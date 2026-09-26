<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol as P;

/** Wire representation only; feet and client input tick come from the Rust owner. */
final class MovementSync {
    public static function correction(array $feet, int $tick, bool $onGround, float $velocityY=0.0): P\CorrectPlayerMovePredictionPacket {
        return P\CorrectPlayerMovePredictionPacket::create(
            new Vector3($feet[0],$feet[1]+1.62,$feet[2]),new Vector3(0,$velocityY,0),$onGround,$tick,
            P\CorrectPlayerMovePredictionPacket::PREDICTION_TYPE_PLAYER,new Vector2(0,0),null
        );
    }
    public static function teleport(int $runtimeId,array $feet,float $pitch,float $yaw,int $tick): P\MovePlayerPacket {
        return P\MovePlayerPacket::simple($runtimeId,new Vector3($feet[0],$feet[1]+1.62,$feet[2]),
            $pitch,$yaw,$yaw,P\MovePlayerPacket::MODE_RESET,false,0,$tick);
    }
}
