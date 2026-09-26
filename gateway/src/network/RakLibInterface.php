<?php
declare(strict_types=1);
namespace mpe\network;
use mpe\Server;
use mpe\utils\RakLogger;
use raklib\server\Server as RakServer;
use raklib\server\{ServerEventSource,ServerEventListener,ServerInterface,ServerSocket,SimpleProtocolAcceptor};
use raklib\utils\{InternetAddress,ExceptionTraceCleaner};
use raklib\protocol\{EncapsulatedPacket,PacketReliability};
/** Adapter for the Minecraft server's configured UDP socket; no external host is contacted. */
final class RakLibInterface implements ServerEventListener {
    private RakServer $rak;
    public readonly int $serverId;
    public function __construct(private Server $server){
        $this->serverId=random_int(1,PHP_INT_MAX);
        $source=new class implements ServerEventSource {
            public function process(ServerInterface $server):bool{return false;}
        };
        $this->rak=new RakServer($this->serverId,new RakLogger($server->logger),
            new ServerSocket(new InternetAddress($server->config['host'],$server->config['port'],4)),
            1492,new SimpleProtocolAcceptor(11),$source,$this,new ExceptionTraceCleaner($server->root));
        $this->rak->setPacketsPerTickLimit(500);$this->advertise();
    }
    public function advertise():void {
        $p=$this->server->registries[$this->server->config['advertise-protocol']]->profile;
        $name=str_replace(';',',',$this->server->config['server-name']);
        $this->rak->setName("MCPE;$name;{$p->id};{$p->version};".$this->server->onlineCount().';'.$this->server->config['max-players'].";{$this->serverId};Creative Playtest;Creative;1;".$this->server->config['port'].';19133;');
    }
    public function tick():void{$this->rak->tickProcessor();}
    public function send(int $transportId,string $bytes):void{
        $pk=new EncapsulatedPacket();$pk->buffer=$bytes;$pk->reliability=PacketReliability::RELIABLE_ORDERED;$pk->orderChannel=0;
        $this->rak->sendEncapsulated($transportId,$pk,true);
    }
    public function close(int $id):void{$this->rak->closeSession($id);}
    public function shutdown():void{$this->rak->waitShutdown();}
    public function onClientConnect(int $sessionId,string $address,int $port,int $clientID):void{$this->server->connected($sessionId,$address,$port);}
    public function onClientDisconnect(int $sessionId,int $reason):void{$this->server->disconnected($sessionId,"RakNet reason $reason");}
    public function onPacketReceive(int $sessionId,string $packet):void{$this->server->received($sessionId,$packet);}
    public function onRawPacketReceive(string $address,int $port,string $payload):void{}
    public function onPacketAck(int $sessionId,int $identifierACK):void{}
    public function onBandwidthStatsUpdate(int $bytesSentDiff,int $bytesReceivedDiff):void{$this->server->bandwidth=[$bytesSentDiff,$bytesReceivedDiff];}
    public function onPingMeasure(int $sessionId,int $pingMS):void{if(isset($this->server->sessions[$sessionId])){$this->server->sessions[$sessionId]->ping=$pingMS;}}
}
