<?php
declare(strict_types=1);
namespace Welcome;
use mpe\plugin\PluginBase;
use mpe\event\player\PlayerJoinEvent;
final class Main extends PluginBase {
    public function onEnable():void {
        $this->getLogger()->info('Welcome включён. Правь src/Main.php и введи reload Welcome в консоли.');
    }
    public function onJoin(PlayerJoinEvent $event):void {
        $player=$event->getPlayer();
        $player->sendMessage('§aПривет, '.$player->getName().'! Это MPE-Core на Rust + PHP.');
        $player->sendMessage('§7Плоский мир: трава, ходьба, прыжки. Команды: /pos, /version.');
    }
    public function onDisable():void {$this->getLogger()->info('Welcome выключен.');}
}
