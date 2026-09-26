<?php
declare(strict_types=1);
namespace mpe\data;
/** The 26.30 upstream snapshot has 16913 states but an erroneous 16914-entry meta map.
 * Use the corrected VERSIONED upstream file, never truncate/pad it to fit.
 */
final class NativeMetadata {
    public const FILE='block_state_meta_map-1.26.30.json';
    public const COMMIT='e08720dda2f32bc7cab7f0bf5bde354f3434f56e';
    public const BLOB='910a64d51fb1a1dbf86c5a6e959bc8b4e9686837';
    public static function path(string $root): string {
        $path=$root.'/.runtime/bedrock/1001/'.self::FILE;
        if(!is_file($path)||filesize($path)>512*1024){throw new \RuntimeException('Missing corrected 1001 metadata; run python3 tools/prepare-native-metadata.py');}
        $bytes=file_get_contents($path);
        if($bytes===false||!hash_equals(self::BLOB,sha1('blob '.strlen($bytes)."\0".$bytes))){
            throw new \UnexpectedValueException('Corrected 1001 metadata does not match pinned upstream blob');
        }
        return $path;
    }
}
