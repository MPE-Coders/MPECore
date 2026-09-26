'use strict'
const assert = require('node:assert/strict')
/** Build a real use-item transaction from the item the SERVER supplied. No invented item/runtime IDs. */
function useItem(action, clicked, held, feet, runtime, slot = 0) {
  if (!['click_block', 'break_block'].includes(action)) throw Error('Unsupported test action')
  if (![clicked.x, clicked.y, clicked.z, ...feet].every(Number.isFinite)) throw Error('Nonfinite action position')
  if (!held || typeof held !== 'object' || !Number.isInteger(runtime) || runtime < 0) throw Error('Missing authoritative item/palette')
  return { transaction: {
    legacy: { legacy_request_id: 0, legacy_transactions: undefined },
    transaction_type: 'item_use', actions: [],
    transaction_data: { action_type: action, trigger_type: 'player_input', block_position: clicked,
      face: 1, hotbar_slot: slot, held_item: held,
      player_pos: { x: feet[0], y: feet[1], z: feet[2] }, click_pos: { x: 0.5, y: 1, z: 0.5 },
      block_runtime_id: runtime, client_prediction: 'success', client_cooldown_state: 'off' }
  } }
}
function playerInventory(packet) {
  return ['inventory', 0].includes(packet.window_id) && Array.isArray(packet.input) && packet.input.length === 36
}
/** Mutates an initially empty cell. /mpe probe is observation only, never the mutation path. */
async function creativeScenario(client, inbox, options, state, probe, check, start) {
  assert.equal(start.player_gamemode, 'creative', 'Server did not put this player in Creative')
  const content = await inbox.expect('inventory_content', playerInventory, options.timeout)
  const held = content.input[0]
  assert(held && Number.isInteger(held.network_id) && held.network_id !== 0, 'First hotbar slot is empty')
  check('creative-mode-and-hotbar', { slots: content.input.length, heldItemSource: 'server InventoryContent' })
  // Exercise the client-requested inventory window handshake too; no GUI is rendered here.
  client.queue('interact', { action_id: 'open_inventory', target_entity_id: start.runtime_entity_id, has_position: false })
  const opened = await inbox.expect('container_open', p => BigInt(p.runtime_entity_id) === BigInt(start.runtime_entity_id), options.timeout)
  client.queue('container_close', { window_id: opened.window_id, window_type: opened.window_type, server: false })
  await inbox.expect('container_close', p => p.window_id === opened.window_id, options.timeout)
  check('ordinary-inventory-open-close', { window: opened.window_id, scope: 'packet acknowledgement, not rendered UI' })
  const [px, py, pz] = state.position
  const clicked = { x: Math.floor(px) + 2, y: 63, z: Math.floor(pz) }
  const target = { ...clicked, y: 64 }
  const before = await probe(target.x, target.y, target.z)
  assert.equal(before.block.id, 0, 'Refusing to overwrite an existing block during creative test')
  const support = await probe(clicked.x, clicked.y, clicked.z)
  assert.equal(support.block.id, 1, 'Test placement needs the flat grass support')
  let dirty = false
  const destroy = runtime => client.queue('inventory_transaction', useItem('break_block', target, held, [px, py, pz], runtime))
  try {
    dirty = true
    client.queue('inventory_transaction', useItem('click_block', clicked, held, [px, py, pz], support.block.runtime))
    await inbox.expect('update_block', p => p.position?.x === target.x && p.position?.y === target.y &&
      p.position?.z === target.z && p.block_runtime_id === support.block.runtime, options.timeout)
    const placed = await probe(target.x, target.y, target.z)
    assert.equal(placed.block.id, 1, 'Ordinary item use did not change the Rust world')
    check('ordinary-block-placement', { position: target, canonical: placed.block.id, mutation: 'InventoryTransaction/UseItem', probe: 'read-only' })
    destroy(placed.block.runtime)
    await inbox.expect('update_block', p => p.position?.x === target.x && p.position?.y === target.y &&
      p.position?.z === target.z && p.block_runtime_id === state.air_runtime, options.timeout)
    const broken = await probe(target.x, target.y, target.z)
    assert.equal(broken.block.id, 0, 'Ordinary break did not restore air in the Rust world')
    dirty = false
    check('ordinary-block-break-and-restore', { position: target, canonical: 0, mutation: 'InventoryTransaction/UseItem' })
  } finally {
    if (dirty) {
      // A lost ACK does not mean no edit happened. Best effort; never report unconfirmed cleanup as successful.
      destroy(support.block.runtime)
      const restored = await probe(target.x, target.y, target.z)
      assert.equal(restored.block.id, 0, 'Creative test cell may still be modified')
    }
  }
}
module.exports = { useItem, playerInventory, creativeScenario }
