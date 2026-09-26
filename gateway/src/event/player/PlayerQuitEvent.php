<?php
declare(strict_types=1);
namespace mpe\event\player;
use mpe\player\Player;
final class PlayerQuitEvent {
    public function __construct(private readonly Player $player){}
    public function getPlayer():Player{return $this->player;}
}
