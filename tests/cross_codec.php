<?php
declare(strict_types=1);
/** Real Prismarine-encoded packets -> actual NetherGames decoders, with assertions. */
require dirname(__DIR__).'/gateway/autoload.php';
use mpe\network\mcpe\{PacketCodec,CodecBridge};
use pocketmine\network\mcpe\protocol as P;
$root=dirname(__DIR__);$file=$argv[1]??$root.'/.runtime/client-packets.json';
try{
    if(!is_file($file)){throw new RuntimeException('Run node tools/codec-doctor.cjs --out=.runtime/client-packets.json first');}
    $fixtures=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);$count=0;
    foreach($fixtures as $f){
        if($f['protocol']>1001){CodecBridge::enable($root);}
        $p=PacketCodec::decode(base64_decode($f['data'],true),$f['protocol']);
        $ok=match($f['name']){
            'request_network_settings'=>$p instanceof P\RequestNetworkSettingsPacket && $p->getProtocolVersion()===$f['protocol'],
            'resource_pack_client_response'=>$p instanceof P\ResourcePackClientResponsePacket && $p->status===4,
            'text'=>$p instanceof P\TextPacket && $p->message==='CODEC-PROBE',
            'request_chunk_radius'=>$p instanceof P\RequestChunkRadiusPacket && $p->radius===2,
            'set_local_player_as_initialized'=>$p instanceof P\SetLocalPlayerAsInitializedPacket && $p->actorRuntimeId===42,
            'command_request'=>$p instanceof P\CommandRequestPacket && $p->command==='/pos',
            'inventory_transaction'=>$p instanceof P\InventoryTransactionPacket && $p->trData instanceof P\types\inventory\UseItemTransactionData && $p->trData->getActionType()===P\types\inventory\UseItemTransactionData::ACTION_BREAK_BLOCK && $p->trData->getBlockPosition()->getX()===2,
            'player_auth_input'=>$p instanceof P\PlayerAuthInputPacket && abs($p->getPosition()->x-0.75)<0.0001 && $p->getTick()===1,
            default=>false
        };
        if(!$ok){throw new RuntimeException('Semantic mismatch '.$f['protocol'].'/'.$f['name']);}$count++;
    }
    echo "PASS $count real cross-library inbound packet cases. This is NOT a live login.\n";
}catch(Throwable $e){fwrite(STDERR,"CROSS-CODEC FAILED: ".$e->getMessage()."\n");exit(1);}
