<?php
declare(strict_types=1);
namespace mpe\network\mcpe;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\{CompoundTag,ListTag};
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;

/** Prerequisites consumed while the client constructs its world from StartGame.
 * The current flat world has no jigsaw rules or custom behavior-pack voxel shapes.
 * Empty registries are explicit data, not missing packets or a vanilla worldgen claim.
 */
final class WorldStartData {
    public const STRUCTURE_LISTS = ['processors','template_pools','jigsaws','structure_sets'];

    /** @return list<P\DataPacket> */
    public static function packets(int $protocol):array {
        $packets=[];
        // Packet 313 was introduced in 712; older clients must not receive it.
        if($protocol>=712){
            $root=CompoundTag::create();
            foreach(self::STRUCTURE_LISTS as $name){
                $root->setTag($name,new ListTag([],NBT::TAG_Compound));
            }
            $packets[]=P\JigsawStructureDataPacket::create(new CacheableNbt($root));
        }
        // Packet 337 starts at 924. The native serializer handles the additional
        // customShapeCount field present since 944; the 2193 schema also has it.
        if($protocol>=924){$packets[]=P\VoxelShapesPacket::create([],[],0);}
        return $packets;
    }
}
