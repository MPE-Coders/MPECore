<?php
declare(strict_types=1);
/**
 * Isolated PHP-language regression test with explicit protocol test doubles.
 * Does NOT load vendor, serialize Bedrock, use RakNet, or verify a client login.
 * The actual installed-codec roundtrip is in tests/integration.php.
 */
namespace pocketmine\network\mcpe\protocol\types {
    final class ServerTelemetryData {
        public function __construct(
            private string $serverId, private string $scenarioId,
            private string $worldId, private string $ownerId
        ) {}
        public function getServerId(): string { return $this->serverId; }
        public function getScenarioId(): string { return $this->scenarioId; }
        public function getWorldId(): string { return $this->worldId; }
        public function getOwnerId(): string { return $this->ownerId; }
    }
}
namespace pocketmine\network\mcpe\protocol {
    use pocketmine\network\mcpe\protocol\types\ServerTelemetryData;
    abstract class DataPacket {}
    final class StartGamePacket extends DataPacket {
        public ServerTelemetryData $serverTelemetryData;
        public function decodeProbe(string $serverId): void {
            self::readProbe($this->serverTelemetryData,$serverId);
        }
        private static function readProbe(ServerTelemetryData &$out,string $serverId): void {
            $out=new ServerTelemetryData($serverId,'scenario','world','owner');
        }
    }
    final class FixturePacket extends DataPacket {}
}
namespace {
    use mpe\network\mcpe\PacketDecodeFactory;
    use pocketmine\network\mcpe\protocol\{StartGamePacket,FixturePacket};
    require dirname(__DIR__).'/gateway/src/network/mcpe/PacketDecodeFactory.php';
    require dirname(__DIR__).'/gateway/src/utils/Binary.php';
    require dirname(__DIR__).'/gateway/src/network/mcpe/PacketCodec.php';
    function ensure(bool $condition): void {
        if (!$condition) { throw new RuntimeException('Assertion failed'); }
    }
    $tests=[
        'Reproduces PHP uninitialized non-nullable by-reference failure'=>static function(): void {
            $failed=false;
            try { (new StartGamePacket())->decodeProbe('wire'); }
            catch (Error $e) { $failed=str_contains($e->getMessage(),'uninitialized non-nullable property'); }
            ensure($failed);
        },
        'Factory initializes all four neutral telemetry fields'=>static function(): void {
            $p=PacketDecodeFactory::create(StartGamePacket::class);$t=$p->serverTelemetryData;
            ensure([$t->getServerId(),$t->getScenarioId(),$t->getWorldId(),$t->getOwnerId()]===['','','','']);
        },
        'Reference assignment can replace defaults with decoded values'=>static function(): void {
            $p=PacketDecodeFactory::create(StartGamePacket::class);$p->decodeProbe('from-wire');
            ensure($p->serverTelemetryData->getServerId()==='from-wire');
        },
        'Decode targets do not share telemetry or outgoing packet state'=>static function(): void {
            $a=PacketDecodeFactory::create(StartGamePacket::class);$b=PacketDecodeFactory::create(StartGamePacket::class);
            ensure($a!==$b && $a->serverTelemetryData!==$b->serverTelemetryData);
            $a->decodeProbe('changed');ensure($b->serverTelemetryData->getServerId()==='');
        },
        'Other packet classes are not given dynamic telemetry properties'=>static function(): void {
            $p=PacketDecodeFactory::create(FixturePacket::class);ensure(get_object_vars($p)===[]);
        },
        'Non-packet decode targets are rejected'=>static function(): void {
            $failed=false;
            try { PacketDecodeFactory::create(stdClass::class); }
            catch (InvalidArgumentException) { $failed=true; }
            ensure($failed);
        },
        'StartGame remains excluded from the inbound client allow-list'=>static function(): void {
            ensure(\mpe\network\mcpe\PacketCodec::decode("\x0b",975)===null);
        },
    ];
    $failed=0;
    foreach($tests as $name=>$test) {
        try { $test();echo "PASS $name\n"; }
        catch (Throwable $e) { $failed++;fwrite(STDERR,"FAIL $name: {$e->getMessage()}\n"); }
    }
    echo (count($tests)-$failed)." packet-factory regression tests passed; $failed failed. Protocol doubles, NOT network integration.\n";
    exit($failed===0?0:1);
}
