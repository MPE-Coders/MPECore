<?php
declare(strict_types=1);
namespace mpe\event\player;
use mpe\player\Player;
/** Notification only in 0.1; cancellation and chat formatting hooks are not yet implemented. */
final class PlayerChatEvent {
    public function __construct(private readonly Player $player,private readonly string $message){}
    public function getPlayer():Player{return $this->player;}
    public function getMessage():string{return $this->message;}
}
