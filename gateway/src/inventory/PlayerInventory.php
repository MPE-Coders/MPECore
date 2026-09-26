<?php
declare(strict_types=1);
namespace mpe\inventory;

/**
 * Creative-playtest inventory. Mutations are atomic; unsupported actions roll back.
 * This is NOT a survival/crafting implementation. All IDs are session-owned.
 */
final class PlayerInventory {
    private array $slots = [];
    private array $lastRequests = [];
    private array $completed = [];
    private int $nextId = 1;
    private int $selected = 0;

    public function __construct() {
        for ($i = 0; $i < 36; ++$i) { $this->slots['i:'.$i] = new Stack(); }
        $this->slots['cursor'] = new Stack();
        $this->slots['output'] = new Stack();
        // All eleven building blocks; first nine are immediately usable in the hotbar.
        foreach (range(1, 11) as $i => $block) { $this->set($i, $block, 64); }
    }
    public function selected(): int { return $this->selected; }
    public function select(int $slot): bool {
        if ($slot < 0 || $slot > 8) { return false; }
        $this->selected = $slot; return true;
    }
    public function get(int $slot): Stack {
        if ($slot < 0 || $slot >= 36) { throw new \OutOfBoundsException('Inventory slot'); }
        return $this->slots['i:'.$slot];
    }
    public function held(): Stack { return $this->get($this->selected); }
    public function cursor(): Stack { return $this->slots['cursor']; }
    public function contents(): array { return array_map($this->get(...), range(0, 35)); }
    public function set(int $slot, int $block, int $count = 64): void {
        $this->get($slot); $this->slots['i:'.$slot] = $this->make($block, $block === 0 ? 0 : $count);
        unset($this->lastRequests['i:'.$slot]);
    }
    private function make(int $block, int $count): Stack {
        if ($count === 0) { return new Stack(); }
        return new Stack($block, $count, $this->nextId++);
    }
    /** Bedrock UI container IDs, NOT InventoryContent window IDs. */
    public static function key(int $container, int $slot): string {
        if (in_array($container, [12, 28, 29], true) && $slot >= 0 && $slot < 36 && ($container !== 28 || $slot < 9)) {
            return 'i:'.$slot;
        }
        if ($container === 59 && $slot === 0) { return 'cursor'; }
        if ($container === 60 && $slot === 50) { return 'output'; }
        throw new \UnexpectedValueException('Unsupported container or slot');
    }
    private function checked(array $ref, array $slots, array $changed, int $requestId): string {
        [$container, $slot, $stackId] = $ref;
        $key = self::key($container, $slot);
        $value = $slots[$key];
        $sameRequest = $stackId < 0 && isset($changed[$key]) && $stackId === -abs($requestId);
        $previousRequest = $stackId < 0 && ($this->lastRequests[$key] ?? null) === $stackId;
        $createdOutput = $key === 'output' && isset($changed[$key]) && ($stackId === 0 || $stackId === -abs($requestId));
        if ($stackId !== $value->networkId && !$sameRequest && !$previousRequest && !$createdOutput) {
            throw new \UnexpectedValueException('Stale stack ID');
        }
        return $key;
    }
    /** @return array<string,Stack> changed logical slots; throws without committing on failure. */
    public function request(int $requestId, array $actions, bool $creative): array {
        if ($requestId === 0 || $requestId > 2147483647 || $requestId < -2147483647 || count($actions) < 1 || count($actions) > 64) {
            throw new \UnexpectedValueException('Invalid stack request');
        }
        if (isset($this->completed[$requestId])) { throw new \UnexpectedValueException('Replayed stack request'); }
        $slots = $this->slots; $changed = []; $nextId = $this->nextId;
        try {
            foreach ($actions as $a) {
                $type = $a['type'];
                if ($type === 'creative') {
                    if (!$creative || isset($changed['output']) || $a['block'] < 1 || $a['block'] > 11) {
                        throw new \UnexpectedValueException('Creative item not allowed');
                    }
                    $slots['output'] = $this->make($a['block'], 64); $changed['output'] = true;
                } elseif ($type === 'output') {
                    if (!isset($changed['output']) || $a['index'] !== 0) { throw new \UnexpectedValueException('No creative output'); }
                } elseif ($type === 'move') {
                    $src = $this->checked($a['source'], $slots, $changed, $requestId);
                    $dst = $this->checked($a['destination'], $slots, $changed, $requestId);
                    $n = $a['count']; $s = $slots[$src]; $d = $slots[$dst];
                    if ($src === $dst || $dst === 'output' || $n < 1 || $n > $s->count ||
                        (!$d->isEmpty() && $d->block !== $s->block) || $d->count + $n > 64) {
                        throw new \UnexpectedValueException('Invalid stack transfer');
                    }
                    $slots[$dst] = $this->make($s->block, $d->count + $n);
                    $slots[$src] = $this->make($s->block, $s->count - $n);
                    $changed[$src] = $changed[$dst] = true;
                } elseif ($type === 'swap') {
                    $src = $this->checked($a['source'], $slots, $changed, $requestId);
                    $dst = $this->checked($a['destination'], $slots, $changed, $requestId);
                    if ($src === 'output' || $dst === 'output') { throw new \UnexpectedValueException('Cannot swap output'); }
                    [$slots[$src], $slots[$dst]] = [$slots[$dst], $slots[$src]];
                    $changed[$src] = $changed[$dst] = true;
                } elseif ($type === 'destroy') {
                    if (!$creative) { throw new \UnexpectedValueException('Creative delete only'); }
                    $src = $this->checked($a['source'], $slots, $changed, $requestId);
                    $n = $a['count']; $s = $slots[$src];
                    if ($n < 1 || $n > $s->count) { throw new \UnexpectedValueException('Invalid delete count'); }
                    $slots[$src] = $this->make($s->block, $s->count - $n); $changed[$src] = true;
                } else { throw new \UnexpectedValueException('Unsupported inventory action: '.$type); }
            }
            if (isset($changed['output'])) { $slots['output'] = new Stack(); }
        } catch (\Throwable $e) { $this->nextId = $nextId; throw $e; }
        $this->slots = $slots;
        foreach ($changed as $key => $_) { $this->lastRequests[$key] = -abs($requestId); }
        $this->completed[$requestId] = true;
        if (count($this->completed) > 128) { unset($this->completed[array_key_first($this->completed)]); }
        return array_intersect_key($slots, $changed);
    }
}
