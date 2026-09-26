<?php
// Real inventory value model, not a claim about rendered Minecraft UI.
test('Inventory supports nonzero signed request IDs and preserves validated negative prediction aliases',function(){
    $i=new \mpe\inventory\PlayerInventory();
    $before=$i->get(0);$changed=$i->request(21,[['type'=>'move','source'=>[28,0,$before->networkId],'destination'=>[59,0,0],'count'=>10]],true);
    same($i->get(0)->count,54);same($i->cursor()->count,10);
    $i->request(22,[['type'=>'move','source'=>[59,0,-21],'destination'=>[29,12,0],'count'=>10]],true);
    same($i->get(12)->count,10);same($i->cursor()->count,0);
    rejects(fn()=>$i->request(22,[['type'=>'destroy','source'=>[29,12,$i->get(12)->networkId],'count'=>1]],true));
    same($i->get(12)->count,10);
});
test('Inventory stale predictions cannot spend an unrelated stack or manufacture output',function(){
    $i=new \mpe\inventory\PlayerInventory();
    rejects(fn()=>$i->request(-101,[['type'=>'move','source'=>[59,0,-100],'destination'=>[29,12,0],'count'=>1]],true));
    same($i->get(12)->count,0);
    rejects(fn()=>$i->request(0,[['type'=>'creative','block'=>1]],true));
});
