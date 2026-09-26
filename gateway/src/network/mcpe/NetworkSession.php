<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use mpe\Server;
use mpe\utils\Binary;
use mpe\network\ipc\ChildProcess;
use mpe\network\mcpe\auth\SessionCipher;
use mpe\network\mcpe\convert\Registry;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types as T;
use pocketmine\math\Vector3;
use Ramsey\Uuid\Uuid;

final class NetworkSession {
    public const NEW='new',SETTINGS='settings',AUTH='auth',HANDSHAKE='handshake',PACK_INFO='pack_info',PACK_STACK='pack_stack',JOINING='joining',SPAWNING='spawning',PLAY='play',CLOSING='closing';
    public string $state=self::NEW;
    public int $protocol=1001,$runtimeId=0,$ping=0;
    public string $name='',$uuid='';
    public bool $authenticated=false;
    public CreativeGameHandler $game;
    public float $pitch=0.0,$yaw=0.0;
    public array $feet=[0.5,64.0,0.5];
    private float $phaseAt,$lastPacketAt,$lastTick=0.0,$rateWindow=0.0,$lastChat=0.0,$lastRadiusChange=0.0;
    private int $packetCount=0,$moveCount=0;
    private float $lastCommand=0.0;
    private bool $compressed=false,$spawnSent=false;
    private bool $movementRecorded=false;
    private int $violationCount=0;
    private ?SessionCipher $cipher=null;
    private ?ChildProcess $authWorker=null;
    private string $authBuffer='';
    private array $pendingPackets=[];
    private int $pendingBytes=0;
    private int $radius;
    private ?array $center=null;
    private array $wanted=[],$queued=[],$loaded=[];
    public function __construct(private Server $server,public readonly int $transportId,public readonly int $sid,public readonly string $address,public readonly int $port){
        $this->phaseAt=$this->lastPacketAt=microtime(true);$this->radius=$server->config['view-distance'];$this->game=new CreativeGameHandler($this);
    }
    public function registry():Registry{return $this->server->registries[$this->protocol]??throw new \RuntimeException('Unsupported protocol');}
    private function phase(string $state):void{$this->state=$state;$this->phaseAt=microtime(true);$this->diagnostic('phase',$state);$this->server->logger->debug("Session {$this->sid}: $state");}
    private function expect(string $state):void{if($this->state!==$state){throw new \UnexpectedValueException("Unexpected packet during {$this->state}, expected $state");}}
    public function receive(string $bytes):void {
        if($this->state===self::CLOSING){return;}
        if($bytes===''||$bytes[0]!=="\xfe"){throw new \UnexpectedValueException('Expected Bedrock batch');}
        $this->lastPacketAt=microtime(true);
        if($this->lastPacketAt-$this->rateWindow>=1){$this->rateWindow=$this->lastPacketAt;$this->packetCount=0;$this->moveCount=0;}
        $payload=substr($bytes,1);if($this->cipher!==null){$payload=$this->cipher->decrypt($payload);}
        foreach(BatchCodec::decode($payload,$this->compressed) as $raw){
            if(++$this->packetCount>1000){throw new \OverflowException('Packet rate exceeded');}
            $id=PacketCodec::id($raw);
            // Keep login/JWT payloads out of diagnostics: record IDs and sizes only.
            if($this->server->config['packet-dump']){$this->server->logger->debug(sprintf('RX sid=%d proto=%d id=0x%x len=%d phase=%s',$this->sid,$this->protocol,$id,strlen($raw),$this->state));}
            $packet=PacketCodec::decode($raw,$this->protocol);
            if($packet!==null){$this->handle($packet);}
        }
    }
    private function handle(P\DataPacket $p):void {
        if($p instanceof P\PacketViolationWarningPacket){
            $detail=ClientPacketNotice::summary($p->getType(),$p->getSeverity(),$p->getPacketId(),$p->getMessage());
            $this->diagnostic('client_packet_violation',$detail);
            $this->server->logger->warning('Minecraft rejected server data: '.$detail);
            if($p->getSeverity()>=P\PacketViolationWarningPacket::SEVERITY_TERMINATING_CONNECTION){$this->close('Client reported a terminating protocol violation');}
            return;
        }
        if($p instanceof P\RequestNetworkSettingsPacket){
            $this->expect(self::NEW);$version=$p->getProtocolVersion();
            if(!isset($this->server->registries[$version])){
                $this->send(P\PlayStatusPacket::create($version<min(array_keys($this->server->registries))?1:2),true);
                $this->close('Unsupported client protocol '.$version,false);return;
            }
            $this->protocol=$version;$this->diagnostic('protocol_selected',$this->registry()->profile->version);
            $this->send(P\NetworkSettingsPacket::create(256,0,false,0,0.0),true);
            $this->compressed=true;$this->phase(self::SETTINGS);return;
        }
        if($p instanceof P\LoginPacket){
            $this->expect(self::SETTINGS);
            if($p->protocol!==$this->protocol){throw new \UnexpectedValueException('Protocol changed after negotiation');}
            $this->authWorker=new ChildProcess(ChildProcess::phpCommand($this->server->root.'/gateway/auth-worker.php'),$this->server->root);
            $this->authWorker->send(json_encode(['protocol'=>$this->protocol,'auth_json'=>$p->authInfoJson,'client_jwt'=>$p->clientDataJwt,'online'=>$this->server->config['online-mode'],'key_cache'=>$this->server->root.'/data/auth-keys.json'],JSON_THROW_ON_ERROR)."\n");
            $this->phase(self::AUTH);return;
        }
        if($p instanceof P\ClientToServerHandshakePacket){$this->expect(self::HANDSHAKE);$this->beginPacks();return;}
        if($p instanceof P\ResourcePackClientResponsePacket){
            if(!in_array($this->state,[self::PACK_INFO,self::PACK_STACK],true)){throw new \UnexpectedValueException('Unexpected resource pack reply');}
            if($p->status===3&&$this->state===self::PACK_INFO){
                $this->send(P\ResourcePackStackPacket::create([],[],false,$this->registry()->profile->version,new T\Experiments([],false),false));$this->phase(self::PACK_STACK);
            }elseif($p->status===4&&$this->state===self::PACK_STACK){
                $occupied=count(array_filter($this->server->sessions,static fn($s)=>in_array($s->state,[self::JOINING,self::SPAWNING,self::PLAY],true)));
                if($occupied >= $this->server->config['max-players']){$this->close('Server is full');return;}
                $this->server->engineSend("\x01".Binary::u64($this->sid).Binary::str($this->name).Binary::str($this->uuid).chr((int)$this->authenticated));$this->phase(self::JOINING);
            }else{throw new \UnexpectedValueException('Resource pack negotiation refused or invalid');}return;
        }
        if($p instanceof P\RequestChunkRadiusPacket){
            if(!in_array($this->state,[self::SPAWNING,self::PLAY],true)){return;}
            if(microtime(true)-$this->lastRadiusChange<0.5){return;}$this->lastRadiusChange=microtime(true);
            $this->radius=max(2,min($p->radius,$this->server->config['max-view-distance']));
            $this->send(P\ChunkRadiusUpdatedPacket::create($this->radius));$this->updateChunks(true);return;
        }
        if($p instanceof P\SetLocalPlayerAsInitializedPacket){
            if($this->state===self::PLAY){return;}
            $this->expect(self::SPAWNING);
            if(!$this->spawnSent||$p->actorRuntimeId!==$this->runtimeId){throw new \UnexpectedValueException('Premature initialization');}
            $this->phase(self::PLAY);$this->server->engineSend("\x08".Binary::u64($this->sid));return;
        }
        if($p instanceof P\PlayerAuthInputPacket || $p instanceof P\MovePlayerPacket){
            if($this->state!==self::PLAY){return;}
            if(++$this->moveCount>120){throw new \OverflowException('Movement rate exceeded');}
            if($p instanceof P\PlayerAuthInputPacket){$pos=$p->getPosition();$pitch=$p->getPitch();$yaw=$p->getYaw();$tick=$p->getTick();}
            else{return;}
            if(!is_finite($pos->x)||!is_finite($pos->y)||!is_finite($pos->z)||!is_finite($pitch)||!is_finite($yaw)||$tick<0){throw new \UnexpectedValueException('Non-finite movement');}
            $feet=[$pos->x,$pos->y-1.62,$pos->z];
            $this->server->engineSend("\x03".Binary::u64($this->sid).pack('g*',...$feet).pack('g2',$pitch,$yaw).Binary::u64($tick));
            $this->game->input($p);
            return;
        }
        if($this->state===self::PLAY && $this->game->handle($p)){return;}
        if($p instanceof P\TextPacket){
            if($this->state!==self::PLAY||microtime(true)-$this->lastChat<0.4){return;}$this->lastChat=microtime(true);
            $text=Binary::clean($p->message,256);if($text===''){return;}
            if(str_starts_with($text,'/')){$this->playerCommand($text);}else{
                $this->server->broadcast('<'.$this->name.'> '.$text);
                $this->server->plugins->event('chat',['sid'=>$this->sid,'name'=>$this->name,'uuid'=>$this->uuid,'message'=>$text]);
            }return;
        }
        if($p instanceof P\CommandRequestPacket){if($this->state===self::PLAY){$this->playerCommand($p->command);}return;}
        if($p instanceof P\NetworkStackLatencyPacket){
            if($p->needResponse){$this->send(P\NetworkStackLatencyPacket::create($p->timestamp,false));}return;
        }
        if($p instanceof P\DisconnectPacket){$this->close('Client disconnected',false);return;}
    }
    public function send(P\DataPacket $packet,bool $immediate=false):void{
        $bytes=PacketCodec::encode($packet,$this->protocol);
        if($this->server->config['packet-dump']){$this->server->logger->debug(sprintf('TX sid=%d proto=%d id=0x%x len=%d',$this->sid,$this->protocol,$packet->pid(),strlen($bytes)));}
        if($this->pendingBytes+strlen($bytes)>BatchCodec::MAX_BATCH){$this->flush();}
        $this->pendingPackets[]=$bytes;$this->pendingBytes+=strlen($bytes);
        if($immediate){$this->flush();}
    }
    public function flush():void {
        if($this->pendingPackets===[]){return;}
        $batch=BatchCodec::encode($this->pendingPackets,$this->compressed);$this->pendingPackets=[];$this->pendingBytes=0;
        if($this->cipher!==null){$batch=$this->cipher->encrypt($batch);}
        $this->server->network->send($this->transportId,"\xfe".$batch);
    }
    public function tick(float $now):void {
        if($this->state===self::CLOSING){return;}
        if($this->state!==self::PLAY&&$now-$this->phaseAt>(in_array($this->state,[self::JOINING,self::SPAWNING],true)?$this->server->config['spawn-timeout']:$this->server->config['auth-timeout'])){$this->close('Login timeout at '.$this->state);return;}
        if($now-$this->lastPacketAt>60){$this->close('Connection timeout');return;}
        if($this->authWorker!==null){
            $this->authWorker->flush();$this->authBuffer.=$this->authWorker->read();
            if(strlen($this->authBuffer)>65536){throw new \OverflowException('Auth worker output limit');}
            $err=$this->authWorker->read(2);if($err!==''){$this->server->logger->debug('Auth worker: '.Binary::clean($err));}
            if(str_contains($this->authBuffer,"\n")){
                $result=json_decode(strtok($this->authBuffer,"\n"),true,32,JSON_THROW_ON_ERROR);$this->authWorker->close();$this->authWorker=null;$this->authBuffer='';
                if(!($result['ok']??false)){$this->close('Authentication failed: '.($result['error']??'unknown'));return;}
                $reserved=count(array_filter($this->server->sessions,fn($other)=>$other!==$this&&$other->name!==''&&$other->state!==self::CLOSING));
                if($reserved >= $this->server->config['max-players']){$this->close('Server is full');return;}
                $this->name=$result['name'];$this->uuid=$result['uuid'];$this->authenticated=$result['authenticated'];
                $this->server->logger->info("Login {$this->name}: protocol {$this->protocol}, ".($this->authenticated?'signed online identity':'OFFLINE identity'));
                if($this->server->config['encryption']){
                    $this->send(P\ServerToClientHandshakePacket::create($result['handshake']),true);
                    $key=base64_decode($result['key'],true);if($key===false){throw new \RuntimeException('Bad worker key');}
                    $this->cipher=new SessionCipher($key);$this->phase(self::HANDSHAKE);
                }else{$this->beginPacks();}
            }elseif(!$this->authWorker->running()){$this->close('Authentication worker stopped');return;}
        }
        if($now-$this->lastTick>=0.05){
            $this->lastTick=$now;
            if(in_array($this->state,[self::SPAWNING,self::PLAY],true)){
                $count=0;
                foreach($this->wanted as $key=>[$x,$z]){
                    if(isset($this->loaded[$key])||isset($this->queued[$key])){continue;}
                    if(count($this->queued)>=16){break;}
                    $this->server->engineSend("\x04".Binary::u64($this->sid).Binary::i32($x).Binary::i32($z));$this->queued[$key]=true;
                    if(++$count>=$this->server->config['chunks-per-tick']){break;}
                }
            }
        }
        $this->flush();
    }
    private function beginPacks():void {
        $this->send(P\PlayStatusPacket::create(0));
        $this->send(P\ResourcePacksInfoPacket::create([],[],false,false,false,false,[],Uuid::fromString(Uuid::NIL),'',false));
        $this->phase(self::PACK_INFO);
    }
    public function admitted(int $runtimeId,array $feet):void {
        $this->expect(self::JOINING);$this->runtimeId=$runtimeId;$this->feet=$feet;
        foreach(SpawnSequence::packets($this->registry(),$runtimeId,$feet,$this->server->config['server-name'],$this->canBuild()) as $packet){$this->send($packet);}
        $this->game->sync(true);$this->phase(self::SPAWNING);$this->send(P\ChunkRadiusUpdatedPacket::create($this->radius));$this->updateChunks(true);$this->flush();
    }
    private function updateChunks(bool $force=false):void {
        $cx=(int)floor($this->feet[0]/16);$cz=(int)floor($this->feet[2]/16);
        if(!$force&&$this->center===[$cx,$cz]){return;}$this->center=[$cx,$cz];
        $this->send(P\NetworkChunkPublisherUpdatePacket::create(new T\BlockPosition((int)floor($this->feet[0]),(int)floor($this->feet[1]),(int)floor($this->feet[2])),$this->radius*16,[]));
        $positions=[];
        for($x=$cx-$this->radius;$x<=$cx+$this->radius;$x++){
            for($z=$cz-$this->radius;$z<=$cz+$this->radius;$z++){
                if(($x-$cx)**2+($z-$cz)**2<=$this->radius**2){$positions[]=[($x-$cx)**2+($z-$cz)**2,$x,$z];}
            }
        }
        usort($positions,static fn($a,$b)=>$a[0]<=>$b[0]);$this->wanted=[];
        foreach($positions as [,$x,$z]){$this->wanted["$x:$z"]=[$x,$z];}
        $this->loaded=array_intersect_key($this->loaded,$this->wanted);
    }
    public function chunk(int $x,int $z,int $count,string $payload):void{
        $key="$x:$z";unset($this->queued[$key]);
        if(!isset($this->wanted[$key])||!in_array($this->state,[self::SPAWNING,self::PLAY],true)){return;}
        $this->send(P\LevelChunkPacket::create(new T\ChunkPosition($x,$z),T\DimensionIds::OVERWORLD,$count,false,null,$payload));$this->loaded[$key]=true;
        if(!$this->spawnSent){
            [$cx,$cz]=$this->center;$ready=true;
            for($dx=-1;$dx<=1;$dx++){for($dz=-1;$dz<=1;$dz++){if(!isset($this->loaded[($cx+$dx).':'.($cz+$dz)])){$ready=false;}}}
            if($ready){$this->send(P\PlayStatusPacket::create(3));$this->spawnSent=true;$this->diagnostic('spawn_sent','3x3 central chunks queued before PLAYER_SPAWN');}
        }
    }
    public function correct(array $feet,float $pitch,float $yaw,int $tick,bool $onGround):void {
        if($this->state!==self::PLAY){return;}$this->feet=$feet;$this->pitch=$pitch;$this->yaw=$yaw;
        $this->send(MovementSync::correction($feet,$tick,$onGround));$this->updateChunks();
        $this->diagnostic('movement_corrected','client_tick='.$tick.' on_ground='.(int)$onGround);
    }
    public function teleport(array $feet,float $pitch,float $yaw,int $tick):void {
        if($this->state!==self::PLAY){return;}$this->feet=$feet;$this->pitch=$pitch;$this->yaw=$yaw;
        $this->send(MovementSync::teleport($this->runtimeId,$feet,$pitch,$yaw,$tick));$this->updateChunks(true);
        $this->diagnostic('teleport','client_tick='.$tick);
    }
    public function message(string $text):void{if($this->state===self::PLAY){$this->send(P\TextPacket::raw(Binary::clean($text,1024)));}}
    public function acceptPosition(array $feet,float $pitch=0.0,float $yaw=0.0):void {
        if($this->state!==self::PLAY){return;}$old=$this->feet;$this->feet=$feet;$this->pitch=$pitch;$this->yaw=$yaw;$this->updateChunks();
        if(!$this->movementRecorded && ($old[0]-$feet[0])**2+($old[1]-$feet[1])**2+($old[2]-$feet[2])**2>0.0001){
            $this->movementRecorded=true;$this->diagnostic('movement_accepted',sprintf('%.3f %.3f %.3f',...$feet));
        }
    }
    public function blockChanged(int $x,int $y,int $z,int $canonical):void {
        if(!in_array($this->state,[self::SPAWNING,self::PLAY],true)){return;}
        $key=((int)floor($x/16)).':'.((int)floor($z/16));
        if(!isset($this->loaded[$key])){return;}
        $this->send(P\UpdateBlockPacket::create(new T\BlockPosition($x,$y,$z),$this->registry()->blocks->runtime($canonical),P\UpdateBlockPacket::FLAG_NETWORK,0));
        unset($this->loaded[$key]);
    }
    public function canBuild():bool {return $this->server->config['allow-building'];}
    public function engine(string $bytes):void {$this->server->engineSend($bytes);}
    public function resendAround(int $x,int $z):void {
        if(abs($x)>100000||abs($z)>100000){return;}
        for($dx=-1;$dx<=1;$dx++){for($dz=-1;$dz<=1;$dz++){
            $key=(((int)floor($x/16))+$dx).':'.(((int)floor($z/16))+$dz);unset($this->loaded[$key]);
        }}
    }
    public function authoritativeBlock(int $x,int $y,int $z,int $id):void {
        if($y< -64||$y>319||abs($x)>100000||abs($z)>100000){return;}
        $this->send(P\UpdateBlockPacket::create(new T\BlockPosition($x,$y,$z),$this->registry()->blocks->runtime($id),P\UpdateBlockPacket::FLAG_NETWORK,0));
    }
    public function diagnostic(string $kind,string $detail):void {
        $this->server->diagnostic($this,$kind,$detail);
    }
    public function canEdit():bool {
        return $this->server->config['test-mode'] || ($this->authenticated && in_array(strtolower($this->uuid),array_map('strtolower',$this->server->config['operators']),true));
    }
    public function requestSetBlock(int $x,int $y,int $z,int $id,string $nonce):void {
        $this->server->engineSend("\x0a".Binary::u64($this->sid).Binary::str($nonce).Binary::i32($x).Binary::i32($y).Binary::i32($z).chr($id));
    }
    private function playerCommand(string $text):void {
        if(microtime(true)-$this->lastCommand<0.1){return;}$this->lastCommand=microtime(true);
        $parts=preg_split('/\s+/',trim($text));$command=strtolower(array_shift($parts));
        if($command==='/mpe'&&($parts[0]??'')==='probe'){
            if(count($parts)!==5||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$parts[1])){$this->message('Usage: /mpe probe nonce x y z');return;}
            foreach(array_slice($parts,2) as $v){if(!preg_match('/^-?[0-9]{1,6}$/',$v)){$this->message('Invalid coordinate');return;}}
            [, $nonce,$x,$y,$z]=$parts;
            if(abs((int)$x)>100000||abs((int)$z)>100000||(int)$y < -64||(int)$y>319){$this->message('Out of bounds');return;}
            $this->server->engineSend("\x09".Binary::u64($this->sid).Binary::str($nonce).Binary::i32((int)$x).Binary::i32((int)$y).Binary::i32((int)$z));return;
        }
        if($command==='/spawn'){
            $this->engine("\x0c".Binary::u64($this->sid));return;
        }
        if($command==='/block'){
            if(!$this->canBuild()){$this->message('Building is disabled');return;}
            $defs=json_decode(file_get_contents($this->server->root.'/resources/blocks.json'),true,64,JSON_THROW_ON_ERROR);
            $ids=array_column($defs,'id','name');$name=str_replace('minecraft:','',$parts[0]??'');
            if(!isset($ids[$name])||$ids[$name]===0){$this->message('Usage: /block <name>; /blocks lists supported names');return;}
            $this->game->inventory->set($this->game->inventory->selected(),$ids[$name]);$this->game->sync(true);return;
        }
        if($command==='/setblock'){
            if(!$this->canEdit()){$this->message('Permission denied: authenticated operator UUID or loopback test-mode required');return;}
            if(count($parts)<4||count($parts)>5){$this->message('Usage: /setblock x y z block_name [nonce]');return;}
            foreach(array_slice($parts,0,3) as $v){if(!preg_match('/^-?[0-9]{1,6}$/',$v)){$this->message('Invalid coordinate');return;}}
            $defs=json_decode(file_get_contents($this->server->root.'/resources/blocks.json'),true,64,JSON_THROW_ON_ERROR);$ids=array_column($defs,'id','name');
            $name=str_replace('minecraft:','',$parts[3]);$id=$ids[$name]??null;$nonce=$parts[4]??bin2hex(random_bytes(8));
            if($id===null||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$nonce)){$this->message('Unknown block or invalid nonce');return;}
            $this->requestSetBlock((int)$parts[0],(int)$parts[1],(int)$parts[2],$id,$nonce);return;
        }
        if($this->server->plugins->command($this,ltrim($command,'/'),$parts)){return;}
        $this->message(match($command){'/help'=>'MPE-Core: /help /version /pos /blocks /block <name> /spawn /setblock /mpe probe','/version'=>'MPE-Core 0.4.0 | Rust + RakLib | protocol '.$this->protocol,'/pos'=>sprintf('Authoritative position: %.2f %.2f %.2f',...$this->feet),'/blocks'=>'air grass stone cobblestone dirt glass oak_planks diamond_block gold_block iron_block emerald_block obsidian',default=>'Unknown command. /help'});
    }
    public function close(string $reason,bool $notify=true):void {
        if($this->state===self::CLOSING){return;}
        $this->diagnostic('disconnect',Binary::clean($reason,200));$this->server->logger->info("Disconnect sid={$this->sid} {$this->name}: ".$reason);
        if($notify){try{$this->send(P\DisconnectPacket::create(0,Binary::clean($reason,512),''),true);}catch(\Throwable){}}
        $this->phase(self::CLOSING);$this->dispose();$this->server->network->close($this->transportId);
    }
    public function dispose():void{$this->authWorker?->close();$this->authWorker=null;}
}
