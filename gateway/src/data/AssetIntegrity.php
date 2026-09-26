<?php
declare(strict_types=1);
namespace mpe\data;

/** Detects source/profile drift. Local fingerprints are not a Mojang signature. */
final class AssetIntegrity {
    private array $lock;
    private array $checked = [];
    private bool $dirty = false;

    public function __construct(private readonly string $root, private readonly array $references) {
        $file = $root.'/data/bedrock-assets.lock.json';
        $this->lock = is_file($file)
            ? json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)
            : ['schema'=>1, 'references'=>$references, 'files'=>[]];
        if (($this->lock['schema'] ?? null) !== 1 || !is_array($this->lock['files'] ?? null)) {
            throw new \UnexpectedValueException('Invalid Bedrock asset lock file');
        }
        self::references($references, $this->lock['references'] ?? []);
    }

    public static function references(array $expected, array $actual): void {
        foreach ($expected as $name => $ref) {
            if (!is_string($actual[$name] ?? null) || !hash_equals($ref, $actual[$name])) {
                throw new \UnexpectedValueException("Source reference mismatch for $name; refusing mixed upstream snapshots");
            }
        }
    }

    public static function installedReferences(string $root): array {
        $spec = json_decode((string)file_get_contents($root.'/resources/upstream.json'), true, 32, JSON_THROW_ON_ERROR);
        $expected = []; $actual = [];
        foreach ($spec['packages'] as $name => $entry) {
            $expected[$name] = $entry['reference'];
            $actual[$name] = \Composer\InstalledVersions::getReference($name);
        }
        self::references($expected, $actual);
        return $expected;
    }

    public function profile(array $profile, string $assetRoot): array {
        ProfileGuard::validate($profile);
        if($profile['protocol']===2193){
            self::references(['nethergamesmc/bedrock-protocol'=>$profile['codec_reference']],$this->references);
            return ModernData::fingerprints($this->root,$profile);
        }
        self::references([
            'nethergamesmc/bedrock-data'=>$profile['data_reference'],
            'nethergamesmc/bedrock-protocol'=>$profile['codec_reference']
        ], $this->references);
        $result = [];
        foreach (ProfileGuard::ASSETS as $key) {
            $name = $profile[$key]; $path = $profile['protocol']===1001 && $key==='block_meta'
                ? NativeMetadata::path($this->root) : $assetRoot.'/'.$name;
            if (!isset($this->checked[$name])) {
                $size = is_file($path) ? filesize($path) : false;
                if ($size === false || $size === 0 || $size > 64 * 1024 * 1024) {
                    throw new \UnexpectedValueException("Missing, empty or oversized profile asset: $name");
                }
                $digest = hash_file('sha256', $path);
                if ($digest === false) { throw new \RuntimeException("Cannot hash $name"); }
                $entry = ['bytes'=>$size, 'sha256'=>$digest];
                if (isset($this->lock['files'][$name]) && $this->lock['files'][$name] !== $entry) {
                    throw new \UnexpectedValueException("Asset changed since first verified-source load: $name. Restore the pinned vendor tree; do not substitute a neighbouring version.");
                }
                if (!isset($this->lock['files'][$name])) { $this->lock['files'][$name] = $entry; $this->dirty = true; }
                $this->checked[$name] = $entry;
            }
            $result[$key] = ['file'=>$name] + $this->checked[$name];
        }
        return $result;
    }

    /** Called only after every registry decoded successfully. */
    public function commit(): void {
        if (!$this->dirty) { return; }
        $dir = $this->root.'/data';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create data directory'); }
        ksort($this->lock['files']);
        $file = $dir.'/bedrock-assets.lock.json'; $temp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $data = json_encode($this->lock, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data)) { @unlink($temp); throw new \RuntimeException('Cannot write asset lock'); }
        chmod($temp, 0600);
        if (!rename($temp, $file)) { @unlink($temp); throw new \RuntimeException('Cannot replace asset lock'); }
        $this->dirty = false;
    }
}
