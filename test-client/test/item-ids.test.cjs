'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {itemIds}=require('../../codec/fields-2193.cjs')
const item=id=>({network_id:1,count:64,metadata:0,has_stack_id:true,stack_id:id,block_runtime_id:42})
test('2193 bridge preserves typed 1001 server stack IDs instead of coercing an object to zero',()=>{
  const original={input:[item({type:'item_stack_net_id',id:17})]}
  const forward=itemIds(original,1001,2193)
  assert.equal(forward.input[0].stack_id,17)
  assert.deepEqual(itemIds(forward,2193,1001),original)
  assert.equal(original.input[0].stack_id.id,17)
})
test('Signed predicted item IDs retain their request kind; malformed tags are rejected',()=>{
  for(const id of [-401,-402,0,2147483647])assert.deepEqual(itemIds(itemIds(item(id),2193,1001),1001,2193),item(id))
  assert.throws(()=>itemIds(item({type:'item_stack_request_id',id:17}),1001,2193))
  assert.throws(()=>itemIds(item({type:'item_stack_net_id',id:NaN}),1001,2193))
  assert.throws(()=>itemIds(item(2147483648),2193,1001))
})
test('Slot references are not ItemV4 and must keep their integer stack ID',()=>{
  const ref={slot_type:{container_id:'hotbar'},slot:0,stack_id:-401}
  assert.deepEqual(itemIds(ref,2193,1001),ref)
})
