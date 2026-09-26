<?php
declare(strict_types=1);
namespace mpe\math;
/** Immutable snapshot; changing plugin variables cannot mutate the simulation behind its back. */
final class Vector3 {
    public function __construct(public readonly float $x,public readonly float $y,public readonly float $z){}
    public function add(float $x,float $y,float $z):self{return new self($this->x+$x,$this->y+$y,$this->z+$z);}
}
