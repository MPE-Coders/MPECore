<?php
declare(strict_types=1);
namespace mpe\plugin;
use mpe\event\player\{PlayerJoinEvent,PlayerQuitEvent,PlayerChatEvent};
/** Small, real API; it is PMMP-shaped, NOT binary/source compatible with PMMP. */
abstract class PluginBase {
    private \Closure $send;
    final public function __construct(callable $sender,private readonly string $dataFolder){$this->send=\Closure::fromCallable($sender);}
    public function onEnable():void{}
    public function onDisable():void{}
    public function onJoin(PlayerJoinEvent $event):void{}
    public function onQuit(PlayerQuitEvent $event):void{}
    public function onCommand(\mpe\event\player\PlayerCommandEvent $event):void{}
    public function onBlockChange(\mpe\event\block\BlockChangeEvent $event):void{}
    public function onChat(PlayerChatEvent $event):void{}
    final public function getDataFolder():string{return $this->dataFolder;}
    final public function getLogger():PluginLogger{return new PluginLogger($this->send);}
}
