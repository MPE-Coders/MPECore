'use strict'
const assert=require('node:assert/strict')
const ref=(container_id,slot,stack_id)=>({slot_type:{container_id,dynamic_container_id:undefined},slot,stack_id})
const request=(id,actions)=>({requests:[{request_id:id,actions,custom_names:[],cause:'chat_public'}]})
/** Ordinary mouse-like predicted transfers. Never uses slash commands to edit inventory. */
async function inventoryScenario(client,inbox,o,content,check){
  const original=content.input[0]
  const id=original.stack_id
  const originalId=Number.isInteger(id)?id:(id?.type==='item_stack_net_id'?id.id:undefined)
  assert(Number.isInteger(originalId)&&originalId>0,'Server must assign a stack ID')
  const wait=async (id,status)=>{
    const p=await inbox.expect('item_stack_response',p=>p.responses?.some(r=>r.request_id===id&&(!status||r.status===status)),o.timeout)
    return p.responses.find(r=>r.request_id===id&&(!status||r.status===status))
  }
  const slot=(r,c,s)=>{
    const found=r.containers?.find(x=>x.slot_type.container_id===c)?.slots.find(x=>x.slot===s)
    assert(found,`Missing matching response namespace ${c}/${s}`);return found
  }
  // Send the second request before awaiting the first: the official UI predicts
  // cursor moves instead of serializing each mouse click behind a network RTT.
  client.queue('item_stack_request',request(-401,[{type_id:'take',count:10,source:ref('hotbar',0,originalId),destination:ref('cursor',0,0)}]))
  client.queue('item_stack_request',request(-402,[{type_id:'place',count:10,source:ref('cursor',0,-401),destination:ref('inventory',12,0)}]))
  const a=await wait(-401),b=await wait(-402)
  assert.equal(a.status,'ok');assert.equal(b.status,'ok')
  const hotbar=slot(a,'hotbar',0),bag=slot(b,'inventory',12)
  assert.equal(hotbar.count,54);assert.equal(bag.count,10);assert.equal(slot(b,'cursor',0).count,0)
  client.queue('item_stack_request',request(-403,[{type_id:'place',count:10,source:ref('inventory',12,bag.item_stack_id),destination:ref('hotbar',0,hotbar.item_stack_id)}]))
  const c=await wait(-403);assert.equal(c.status,'ok');assert.equal(slot(c,'hotbar',0).count,64);assert.equal(slot(c,'inventory',12).count,0)
  // Real wire replay/overdraw rejection. A correct reply is ERROR, never a duplicate item.
  client.queue('item_stack_request',request(-403,[{type_id:'destroy',count:1,source:ref('hotbar',0,slot(c,'hotbar',0).item_stack_id)}]))
  assert.equal((await wait(-403,'error')).status,'error')
  check('inventory-predicted-cursor-transfers',{requests:4,restoredCount:64,matchingContainers:true,replayRejected:true})
}
module.exports={inventoryScenario,ref,request}
