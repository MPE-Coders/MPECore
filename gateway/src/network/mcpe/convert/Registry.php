<?php
declare(strict_types=1);
namespace mpe\network\mcpe\convert;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types as T;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\color\Color;
use pocketmine\network\mcpe\protocol\types\biome\BiomeDefinitionEntry;
final class Registry {
    public readonly BlockPalette $blocks;
    public readonly array $items;
    public readonly ItemMap $itemMap;
    public readonly P\BiomeDefinitionListPacket $biomes;
    public readonly P\AvailableActorIdentifiersPacket $actors;
    public function __construct(public readonly ProtocolProfile $profile,string $assetRoot){
        $this->blocks=new BlockPalette($profile,$assetRoot);$items=[];
        $raw=json_decode(file_get_contents($profile->asset($assetRoot,'items')),true,512,JSON_THROW_ON_ERROR);
        \mpe\data\ProfileGuard::items($raw);
        foreach($raw as $name=>$v){
            $nbt=CompoundTag::create();
            if(isset($v['component_nbt'])){
                $binary=base64_decode($v['component_nbt'],true);if($binary===false){throw new \UnexpectedValueException('Invalid component NBT base64');}
                $nbt=(new LittleEndianNbtSerializer())->read($binary)->mustGetCompoundTag();
            }
            $items[]=new T\ItemTypeEntry($name,$v['runtime_id'],$v['component_based'],$v['version']??2,new T\CacheableNbt($nbt));
        }
        $this->items=$items;
        $definitions=json_decode(file_get_contents(dirname(__DIR__,5).'/resources/blocks.json'),true,64,JSON_THROW_ON_ERROR);
        $this->itemMap=new ItemMap($raw,$definitions);
        $serializer=new NetworkNbtSerializer();
        $this->actors=P\AvailableActorIdentifiersPacket::create(new T\CacheableNbt($serializer->read(file_get_contents($profile->asset($assetRoot,'entity_identifiers')))->mustGetCompoundTag()));
        if($profile->id<800){
            $this->biomes=P\BiomeDefinitionListPacket::createLegacy(new T\CacheableNbt($serializer->read(file_get_contents($profile->asset($assetRoot,'biomes')))->mustGetCompoundTag()));
        }else{
            $entries=[];$data=json_decode(file_get_contents($profile->asset($assetRoot,'biomes')),true,512,JSON_THROW_ON_ERROR);
            foreach($data as $name=>$v){
                $c=$v['mapWaterColour'];
                $entries[]=new BiomeDefinitionEntry($name,$v['id'],$v['temperature'],$v['downfall'],$v['redSporeDensity'],$v['blueSporeDensity'],$v['ashDensity'],$v['whiteAshDensity'],$v['foliageSnow'],$v['depth'],$v['scale'],new Color($c['r'],$c['g'],$c['b'],$c['a']),$v['rain'],($v['tags']??[])!==[]?$v['tags']:null);
            }
            $this->biomes=P\BiomeDefinitionListPacket::fromDefinitions($entries);
        }
    }
}
