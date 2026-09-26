<?php
declare(strict_types=1);
namespace mpe\network\mcpe\convert;
final class ItemMap {
    private array $entries = [];
    public function __construct(array $itemTable, array $definitions) {
        foreach ($definitions as $definition) {
            if ($definition['id'] === 0) { continue; }
            $entry = null;
            foreach ($definition['item_names'] as $name) {
                if (isset($itemTable[$name])) {
                    $entry = ['id' => (int)$itemTable[$name]['runtime_id'], 'meta' => $definition['item_meta'], 'name' => $name];
                    break;
                }
            }
            if ($entry === null || $entry['id'] === 0) {
                throw new \UnexpectedValueException('No version-specific item mapping for '.$definition['name']);
            }
            $this->entries[$definition['id']] = $entry;
        }
    }
    public function get(int $block): array { return $this->entries[$block] ?? throw new \OutOfBoundsException('Item not mapped'); }
    public function all(): array { return $this->entries; }
}
