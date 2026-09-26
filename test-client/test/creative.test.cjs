'use strict'
const { test } = require('node:test'), assert = require('node:assert/strict')
const { useItem, playerInventory } = require('../lib/creative.cjs')
const { parseArgs } = require('../lib/args.cjs')
test('Creative scenario is an explicit CLI mode without operator commands', () => {
  assert.equal(parseArgs(['127.0.0.1','19132','--scenario','creative']).scenario, 'creative')
})
test('Ordinary use-item builder preserves version-specific authoritative held item', () => {
  const held = { network_id: -42, block_runtime_id: 999, count: 64, metadata: 0 }
  const packet = useItem('click_block', {x:2,y:63,z:0}, held, [0.5,64,0.5], 1234)
  assert.equal(packet.transaction.transaction_data.held_item, held)
  assert.equal(packet.transaction.transaction_data.block_runtime_id, 1234)
  assert.equal(packet.transaction.transaction_type, 'item_use')
  assert.equal(packet.transaction.legacy.legacy_request_id, 0)
  assert.equal(packet.transaction.transaction_data.hotbar_slot, 0)
  assert.equal(packet.transaction.transaction_data.face, 1)
})
test('Use-item builder rejects nonfinite coordinates and unsupported actions', () => {
  assert.throws(() => useItem('click_air', {x:2,y:63,z:0}, {}, [0.5,64,0.5], 1))
  assert.throws(() => useItem('click_block', {x:Infinity,y:63,z:0}, {}, [0.5,64,0.5], 1))
})
test('Inventory probe does not mistake another window for a playable hotbar', () => {
  assert.equal(playerInventory({window_id:'inventory',input:Array(36).fill({})}), true)
  assert.equal(playerInventory({window_id:0,input:Array(36).fill({})}), true)
  assert.equal(playerInventory({window_id:'ui',input:Array(36).fill({})}), false)
  assert.equal(playerInventory({window_id:'inventory',input:[]}), false)
})

const {adapt,ALLOWED}=require('../../codec/adapters.cjs')
test('Modern adapter preserves ordinary block input and derives payload presence flags',()=>{
  const tx={legacy:{legacy_request_id:0},actions:[],data:{action_type:'click_block'}}
  const p=adapt('player_auth_input',{input_data:['start_flying'],transaction:tx,block_action:[{action:'predict_break',position:{x:1,y:63,z:0},face:1}]},2193,1001)
  assert.equal(p.input_data.start_flying,true);assert.equal(p.input_data.item_interact,true);assert.equal(p.input_data.block_action,true)
  assert.equal(p.transaction.data.action_type,'click_block');assert.equal(p.block_action[0].position.y,63)
  const round=adapt('player_auth_input',p,1001,2193);assert(round.input_data.includes('block_action'));assert.equal(round.transaction.data.action_type,'click_block')
})
test('Modern adapter rejects nonempty legacy deltas, unknown actions and missing flag payloads',()=>{
  assert.throws(()=>adapt('player_auth_input',{input_data:['block_action']},2193,1001),/Missing/)
  assert.throws(()=>adapt('player_auth_input',{input_data:[],block_action:[{action:'vehicle'}]},2193,1001),/Unsupported/)
  assert.throws(()=>adapt('player_auth_input',{input_data:[],transaction:{legacy:{legacy_request_id:0},actions:[{}],data:{}}},2193,1001),/legacy/)
})
test('Gameplay schema boundary includes inventory and command packets, not arbitrary packets',()=>{
  for(const name of ['inventory_content','inventory_slot','inventory_transaction','item_stack_request','item_stack_response','mob_equipment','container_open','interact','available_commands'])assert(ALLOWED.has(name))
  assert(!ALLOWED.has('structure_template_data_request'))
})
