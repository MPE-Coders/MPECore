<?php
declare(strict_types=1);

namespace mpe\network\mcpe;

use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\ServerTelemetryData;

/** Creates fresh decode targets without modifying Composer's vendor directory. */
final class PacketDecodeFactory {
    /**
     * @param class-string<DataPacket> $class An internal, trusted packet class.
     * This does not decide which packets clients may send; PacketCodec does that.
     */
    public static function create(string $class): DataPacket {
        if (!is_a($class, DataPacket::class, true)) {
            throw new \InvalidArgumentException('Decode target must extend DataPacket');
        }
        $packet = new $class();
        if ($packet instanceof StartGamePacket) {
            // NetherGames aae6ba55: LevelSettings::read receives this non-nullable
            // property by reference before StartGamePacket has assigned it.
            // A fresh neutral value is needed even on protocols where telemetry
            // is read later. The decoder still consumes the real wire fields.
            // Never clone the outgoing packet: that could conceal decode errors.
            $packet->serverTelemetryData = new ServerTelemetryData('', '', '', '');
        }
        return $packet;
    }
}
