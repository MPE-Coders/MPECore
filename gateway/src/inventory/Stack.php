<?php
declare(strict_types=1);
namespace mpe\inventory;

/** Canonical immutable value. No protocol IDs or client NBT are stored here. */
final readonly class Stack {
    public function __construct(public int $block = 0, public int $count = 0, public int $networkId = 0) {
        if ($block < 0 || $block > 11 || $count < 0 || $count > 64 || $networkId < 0 ||
            (($block === 0) !== ($count === 0)) || (($count === 0) !== ($networkId === 0))) {
            throw new \InvalidArgumentException('Invalid canonical stack');
        }
    }
    public function isEmpty(): bool { return $this->count === 0; }
}
