<?php
declare(strict_types=1);
namespace mpe\network\mcpe\convert;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;

final class BlockPalette {
    public readonly int $air;
    public readonly int $grass;
    public readonly string $sha256;
    public readonly int $count;
    public readonly string $grassName;
    public readonly string $metaSha256;
    private readonly string $metaPath;
    /** @var array<string,int> Typed canonical block-state key -> version-local runtime ID. */
    private array $states=[];
    private array $runtimeMap=[];
    public function __construct(ProtocolProfile $profile,string $assetRoot){
        $bytes=file_get_contents($profile->asset($assetRoot,'block_palette'));
        $this->sha256=hash('sha256',$bytes);
        $this->metaPath=$profile->asset($assetRoot,'block_meta');
        $metaBytes=file_get_contents($this->metaPath);json_decode($metaBytes,true,512,JSON_THROW_ON_ERROR);
        $this->metaSha256=hash('sha256',$metaBytes);unset($metaBytes);
        $roots=(new NetworkNbtSerializer())->readMultiple($bytes);
        $this->count=count($roots);
        if($profile->id===2193){
            $info=$this->metadata();
            if(($info['kind']??null)!=='ordered-states-without-legacy-meta' || ($info['states']??null)!==$this->count || ($info['palette_sha256']??null)!==$this->sha256){throw new \UnexpectedValueException('2193 ordered palette manifest mismatch');}
        }else{\mpe\data\ProfileGuard::metadata($this->metadata(),$this->count);}
        $air=null;$grass=null;$grassName='';
        foreach($roots as $runtimeId=>$root){
            $nbt=$root->mustGetCompoundTag();$name=$nbt->getString('name');$states=$nbt->getCompoundTag('states');
            if($states===null){throw new \UnexpectedValueException('Block palette entry has no states compound');}
            $key=self::key($name,$states);
            if(isset($this->states[$key])){throw new \UnexpectedValueException('Duplicate typed block state');}
            $this->states[$key]=$runtimeId;
            if(count($states->getValue())===0){
                if($name==='minecraft:air'){$air=$runtimeId;}
                if(in_array($name,$profile->data['grass_names'],true)){$grass=$runtimeId;$grassName=$name;}
            }
        }
        if($air===null||$grass===null){throw new \RuntimeException("Protocol {$profile->id}: cannot resolve exact air/grass states");}
        $this->air=$air;$this->grass=$grass;$this->grassName=$grassName;
        $definitions=json_decode(file_get_contents(dirname(__DIR__,5)."/resources/blocks.json"),true,64,JSON_THROW_ON_ERROR);
        $this->runtimeMap=CanonicalBlockRegistry::resolve($this->states,$definitions);
        $this->states=[];
    }
    public static function key(string $name,CompoundTag $states):string {
        $values=[];foreach($states->getValue() as $k=>$tag){$values[$k]=self::typed($tag);}ksort($values,SORT_STRING);
        return $name.'|'.json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
    private static function typed(Tag $tag):array {
        $v=$tag->getValue();
        if(is_array($v)){foreach($v as $k=>$child){if($child instanceof Tag){$v[$k]=self::typed($child);}}if($tag instanceof CompoundTag){ksort($v,SORT_STRING);}}
        // A byte 1 and an int 1 must NEVER be treated as the same block property.
        return [$tag->getType(),$v];
    }
    public function metadata():array{return json_decode(file_get_contents($this->metaPath),true,512,JSON_THROW_ON_ERROR);}
    public function runtimeMap():array{return $this->runtimeMap;}
    public function runtime(int $canonical):int{return $this->runtimeMap[$canonical]??throw new \OutOfBoundsException('Unmapped engine block state');}
}
