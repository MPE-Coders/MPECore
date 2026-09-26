<?php
declare(strict_types=1);
namespace mpe\event\player;
use mpe\player\Player;
final class PlayerCommandEvent {
    public function __construct(private readonly Player $player,private readonly string $command,private readonly array $arguments){}
    public function getPlayer():Player{return $this->player;}
    public function getCommand():string{return $this->command;}
    public function getArguments():array{return $this->arguments;}
}
