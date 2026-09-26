<?php
declare(strict_types=1);
namespace mpe\event\block;
use mpe\player\Player;
use mpe\math\Vector3;
/** Notification AFTER durable commit. Not cancellable; no mutable world object escapes the engine. */
final class BlockChangeEvent {
    public function __construct(private Player $player,private Vector3 $position,private int $block,private int $previous) {}
    public function getPlayer():Player {return $this->player;}
    public function getPosition():Vector3 {return $this->position;}
    public function getBlockId():int {return $this->block;}
    public function getPreviousBlockId():int {return $this->previous;}
    public function isBreak():bool {return $this->block===0;}
}
