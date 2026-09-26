<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use mpe\utils\Binary;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol as P;
final class PacketCodec {
    private const INBOUND=[
        0xc1=>P\RequestNetworkSettingsPacket::class,1=>P\LoginPacket::class,
        4=>P\ClientToServerHandshakePacket::class,5=>P\DisconnectPacket::class,
        8=>P\ResourcePackClientResponsePacket::class,9=>P\TextPacket::class,
        0x13=>P\MovePlayerPacket::class,0x45=>P\RequestChunkRadiusPacket::class,
        0x71=>P\SetLocalPlayerAsInitializedPacket::class,0x73=>P\NetworkStackLatencyPacket::class,
        0x81=>P\ClientCacheStatusPacket::class,0x90=>P\PlayerAuthInputPacket::class,
        0x4d=>P\CommandRequestPacket::class,
        0x1e=>P\InventoryTransactionPacket::class,0x1f=>P\MobEquipmentPacket::class,
        0x21=>P\InteractPacket::class,0x24=>P\PlayerActionPacket::class,0x93=>P\ItemStackRequestPacket::class,
        0xb8=>P\RequestAbilityPacket::class,0x2f=>P\ContainerClosePacket::class,
        0x9c=>P\PacketViolationWarningPacket::class,
    ];
    public static function id(string $packet):int{
        $o=0;$header=Binary::readUvar($packet,$o);
        if(($header>>10)!==0){throw new \UnexpectedValueException('Subclients/split screen not implemented');}
        return $header&0x3ff;
    }
    public static function decode(string $packet,int $protocol):?P\DataPacket{
        $id=self::id($packet);$class=self::INBOUND[$id]??null;
        if($class===null){return null;}
        $base=$protocol;
        if($protocol>1001){$packet=CodecBridge::convert($packet,$protocol,1001);$base=1001;}
        $p=PacketDecodeFactory::create($class);$p->decode(new ByteBufferReader($packet),$base);return $p;
    }
    public static function encode(P\DataPacket $packet,int $protocol):string{
        $out=new ByteBufferWriter();$packet->encode($out,$protocol>1001?1001:$protocol);
        return $protocol>1001?CodecBridge::convert($out->getData(),1001,$protocol):$out->getData();
    }
}
