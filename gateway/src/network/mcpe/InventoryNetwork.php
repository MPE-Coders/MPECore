<?php
declare(strict_types=1);
namespace mpe\network\mcpe;

use mpe\inventory\{PlayerInventory, Stack};
use mpe\network\mcpe\convert\Registry;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types\inventory as I;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest as Q;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse as A;

/** Only this boundary knows Bedrock item/container/network-stack IDs. */
final class InventoryNetwork {
    public static function item(Registry $r, Stack $stack): I\ItemStack {
        $extra = new ByteBufferWriter(); (new I\ItemStackExtraData(null, [], []))->write($extra);
        if ($stack->isEmpty()) { return new I\ItemStack(0,0,0,0,$extra->getData()); }
        $item = $r->itemMap->get($stack->block);
        return new I\ItemStack($item['id'], $item['meta'], $stack->count, $r->blocks->runtime($stack->block), $extra->getData());
    }
    public static function wrap(Registry $r, Stack $stack): I\ItemStackWrapper {
        return new I\ItemStackWrapper($stack->networkId, self::item($r, $stack));
    }
    public static function creative(Registry $r): P\CreativeContentPacket {
        $items = [];
        foreach ($r->itemMap->all() as $id => $_) {
            $items[] = new I\CreativeItemEntry($id, self::item($r, new Stack($id, 1, $id)), 0);
        }
        $group = new I\CreativeGroupEntry(P\CreativeContentPacket::CATEGORY_CONSTRUCTION, 'MPE Building Blocks', self::item($r, new Stack(1, 1, 1)));
        return P\CreativeContentPacket::create([$group], $items);
    }
    public static function contents(Registry $r, PlayerInventory $inventory, int $windowId = I\ContainerIds::INVENTORY): array {
        $empty = self::wrap($r, new Stack());
        return [
            P\InventoryContentPacket::create($windowId, array_map(fn(Stack $s) => self::wrap($r,$s),$inventory->contents()), new I\FullContainerName(I\ContainerUIIds::COMBINED_HOTBAR_AND_INVENTORY),0,$empty),
            P\InventorySlotPacket::create(I\ContainerIds::UI,0,new I\FullContainerName(I\ContainerUIIds::CURSOR),0,$empty,self::wrap($r,$inventory->cursor())),
        ];
    }
    public static function held(Registry $r, PlayerInventory $i, int $actor): P\MobEquipmentPacket {
        return P\MobEquipmentPacket::create($actor,self::wrap($r,$i->held()),$i->selected(),$i->selected(),I\ContainerIds::INVENTORY);
    }
    private static function ref(Q\ItemStackRequestSlotInfo $ref): array {
        if ($ref->getContainerName()->getDynamicId() !== null && $ref->getContainerName()->getDynamicId() !== 0) {
            throw new \UnexpectedValueException('Dynamic containers not supported');
        }
        return [$ref->getContainerName()->getContainerId(),$ref->getSlotId(),$ref->getStackId()];
    }
    public static function actions(Q\ItemStackRequest $request): array {
        $result=[];
        foreach($request->getActions() as $a) {
            $result[] = match (true) {
                $a instanceof Q\CreativeCreateStackRequestAction => ['type'=>'creative','block'=>$a->getCreativeItemId()],
                $a instanceof Q\CraftingCreateSpecificResultStackRequestAction => ['type'=>'output','index'=>$a->getResultIndex()],
                $a instanceof Q\TakeStackRequestAction, $a instanceof Q\PlaceStackRequestAction => ['type'=>'move','source'=>self::ref($a->getSource()),'destination'=>self::ref($a->getDestination()),'count'=>$a->getCount()],
                $a instanceof Q\SwapStackRequestAction => ['type'=>'swap','source'=>self::ref($a->getSlot1()),'destination'=>self::ref($a->getSlot2())],
                $a instanceof Q\DestroyStackRequestAction => ['type'=>'destroy','source'=>self::ref($a->getSource()),'count'=>$a->getCount()],
                default => throw new \UnexpectedValueException('Unsupported stack action '.get_debug_type($a)),
            };
        }
        return $result;
    }
    public static function response(int $requestId, ?array $changes, array $actions=[]): P\ItemStackResponsePacket {
        if ($changes === null) { return P\ItemStackResponsePacket::create([new A\ItemStackResponse(A\ItemStackResponse::RESULT_ERROR,$requestId)]); }
        $groups=[];$references=[];
        foreach($actions as $action){
            foreach(['source','destination'] as $ref){
                if(isset($action[$ref])){
                    [$container,$slot]=$action[$ref];
                    $key=PlayerInventory::key($container,$slot);
                    $references[$key][$container.':'.$slot]=[$container,$slot];
                }
            }
        }
        foreach ($changes as $key=>$s) {
            [$container,$slot] = match ($key) {
                'cursor' => [I\ContainerUIIds::CURSOR,0], 'output' => [I\ContainerUIIds::CREATED_OUTPUT,50],
                default => [I\ContainerUIIds::COMBINED_HOTBAR_AND_INVENTORY,(int)substr($key,2)],
            };
            foreach($references[$key]??[[$container,$slot]] as [$container,$slot]){
                $groups[$container][] = new A\ItemStackResponseSlotInfo($slot,$slot,$s->count,$s->networkId,'','',0);
            }
        }
        $infos=[];
        foreach ($groups as $id=>$slots) { $infos[]=new A\ItemStackResponseContainerInfo(new I\FullContainerName($id),$slots); }
        return P\ItemStackResponsePacket::create([new A\ItemStackResponse(A\ItemStackResponse::RESULT_OK,$requestId,$infos)]);
    }
}
