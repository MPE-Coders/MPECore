<?php
declare(strict_types=1);
/** Requires real installed NetherGames/RakLib dependencies. No mocks. Does not simulate a Minecraft client. */
require dirname(__DIR__).'/gateway/autoload.php';
use mpe\utils\Config;
use mpe\network\mcpe\{PacketCodec,SpawnSequence,InventoryNetwork};
use mpe\inventory\PlayerInventory;
use pocketmine\network\mcpe\protocol\types\inventory as I;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest as Q;
use mpe\network\mcpe\convert\BlockPalette;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types as T;
use pocketmine\nbt\tag\CompoundTag;
use pmmp\encoding\ByteBufferReader;
use Ramsey\Uuid\Uuid;
try{
    if(!extension_loaded('encoding')||!class_exists(P\StartGamePacket::class)){throw new RuntimeException('Real ext-encoding and Composer dependencies are required. Use ./start.sh --doctor');}
    $root=dirname(__DIR__);$server=new \mpe\Server($root,Config::load($root));$server->loadRegistries();
    // Force PHP to validate interface signatures against the actual RakLib package.
    class_exists(\mpe\network\RakLibInterface::class);class_exists(\mpe\utils\RakLogger::class);
    if(BlockPalette::key('test',CompoundTag::create()->setByte('v',1))===BlockPalette::key('test',CompoundTag::create()->setInt('v',1))){throw new RuntimeException('NBT type information was lost');}
    $count=0;$startupCorpus=[];
    foreach($server->registries as $id=>$registry){
        $packets=SpawnSequence::packets($registry,42,[0.5,64.0,0.5],'Codec test');
        $meta=SpawnSequence::actorData(42);
        $f=$meta->metadata[T\entity\EntityMetadataProperties::FLAGS]->getValue();
        foreach([T\entity\EntityMetadataFlags::AFFECTED_BY_GRAVITY,T\entity\EntityMetadataFlags::HAS_COLLISION] as $flag){
            if(($f & (1<<$flag))===0){throw new RuntimeException('Player gravity/collision flag missing');}
        }
        $expectedStartup=[];
        if($id>=712){$expectedStartup[]=P\JigsawStructureDataPacket::class;}
        if($id>=924){$expectedStartup[]=P\VoxelShapesPacket::class;}
        $expectedStartup[]=P\StartGamePacket::class;
        if(array_map(static fn($p)=>$p::class,array_slice($packets,0,count($expectedStartup)))!==$expectedStartup){
            throw new RuntimeException('Missing or reordered world-start prerequisite packets');
        }
        $startupCorpus[$id]=['protocol'=>$id,'version'=>$registry->profile->version,'packets'=>[]];
        $inventory=new PlayerInventory();
        array_push($packets,...InventoryNetwork::contents($registry,$inventory));
        $changes=$inventory->request(-1,[['type'=>'move','source'=>[28,0,1],'destination'=>[29,12,0],'count'=>1]],true);
        $packets[]=InventoryNetwork::response(-1,$changes);$packets[]=InventoryNetwork::response(-2,null);
        $packets[]=InventoryNetwork::held($registry,$inventory,42);
        $source=new Q\ItemStackRequestSlotInfo(new I\FullContainerName(I\ContainerUIIds::HOTBAR),0,$inventory->get(0)->networkId);
        $destination=new Q\ItemStackRequestSlotInfo(new I\FullContainerName(I\ContainerUIIds::INVENTORY),13,0);
        $request=new Q\ItemStackRequest(-2,[new Q\TakeStackRequestAction(1,$source,$destination)],[],0);
        $packets[]=P\ItemStackRequestPacket::create([$request]);
        $actions=InventoryNetwork::actions($request);
        if(count($actions)!==1||$actions[0]['type']!=='move'){throw new RuntimeException('Installed stack-request API mismatch');}
        array_push($packets,
            P\NetworkSettingsPacket::create(256,0,false,0,0.0),P\PlayStatusPacket::create(0),
            P\ServerToClientHandshakePacket::create('a.b.c'),
            P\ResourcePacksInfoPacket::create([],[],false,false,false,false,[],Uuid::fromString(Uuid::NIL),'',false),
            P\ResourcePackStackPacket::create([],[],false,$registry->profile->version,new T\Experiments([],false),false),
            P\ChunkRadiusUpdatedPacket::create(4),
            P\ContainerOpenPacket::entityInv(1,255,42),P\ContainerClosePacket::create(1,255,false),
            P\NetworkChunkPublisherUpdatePacket::create(new T\BlockPosition(0,64,0),64,[]),
            P\LevelChunkPacket::create(new T\ChunkPosition(0,0),0,8,false,null,"test"),
            P\TextPacket::raw('test'),P\DisconnectPacket::create(0,'test',''),
            \mpe\network\mcpe\MovementSync::correction([0.5,64.0,0.5],123,true),
            \mpe\network\mcpe\MovementSync::teleport(42,[0.5,64.0,0.5],0.0,0.0,124),
            P\PacketViolationWarningPacket::create(0,0,11,'fixture')
        );
        foreach($packets as $packet){
            $class=$packet::class;
            try {
                if ($packet instanceof P\StartGamePacket) {
                    // Non-empty values prove that decoding reads bytes rather than
                    // merely retaining the neutral factory defaults.
                    $packet->serverTelemetryData = new T\ServerTelemetryData(
                        "server-$id", "scenario-$id", "world-$id", "owner-$id"
                    );
                }
                $wire=PacketCodec::encode($packet,$id);
                if(in_array($class,$expectedStartup,true)){
                    $startupCorpus[$id]['packets'][]=['wire'=>base64_encode($wire)];
                }

                $copy=\mpe\network\mcpe\PacketDecodeFactory::create($class);
                $base=$id>1001?1001:$id;
                $back=$id>1001?\mpe\network\mcpe\CodecBridge::convert($wire,$id,1001):$wire;
                $copy->decode(new ByteBufferReader($back),$base);
                if ($packet instanceof P\StartGamePacket) {
                    if ($copy->actorRuntimeId!==42 || $copy->levelSettings->vanillaVersion!==$registry->profile->version || $copy->playerGamemode!==1) {
                        throw new RuntimeException('StartGame roundtrip mismatch');
                    }
                    $t=$copy->serverTelemetryData;
                    $hasTelemetry=$base>=P\ProtocolInfo::PROTOCOL_1_21_0;
                    $hasOwner=$base>=P\ProtocolInfo::PROTOCOL_1_21_90;
                    $expected=$hasTelemetry?["server-$id","scenario-$id","world-$id",$hasOwner?"owner-$id":""]:['','','',''];
                    if ([$t->getServerId(),$t->getScenarioId(),$t->getWorldId(),$t->getOwnerId()]!==$expected) {
                        throw new RuntimeException('StartGame telemetry was not decoded from the wire');
                    }
                    if ($id<=1001 && PacketCodec::encode($copy,$id)!==$wire) {
                        throw new RuntimeException('StartGame byte-for-byte re-encode mismatch');
                    }
                    $rejected=false;
                    try {
                        $short=\mpe\network\mcpe\PacketDecodeFactory::create($class);
                        $short->decode(new ByteBufferReader(substr($back,0,-1)),$base);
                    } catch (Throwable) { $rejected=true; }
                    if (!$rejected) { throw new RuntimeException('Truncated StartGame was accepted'); }
                    echo "PASS StartGame telemetry + wire roundtrip + truncation profile $id\n";
                }
                if($copy instanceof P\JigsawStructureDataPacket){
                    $structureRoot=$copy->getNbt()->getRoot();
                    foreach(['processors','template_pools','jigsaws','structure_sets'] as $key){
                        $list=$structureRoot->getListTag($key);
                        if($list===null||$list->count()!==0||$list->getTagType()!==\pocketmine\nbt\NBT::TAG_Compound){
                            throw new RuntimeException('World-start structure registry is incomplete or not a typed empty list: '.$key);
                        }
                    }
                }
                if($copy instanceof P\VoxelShapesPacket && ($copy->getShapes()!==[]||$copy->getNameMap()!==[]||($id>=944&&$copy->getCustomShapeCount()!==0))){
                    throw new RuntimeException('Unexpected custom shapes in flat-world startup data');
                }
                $count++;
            } catch (Throwable $e) {
                throw new RuntimeException("Profile $id ({$registry->profile->version}), $class: ".$e->getMessage(),0,$e);
            }
        }
        $notice=P\PacketViolationWarningPacket::create(0,2,11,'test');
        $decodedNotice=PacketCodec::decode(PacketCodec::encode($notice,$id),$id);
        if(!$decodedNotice instanceof P\PacketViolationWarningPacket || $decodedNotice->getPacketId()!==11){throw new RuntimeException('Client warning decoder mismatch');}
        $request=P\RequestNetworkSettingsPacket::create($id);$wire=PacketCodec::encode($request,$id);
        if(PacketCodec::decode($wire,$id)->getProtocolVersion()!==$id){throw new RuntimeException('RequestNetworkSettings mismatch');}
        // Validate registered inbound decoders, not only outgoing PHP serialization.
        $eq=InventoryNetwork::held($registry,$inventory,42);
        $decoded=PacketCodec::decode(PacketCodec::encode($eq,$id),$id);
        if(!$decoded instanceof P\MobEquipmentPacket||$decoded->hotbarSlot!==0){throw new RuntimeException('Equipment inbound codec mismatch');}
        echo "PASS installed palette and packet-codec profile $id ({$registry->profile->version})\n";
    }
    $corpus=json_encode(['schema'=>1,'profiles'=>array_values($startupCorpus)],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
    if(file_put_contents($root.'/data/world-start-codec.json',$corpus)!==strlen($corpus)){throw new RuntimeException('Cannot write world-start wire corpus');}
    echo "PASS world-start prerequisite sequence and typed empty registries\n";
    echo "$count outgoing packets roundtripped using installed native codecs / opted-in schema adapters. This is NOT a client join test.\n";
}catch(Throwable $e){fwrite(STDERR,"INTEGRATION FAILED: ".$e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
