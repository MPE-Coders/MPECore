<?php
declare(strict_types=1);
namespace mpe\data;

/** Version-specific Cloudburst/Pumpkin dump, never a nearest-version palette. */
final class ModernData {
    public static function root(string $root): string { return $root.'/.runtime/bedrock/2193'; }
    public static function validateProfile(array $p): void {
        if (($p['protocol']??null)!==2193 || ($p['game_version']??null)!=='1.26.51' ||
            ($p['codec']??null)!=='prismarine-bridge' || ($p['codec_base']??null)!==1001 ||
            ($p['data_status']??null)!=='pinned-cloudburst-2193' ||
            ($p['data_reference']??null)!=='a8a4341d7763d6eb8547cff3ca46b4153d60163d' ||
            ($p['codec_reference']??null)!=='aae6ba55aa75b2c188aade6cadca5fb6d2633d33' ||
            ($p['min_section']??null)!==-4 || ($p['max_section']??null)!==19) {
            throw new \UnexpectedValueException('Unrecognised schema-3 codec/data contract');
        }
        $names=['block_palette'=>'block_states-2193.nbt','block_meta'=>'block_state_info-2193.json',
            'items'=>'required_item_list-2193.json','entity_identifiers'=>'entity_identifiers-2193.nbt','biomes'=>'biome_definitions-2193.json'];
        foreach($names as $key=>$name){if(($p[$key]??null)!==$name){throw new \UnexpectedValueException('Wrong 2193 asset: '.$key);}}
    }
    public static function fingerprints(string $root,array $profile): array {
        self::validateProfile($profile);
        $dir=self::root($root);$file=$dir.'/bundle.json';
        if(!is_file($file)){throw new \RuntimeException('2193 data is missing. Run python3 tools/prepare-modern-data.py --protocol 2193');}
        $bundle=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if(($bundle['protocol']??null)!==2193 || ($bundle['source_manifest_sha256']??null)!==hash_file('sha256',$root.'/resources/modern/2193.sources.json')){
            throw new \UnexpectedValueException('2193 source receipt mismatch');
        }
        $out=[];
        foreach(ProfileGuard::ASSETS as $key){
            $name=$profile[$key];$path=$dir.'/'.$name;
            if(!is_file($path) || filesize($path)<1 || filesize($path)>64*1024*1024){throw new \UnexpectedValueException('Invalid 2193 asset: '.$name);}
            $actual=['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path)];
            if(($bundle['outputs'][$name]??null)!==$actual){throw new \UnexpectedValueException('Changed 2193 asset: '.$name);}
            $out[$key]=['file'=>$name]+$actual;
        }
        \mpe\network\mcpe\CodecBridge::enable($root);
        return $out;
    }
}
