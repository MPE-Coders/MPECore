<?php
declare(strict_types=1);
namespace mpe\data;

/** A packet number is not proof that this server understands the associated data. */
final class ProfileGuard {
    public const ASSETS = ['block_palette', 'block_meta', 'items', 'entity_identifiers', 'biomes'];

    public static function validate(array $profile): void {
        if (($profile['schema_version'] ?? null) === 3) { ModernData::validateProfile($profile); return; }
        if (($profile['schema_version'] ?? null) !== 2) {
            throw new \UnexpectedValueException('Unsupported profile schema; expected version 2 or explicit modern profile');
        }
        foreach (['protocol', 'min_section', 'max_section', 'codec_base'] as $key) {
            if (!isset($profile[$key]) || !is_int($profile[$key])) {
                throw new \UnexpectedValueException("Profile field $key must be an integer");
            }
        }
        if (($profile['data_status'] ?? '') !== 'explicit-nethergames-aliases') {
            throw new \UnexpectedValueException('Protocol '.$profile['protocol'].': no verified version-specific data bundle. A newer codec cannot reuse protocol 1001 palettes by assumption.');
        }
        if (($profile['codec'] ?? '') !== 'nethergames' || $profile['codec_base'] !== $profile['protocol']) {
            throw new \UnexpectedValueException('This runtime requires a native NetherGames codec matching the profile');
        }
        foreach (['data_reference', 'codec_reference'] as $key) {
            if (!is_string($profile[$key] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $profile[$key])) {
                throw new \UnexpectedValueException("Profile must pin a full Git commit in $key");
            }
        }
        foreach (self::ASSETS as $key) {
            $file = $profile[$key] ?? null;
            if (!is_string($file) || $file === '' || basename($file) !== $file || str_contains($file, '\\') || str_contains($file, "\0")) {
                throw new \UnexpectedValueException("Invalid asset path in $key");
            }
        }
        if ($profile['min_section'] !== -4 || $profile['max_section'] !== 19) {
            throw new \UnexpectedValueException('Only the post-1.18 Overworld height range is implemented');
        }
    }

    public static function accepted(int $id, array $accepted): void {
        // Only this specific reviewed adapter uses 1001 as an intermediate object representation.
        if ($id === 2193 && in_array(1001, $accepted, true)) { return; }
        if (!in_array($id, $accepted, true)) {
            throw new \UnexpectedValueException("Protocol $id is not in this exact codec's ACCEPTED_PROTOCOL list. Use a separate legacy gateway rather than relabel packets.");
        }
    }

    public static function metadata(array $values, int $states): void {
        if (!array_is_list($values) || count($values) !== $states) {
            throw new \UnexpectedValueException('block_state_meta_map length/order does not match canonical_block_states');
        }
        foreach ($values as $value) {
            if (!is_int($value)) { throw new \UnexpectedValueException('Every block-state metadata entry must be an integer'); }
        }
    }

    public static function items(array $items): void {
        if ($items === [] || array_is_list($items)) { throw new \UnexpectedValueException('Item registry must be a nonempty name-to-entry map'); }
        $ids = [];
        foreach ($items as $name => $v) {
            if (!is_string($name) || !str_contains($name, ':') || !is_array($v) ||
                !is_int($v['runtime_id'] ?? null) || !is_bool($v['component_based'] ?? null) ||
                (isset($v['version']) && !is_int($v['version'])) ||
                (isset($v['component_nbt']) && !is_string($v['component_nbt']))) {
                throw new \UnexpectedValueException('Invalid versioned item entry: '.(string)$name);
            }
            $id = $v['runtime_id'];
            if ($id < -32768 || $id > 32767 || isset($ids[$id])) {
                throw new \UnexpectedValueException('Out-of-range or duplicated item runtime ID: '.$id);
            }
            $ids[$id] = $name;
        }
    }
}
