<?php
declare(strict_types=1);
namespace MpeExamples;
use mpe\plugin\PluginBase;
use mpe\event\player\PlayerCommandEvent;
final class Commands extends PluginBase {
    public function onCommand(PlayerCommandEvent $event):void {
        $player=$event->getPlayer();
        if($event->getCommand()==='hello'){
            $player->sendMessage('§aПривет, '.$player->getName().'! Это PHP-плагин без компиляции.');
        }else{
            $p=$player->getPosition();
            $player->sendMessage(sprintf('Координаты на момент команды: %.2f %.2f %.2f',$p->x,$p->y,$p->z));
        }
    }
}
