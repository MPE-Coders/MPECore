<?php
declare(strict_types=1);
namespace mpe;
use mpe\utils\{ConsoleLogger,Binary};
use mpe\network\RakLibInterface;
use mpe\network\ipc\{ChildProcess,FrameStream};
use mpe\network\mcpe\NetworkSession;
use mpe\network\mcpe\convert\{ProtocolProfile,Registry};
use mpe\network\mcpe\serializer\ChunkSerializer;
use mpe\plugin\PluginManager;
final class Server {
    public readonly ConsoleLogger $logger;
    public RakLibInterface $network;
    public PluginManager $plugins;
    public array $registries=[],$sessions=[],$bandwidth=[0,0];
    private array $sidIndex=[],$chunkCache=[];
    private int $nextSid=1;
    private bool $running=true,$engineReady=false;
    private ?ChildProcess $engine=null;
    private FrameStream $frames;
    private string $console='';
    private \mpe\utils\PlaytestJournal $journal;
    public function __construct(public readonly string $root,public readonly array $config){
        date_default_timezone_set($config['timezone']);$this->logger=new ConsoleLogger($root.'/logs/server.log',$config['debug']);$this->frames=new FrameStream();$this->journal=new \mpe\utils\PlaytestJournal($root.'/logs/playtest.jsonl');
    }
    public function loadRegistries():void {
        $assetRoot=$this->root.'/vendor/nethergamesmc/bedrock-data';
        $integrity=new \mpe\data\AssetIntegrity($this->root,\mpe\data\AssetIntegrity::installedReferences($this->root));
        $assetFingerprints=[];
        foreach($this->config['protocols'] as $id){
            $profile=new ProtocolProfile($this->root.'/resources/protocols/'.$id.'.json');
            \mpe\data\ProfileGuard::accepted($id,\pocketmine\network\mcpe\protocol\ProtocolInfo::ACCEPTED_PROTOCOL);
            $assetFingerprints[$id]=$integrity->profile($profile->data,$assetRoot);
            $registry=new Registry($profile,$assetRoot);$this->registries[$id]=$registry;
            $this->logger->info("Palette $id ({$profile->version}): {$registry->blocks->count} states, air={$registry->blocks->air} grass={$registry->blocks->grass}, sha256=".substr($registry->blocks->sha256,0,16));
        }
        $integrity->commit();
        @mkdir($this->root.'/data',0700,true);
        $fingerprints=[];foreach($this->registries as $id=>$r){$fingerprints[$id]=['sha256'=>$r->blocks->sha256,'count'=>$r->blocks->count,'air'=>$r->blocks->air,'grass'=>$r->blocks->grass,'grass_name'=>$r->blocks->grassName,'metadata_sha256'=>$r->blocks->metaSha256,'canonical_runtime_map'=>$r->blocks->runtimeMap(),'profile_assets'=>$assetFingerprints[$id]];}
        file_put_contents($this->root.'/data/palettes.loaded.json',json_encode($fingerprints,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    public function run():void {
        $this->logger->banner();$this->loadRegistries();
        putenv('MPE_WORLD_DIR='.$this->config['world-directory']);
        $this->engine=new ChildProcess([$this->root.'/target/release/mpe-core','--stdio'],$this->root);
        $deadline=microtime(true)+5;
        while(!$this->engineReady){$this->pollEngine();if(microtime(true)>$deadline||!$this->engine->running()){throw new \RuntimeException('Rust engine did not become ready');}usleep(1000);}
        $this->network=new RakLibInterface($this);$this->plugins=new PluginManager($this);$this->plugins->loadAll();
        stream_set_blocking(STDIN,false);
        if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGINT,fn()=>$this->stop());pcntl_signal(SIGTERM,fn()=>$this->stop());}
        $this->logger->info('Listening on '.$this->config['host'].':'.$this->config['port'].'/UDP; alpha, not production-hardened');
        if($this->config['host']==='127.0.0.1'){$this->logger->warning('Loopback only. For a LAN test set host=0.0.0.0 in server.json.');}
        if(!$this->config['online-mode']){$this->logger->warning('OFFLINE MODE: names/UUIDs are not trusted online identities.');}
        try{
            while($this->running){
                $this->pollEngine();if(!$this->engine->running()){throw new \RuntimeException('Rust engine exited');}
                $this->readConsole();$this->plugins->tick();$now=microtime(true);
                foreach($this->sessions as $session){try{$session->tick($now);}catch(\Throwable $e){$this->logger->debug($e->getTraceAsString());$session->close($e->getMessage());}}
                $this->network->tick();
            }
        }finally{
            $this->plugins->shutdown();
            foreach($this->sessions as $session){$session->close('Server stopping');}
            $this->network->shutdown();
            $this->engineSend("\x07");$end=microtime(true)+2;
            while($this->engine->running()&&microtime(true)<$end){$this->pollEngine();usleep(1000);}
            $this->engine->close();$this->logger->info('Stopped.');
        }
    }
    public function diagnostic(NetworkSession $session,string $event,string $detail):void {
        if($this->config['playtest-log']){$this->journal->append($session->sid,$session->protocol,$session->state,$event,$detail);}
    }
    public function engineSend(string $payload):void{$this->engine?->send(FrameStream::frame($payload));}
    private function pollEngine():void {
        if($this->engine===null){return;}$this->engine->flush();
        // Drain more than one OS pipe buffer; bound total work so RakLib still gets serviced.
        for($i=0;$i<64;$i++){
            $data=$this->engine->read();if($data===''){break;}
            foreach($this->frames->feed($data) as $frame){$this->engineFrame($frame);}
        }
        $error=$this->engine->read(2);if($error!==''){$this->logger->error('Rust: '.$error);}
    }
    private function engineFrame(string $frame):void {
        $o=1;$op=ord($frame[0]);
        if($op===0x80){$version=Binary::readStr($frame,$o,100);$surface=Binary::readI32($frame,$o);$this->engineReady=true;$this->logger->info("Rust engine $version ready; surface Y=$surface");return;}
        if($op===0x86){$level=ord(Binary::take($frame,$o,1));$this->logger->info(Binary::readStr($frame,$o));return;}
        $sid=Binary::readU64($frame,$o);$s=$this->sidIndex[$sid]??null;
        // A durable edit remains real even if its originating connection just closed.
        if($op===0x8a){
            $nonce=Binary::readStr($frame,$o,64);$x=Binary::readI32($frame,$o);$y=Binary::readI32($frame,$o);$z=Binary::readI32($frame,$o);
            $id=ord(Binary::take($frame,$o,1));$previous=ord(Binary::take($frame,$o,1));
            if($o!==strlen($frame)){throw new \UnexpectedValueException('Edit response trailing bytes');}
            // Serialized chunks are keyed by palette AND complete content hash.
            // Old entries cannot represent changed bytes, so retain the bounded
            // cache for other columns instead of throwing it away on every edit.
            foreach($this->sessions as $other){
                try{$other->blockChanged($x,$y,$z,$id);}catch(\Throwable $e){$other->close('Block encoding failed: '.$e->getMessage());}
            }
            if($s!==null && $s->state!==NetworkSession::CLOSING){
                if(!$s->game->completed($nonce,true,"$x,$y,$z=$id")){
                    $s->message('MPE-EDIT '.json_encode(['nonce'=>$nonce,'x'=>$x,'y'=>$y,'z'=>$z,'id'=>$id,'previous'=>$previous],JSON_THROW_ON_ERROR));
                }
                $this->plugins->event('block_change',['sid'=>$s->sid,'name'=>$s->name,'uuid'=>$s->uuid,
                    'position'=>$s->feet,'x'=>$x,'y'=>$y,'z'=>$z,'block'=>$id,'previous'=>$previous]);
            }
            return;
        }
        if($s===null||$s->state===NetworkSession::CLOSING){return;}
        try{
            switch($op){
                case 0x81:$runtime=Binary::readU64($frame,$o);$s->admitted($runtime,$this->readVector($frame,$o));break;
                case 0x82:$s->close(Binary::readStr($frame,$o,1024));break;
                case 0x83:
                    $x=Binary::readI32($frame,$o);$z=Binary::readI32($frame,$o);$n=ord(Binary::take($frame,$o,1));$sections=[];
                    for($i=0;$i<$n;$i++){$y=unpack('c',Binary::take($frame,$o,1))[1];$sections[$y]=Binary::take($frame,$o,4096);}
                    if($o!==strlen($frame)){throw new \UnexpectedValueException('Chunk IPC trailing bytes');}
                    $r=$s->registry();$key=$r->blocks->sha256.':'.hash('sha256',implode('',$sections));
                    if(!isset($this->chunkCache[$key])){
                        if(count($this->chunkCache)>16){array_shift($this->chunkCache);}
                        $this->chunkCache[$key]=ChunkSerializer::serialize($sections,$r->blocks->runtimeMap());
                    }
                    $s->chunk($x,$z,$n,$this->chunkCache[$key]);break;
                case 0x84:
                    $feet=$this->readVector($frame,$o);$pitch=Binary::readF32($frame,$o);$yaw=Binary::readF32($frame,$o);$tick=Binary::readU64($frame,$o);
                    $ground=ord(Binary::take($frame,$o,1));
                    if($ground>1 || $o!==strlen($frame)){throw new \UnexpectedValueException('Invalid prediction correction IPC');}
                    $s->correct($feet,$pitch,$yaw,$tick,(bool)$ground);break;
                case 0x8d:
                    $feet=$this->readVector($frame,$o);$pitch=Binary::readF32($frame,$o);$yaw=Binary::readF32($frame,$o);$tick=Binary::readU64($frame,$o);
                    if($o!==strlen($frame)){throw new \UnexpectedValueException('Invalid teleport IPC');}
                    $s->teleport($feet,$pitch,$yaw,$tick);break;
                case 0x85:
                    $feet=$this->readVector($frame,$o);$tick=Binary::readU64($frame,$o);$pitch=Binary::readF32($frame,$o);$yaw=Binary::readF32($frame,$o);$s->acceptPosition($feet,$pitch,$yaw);break;
                case 0x89:
                    $nonce=Binary::readStr($frame,$o,64);$feet=$this->readVector($frame,$o);$tick=Binary::readU64($frame,$o);
                    $x=Binary::readI32($frame,$o);$y=Binary::readI32($frame,$o);$z=Binary::readI32($frame,$o);$block=ord(Binary::take($frame,$o,1));
                    $s->message('MPE-PROBE '.json_encode(['nonce'=>$nonce,'position'=>$feet,'tick'=>$tick,'block'=>['x'=>$x,'y'=>$y,'z'=>$z,'id'=>$block,'runtime'=>$s->registry()->blocks->runtime($block)],'palette_sha256'=>$s->registry()->blocks->sha256,'air_runtime'=>$s->registry()->blocks->air,'grass_runtime'=>$s->registry()->blocks->grass],JSON_THROW_ON_ERROR));break;
                case 0x8c:
                    $nonce=Binary::readStr($frame,$o,64);$reason=Binary::readStr($frame,$o,256);$count=ord(Binary::take($frame,$o,1));
                    if($count>2){throw new \UnexpectedValueException('Invalid correction count');}
                    for($i=0;$i<$count;$i++){
                        $x=Binary::readI32($frame,$o);$y=Binary::readI32($frame,$o);$z=Binary::readI32($frame,$o);$id=ord(Binary::take($frame,$o,1));
                        $s->authoritativeBlock($x,$y,$z,$id);
                    }
                    $s->game->completed($nonce,false,$reason);break;
                case 0x8b:$nonce=Binary::readStr($frame,$o,64);$s->message('MPE-ERROR '.$nonce.' '.Binary::readStr($frame,$o,256));break;
                case 0x88:
                    $name=Binary::readStr($frame,$o,64);$uuid=Binary::readStr($frame,$o,64);$auth=ord(Binary::take($frame,$o,1))!==0;$feet=$this->readVector($frame,$o);
                    $this->logger->info("Spawned $name at ".implode(', ',$feet));
                    $s->diagnostic('initialized','Client acknowledged local player; creative inventory enabled');$s->game->sync(true);
                    $s->message('§aMPE Creative Playtest: walk, fly, place and break. /block glass /spawn /help');
                    $this->plugins->event('join',['sid'=>$sid,'name'=>$name,'uuid'=>$uuid,'authenticated'=>$auth]);$this->network->advertise();break;
                default:throw new \UnexpectedValueException('Unknown Rust response');
            }
        }catch(\Throwable $e){$this->logger->debug($e->getTraceAsString());$s->close('Gateway error: '.$e->getMessage());}
    }
    private function readVector(string $s,int &$o):array{return [Binary::readF32($s,$o),Binary::readF32($s,$o),Binary::readF32($s,$o)];}
    public function connected(int $transportId,string $address,int $port):void {
        if(count($this->sessions)>=$this->config['max-players']+4){$this->network->close($transportId);return;}
        $sid=$this->nextSid++;$s=new NetworkSession($this,$transportId,$sid,$address,$port);$this->sessions[$transportId]=$s;$this->sidIndex[$sid]=$s;
        $this->logger->debug("RakNet connection sid=$sid $address:$port");
    }
    public function disconnected(int $transportId,string $reason):void {
        if(!isset($this->sessions[$transportId])){return;}$s=$this->sessions[$transportId];$s->diagnostic('transport_closed',Binary::clean($reason,200));$s->dispose();
        $this->engineSend("\x02".Binary::u64($s->sid));unset($this->sessions[$transportId],$this->sidIndex[$s->sid]);
        $this->plugins->event('quit',['sid'=>$s->sid,'name'=>$s->name,'uuid'=>$s->uuid]);$this->network->advertise();
    }
    public function received(int $id,string $packet):void {
        $s=$this->sessions[$id]??null;if($s===null){return;}
        try{$s->receive($packet);}catch(\Throwable $e){$this->logger->debug($e->getTraceAsString());$s->close($e->getMessage());}
    }
    public function onlineCount():int{return count(array_filter($this->sessions,static fn($s)=>$s->state===NetworkSession::PLAY));}
    public function broadcast(string $message):void{$this->logger->info($message);foreach($this->sessions as $s){$s->message($message);}}
    public function messagePlayer(int $sid,string $message):void{($this->sidIndex[$sid]??null)?->message($message);}
    public function stop():void{$this->running=false;}
    private function readConsole():void {
        $s=fread(STDIN,8192);if($s!==false){$this->console.=$s;}
        if(strlen($this->console)>16384){$this->console='';$this->logger->warning('Console line limit');}
        while(($p=strpos($this->console,"\n"))!==false){$line=trim(substr($this->console,0,$p));$this->console=substr($this->console,$p+1);$this->command($line);}
    }
    private function command(string $line):void {
        [$command,$arg]=array_pad(explode(' ',$line,2),2,'');
        switch(strtolower($command)){
            case '':break;
            case 'stop':case 'exit':$this->stop();break;
            case 'help':$this->logger->info('help | stop | list | status | protocols | palettes | plugins | reload <Plugin> | say <text>');break;
            case 'list':case 'status':$this->engineSend("\x06".Binary::str($command));$this->logger->info('RakLib bytes/s tx='.$this->bandwidth[0].' rx='.$this->bandwidth[1]);break;
            case 'protocols':foreach($this->registries as $r){$this->logger->info($r->profile->id.' / '.$r->profile->version.' / experimental');}break;
            case 'palettes':foreach($this->registries as $r){$this->logger->info($r->profile->id.' air='.$r->blocks->air.' grass='.$r->blocks->grass.' sha256='.$r->blocks->sha256);}break;
            case 'plugins':$this->logger->info('PHP plugins: '.implode(', ',$this->plugins->names()));break;
            case 'reload':$this->plugins->reload(trim($arg));break;
            case 'say':$this->broadcast('[Server] '.Binary::clean($arg));break;
            default:$this->logger->warning('Unknown command. Type help.');
        }
    }
}
