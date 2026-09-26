<?php
declare(strict_types=1);
namespace mpe\network\mcpe;

use mpe\inventory\PlayerInventory;
use mpe\utils\Binary;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types as T;
use pocketmine\network\mcpe\protocol\types\inventory as I;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;

/** Ordinary Bedrock input, independent of test-client slash commands. */
final class CreativeGameHandler {
    public readonly PlayerInventory $inventory;
    public bool $flying = false;
    private array $pending = [];
    private string $lastInteraction = '';
    private float $lastInteractionAt = 0.0;
    private float $lastSync = 0.0;
    private float $budgetAt = 0.0;
    private int $requests = 0;
    private int $serial = 0;
    private ?int $inventoryWindow = null;
    private int $nextWindow = 1;
    public function __construct(private NetworkSession $session) { $this->inventory = new PlayerInventory(); }
    public function sync(bool $force = false): void {
        $now=microtime(true);
        if (!$force && $now-$this->lastSync<0.1) { return; }
        $this->lastSync=$now;
        foreach (InventoryNetwork::contents($this->session->registry(),$this->inventory) as $p) { $this->session->send($p); }
        $this->session->send(InventoryNetwork::held($this->session->registry(),$this->inventory,$this->session->runtimeId));
    }
    private function budget(): bool {
        $now=microtime(true);
        if ($now-$this->budgetAt>=1.0) { $this->budgetAt=$now;$this->requests=0; }
        return ++$this->requests<=80;
    }
    public function input(P\PlayerAuthInputPacket $p): void {
        $flags=$p->getInputFlags();
        if ($flags->get(T\PlayerAuthInputFlags::START_FLYING)) { $this->flight(true); }
        if ($flags->get(T\PlayerAuthInputFlags::STOP_FLYING)) { $this->flight(false); }
        $use=$p->getItemInteractionData();
        if ($use!==null) { $this->useItem($use->getTransactionData()); }
        $request=$p->getItemStackRequest();
        if ($request!==null) { $this->stackRequest($request); }
        $actions=$p->getBlockActions()??[];
        if(count($actions)>64) { throw new \OverflowException('Too many block actions'); }
        foreach($actions as $action) {
            if($action instanceof T\PlayerBlockActionWithBlockInfo) {
                $this->blockAction($action->getActionType(),$action->getBlockPosition(),$action->getFace());
            }
        }
    }
    public function handle(P\DataPacket $p): bool {
        if($p instanceof P\MobEquipmentPacket) {
            if($p->actorRuntimeId===$this->session->runtimeId && $p->windowId===I\ContainerIds::INVENTORY) {
                if(!$this->inventory->select($p->hotbarSlot)) { $this->sync(); }
                // Selection only: an equipment packet can never create an item.
            }
            return true;
        }
        if($p instanceof P\InventoryTransactionPacket) {
            if(!$this->budget()) { $this->sync();return true; }
            if(count($p->trData->getActions())>64) { throw new \OverflowException('Too many transaction actions'); }
            if($p->trData instanceof I\UseItemTransactionData) { $this->useItem($p->trData); }
            else { $this->sync(); }
            return true;
        }
        if($p instanceof P\ItemStackRequestPacket) {
            if(count($p->getRequests())>32) { throw new \OverflowException('Too many inventory requests'); }
            foreach($p->getRequests() as $request) { $this->stackRequest($request); }
            return true;
        }
        if($p instanceof P\PlayerActionPacket) {
            if($p->actorRuntimeId===$this->session->runtimeId) { $this->blockAction($p->action,$p->blockPosition,$p->face); }
            return true;
        }
        if($p instanceof P\RequestAbilityPacket) {
            if($p->getAbilityId()===P\RequestAbilityPacket::ABILITY_FLYING && is_bool($p->getAbilityValue())) { $this->flight($p->getAbilityValue()); }
            else { $this->sendAbilities(); }
            return true;
        }
        if($p instanceof P\InteractPacket) {
            if($p->action===P\InteractPacket::ACTION_OPEN_INVENTORY && $p->targetActorRuntimeId===$this->session->runtimeId && $this->budget()) {
                if($this->inventoryWindow===null) {
                    $this->inventoryWindow=$this->nextWindow;
                    $this->nextWindow=$this->nextWindow>=99?1:$this->nextWindow+1;
                    $this->session->send(P\ContainerOpenPacket::entityInv($this->inventoryWindow,255,$this->session->runtimeId));
                }
                $this->sync(true);
                foreach(InventoryNetwork::contents($this->session->registry(),$this->inventory,$this->inventoryWindow) as $content) { $this->session->send($content); }
                $this->session->diagnostic('inventory_opened',(string)$this->inventoryWindow);
            }
            return true;
        }
        if($p instanceof P\ContainerClosePacket) {
            if($p->windowId===-1 || $p->windowId===255 || $p->windowId===$this->inventoryWindow) { $this->inventoryWindow=null; }
            $this->session->send(P\ContainerClosePacket::create($p->windowId,$p->windowType??255,false));
            $this->sync();return true;
        }
        return false;
    }
    private function flight(bool $enabled): void {
        if($this->flying!==$enabled) { $this->flying=$enabled;$this->sendAbilities(); }
    }
    private function sendAbilities(): void {
        $this->session->send(SpawnSequence::abilities($this->session->runtimeId,$this->session->canBuild(),$this->flying));
    }
    private function stackRequest(ItemStackRequest $request): void {
        $changes=null;
        try {
            if(!$this->budget()) { throw new \UnexpectedValueException('Inventory request budget'); }
            $changes=$this->inventory->request($request->getRequestId(),InventoryNetwork::actions($request),$this->session->canBuild());
        } catch(\UnexpectedValueException|\InvalidArgumentException $e) { $this->session->diagnostic('inventory_rejected',$e->getMessage()); }
        $this->session->send(InventoryNetwork::response($request->getRequestId(),$changes));
        $this->sync(true);
    }
    private function useItem(I\UseItemTransactionData $data): void {
        if(!$this->inventory->select($data->getHotbarSlot())) { $this->sync();return; }
        $pos=$data->getBlockPosition();
        if($data->getActionType()===I\UseItemTransactionData::ACTION_BREAK_BLOCK) {
            $this->interaction(0,$pos,$data->getFace());return;
        }
        if($data->getActionType()!==I\UseItemTransactionData::ACTION_CLICK_BLOCK) { return; }
        $serverItem=InventoryNetwork::item($this->session->registry(),$this->inventory->held());
        $clientItem=$data->getItemInHand()->getItemStack();
        if($serverItem->getId()!==$clientItem->getId() || $serverItem->getMeta()!==$clientItem->getMeta() ||
            $serverItem->getBlockRuntimeId()!==$clientItem->getBlockRuntimeId()) {
            $this->session->resendAround($pos->getX(),$pos->getZ());$this->sync();return;
        }
        $this->interaction(1,$pos,$data->getFace());
    }
    private function blockAction(int $action,T\BlockPosition $pos,int $face): void {
        if(in_array($action,[T\PlayerAction::START_BREAK,T\PlayerAction::CREATIVE_PLAYER_DESTROY_BLOCK,T\PlayerAction::PREDICT_DESTROY_BLOCK],true)) {
            $this->interaction(0,$pos,$face);
        } elseif($action===T\PlayerAction::GET_UPDATED_BLOCK) { $this->session->resendAround($pos->getX(),$pos->getZ()); }
    }
    private function interaction(int $action,T\BlockPosition $pos,int $face): void {
        $now=microtime(true);$x=$pos->getX();$y=$pos->getY();$z=$pos->getZ();
        if(!$this->session->canBuild() || !$this->budget() || count($this->pending)>=32 ||
            abs($x)>100000 || abs($z)>100000 || $y< -64 || $y>319 || ($action===1&&($face<0||$face>5))) {
            $this->session->resendAround($x,$z);$this->sync();return;
        }
        $signature="$action:$x:$y:$z:$face";
        if($signature===$this->lastInteraction && $now-$this->lastInteractionAt<0.12) { return; }
        $this->lastInteraction=$signature;$this->lastInteractionAt=$now;
        $block=$action===1?$this->inventory->held()->block:0;
        if($action===1 && $block===0) { return; }
        $nonce='game_'.(++$this->serial);$this->pending[$nonce]=[$x,$z,hrtime(true)];
        $this->session->engine("\x0b".Binary::u64($this->session->sid).Binary::str($nonce).chr($action).
            Binary::i32($x).Binary::i32($y).Binary::i32($z).pack('c',$face).chr($block));
        $this->session->diagnostic('interaction_requested',"$nonce $signature block=$block");
    }
    public function completed(string $nonce,bool $ok,string $reason=''): bool {
        if(!isset($this->pending[$nonce])) { return false; }
        $elapsed=(hrtime(true)-$this->pending[$nonce][2])/1_000_000;
        $this->session->performance->interaction($ok,$elapsed);
        unset($this->pending[$nonce]);
        $this->session->diagnostic($ok?'interaction_committed':'interaction_rejected',$nonce.' '.$reason);
        if(!$ok) { $this->sync(); }
        return true;
    }
}
