<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use mpe\network\mcpe\convert\Registry;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types as T;
use pocketmine\network\mcpe\protocol\types\entity\UpdateAttribute;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use Ramsey\Uuid\Uuid;
final class SpawnSequence {
    /** @return list<P\DataPacket> */
    public static function packets(Registry $registry,int $runtimeId,array $feet,string $worldName,bool $allowBuild=true):array {
        $level=new T\LevelSettings();$level->seed=0;
        $level->spawnSettings=new T\SpawnSettings(T\SpawnSettings::BIOME_TYPE_DEFAULT,'',T\DimensionIds::OVERWORLD);
        $level->worldGamemode=1;$level->difficulty=0;
        $level->spawnPosition=new T\BlockPosition((int)floor($feet[0]),(int)floor($feet[1]),(int)floor($feet[2]));
        $level->time=6000;$level->rainLevel=0.0;$level->lightningLevel=0.0;
        $level->commandsEnabled=true;$level->isTexturePacksRequired=false;
        $level->experiments=new T\Experiments([],false);$level->vanillaVersion=$registry->profile->version;
        $level->gameRules=['dodaylightcycle'=>new T\BoolGameRule(false,false),'domobspawning'=>new T\BoolGameRule(false,false)];
        $level->disablePlayerInteractions=false;
        $start=P\StartGamePacket::create(
            $runtimeId,$runtimeId,1,new Vector3($feet[0],$feet[1]+1.62,$feet[2]),0.0,0.0,
            new T\CacheableNbt(CompoundTag::create()),$level,'mpe-flat',$worldName,'',false,
            new T\PlayerMovementSettings(T\ServerAuthMovementMode::SERVER_AUTHORITATIVE_V3,0,true),
            0,0,'',true,'MPE-Core/0.4.0',Uuid::fromString(Uuid::NIL),false,false,false,
            new T\NetworkPermissions(false),false,null,new T\ServerTelemetryData('','','',''),[],0,$registry->items
        );
        $packets=[$start,self::actorData($runtimeId)];
        if($registry->profile->id>=776){$packets[]=P\ItemRegistryPacket::create($registry->items);}
        $packets[]=$registry->actors;$packets[]=$registry->biomes;
        $packets[]=P\UpdateAttributesPacket::create($runtimeId,[
            new UpdateAttribute('minecraft:health',0.0,20.0,20.0,0.0,20.0,20.0,[]),
            new UpdateAttribute('minecraft:movement',0.0,3.402823466e38,0.1,0.0,3.402823466e38,0.1,[]),
            new UpdateAttribute('minecraft:player.hunger',0.0,20.0,20.0,0.0,20.0,20.0,[])
        ],0);
        $packets[]=self::abilities($runtimeId,$allowBuild,false);
        $packets[]=P\UpdateAdventureSettingsPacket::create(true,true,!$allowBuild,true,true);
        $packets[]=InventoryNetwork::creative($registry);
        $packets[]=CommandNetwork::packet();
        return $packets;
    }
    /** Local-player metadata must be explicit; the spawn packet is not an AddPlayer. */
    public static function actorData(int $runtimeId):P\SetActorDataPacket {
        $props=new T\entity\EntityMetadataCollection();
        $props->setGenericFlag(T\entity\EntityMetadataFlags::AFFECTED_BY_GRAVITY,true);
        $props->setGenericFlag(T\entity\EntityMetadataFlags::HAS_COLLISION,true);
        $props->setGenericFlag(T\entity\EntityMetadataFlags::CAN_CLIMB,true);
        $props->setGenericFlag(T\entity\EntityMetadataFlags::NO_AI,false);
        $props->setFloat(T\entity\EntityMetadataProperties::BOUNDING_BOX_WIDTH,0.6);
        $props->setFloat(T\entity\EntityMetadataProperties::BOUNDING_BOX_HEIGHT,1.8);
        $props->setFloat(T\entity\EntityMetadataProperties::SCALE,1.0);
        return P\SetActorDataPacket::create($runtimeId,$props->getAll(),new T\entity\PropertySyncData([],[]),0);
    }
    public static function abilities(int $runtimeId,bool $allowBuild,bool $flying):P\UpdateAbilitiesPacket {
        $abilities=[];
        for($i=0;$i<T\AbilitiesLayer::NUMBER_OF_ABILITIES;$i++) { if(!in_array($i,[13,14,19],true)) { $abilities[$i]=false; } }
        foreach([T\AbilitiesLayer::ABILITY_INVULNERABLE,T\AbilitiesLayer::ABILITY_ALLOW_FLIGHT,T\AbilitiesLayer::ABILITY_INFINITE_RESOURCES,T\AbilitiesLayer::ABILITY_OPEN_CONTAINERS] as $id) { $abilities[$id]=true; }
        $abilities[T\AbilitiesLayer::ABILITY_BUILD]=$allowBuild;
        $abilities[T\AbilitiesLayer::ABILITY_MINE]=$allowBuild;
        $abilities[T\AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES]=$allowBuild;
        $abilities[T\AbilitiesLayer::ABILITY_FLYING]=$flying;
        return P\UpdateAbilitiesPacket::create(new T\AbilitiesData(0,1,$runtimeId,[new T\AbilitiesLayer(T\AbilitiesLayer::LAYER_BASE,$abilities,0.05,0.05,0.1)]));
    }
}
