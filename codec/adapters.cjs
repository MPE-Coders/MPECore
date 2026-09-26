'use strict'
/** Explicit, limited protocol adaptation for the packets this milestone actually implements.
 * Unsupported crafting/vehicle semantics are rejected, not guessed or translated to a nearby version.
 */
const ALLOWED=new Set(['request_network_settings','network_settings','login','play_status','server_to_client_handshake',
  'client_to_server_handshake','disconnect','resource_packs_info','resource_pack_stack','resource_pack_client_response',
  'start_game','item_registry','available_entity_identifiers','biome_definition_list','update_attributes','update_abilities',
  'update_adventure_settings','creative_content','chunk_radius_update','request_chunk_radius','network_chunk_publisher_update',
  'level_chunk','set_local_player_as_initialized','client_cache_status','network_stack_latency','player_auth_input',
  'move_player','text','command_request','update_block','inventory_content','inventory_slot',
  'inventory_transaction','mob_equipment','item_stack_request','item_stack_response','available_commands',
  'player_action','request_ability','container_close','container_open','interact',
  'correct_player_move_prediction','packet_violation_warning'])
const MOTION_FLAGS=new Set(['up','down','left','right','vertical_collision','horizontal_collision','handled_teleport',
  'received_server_data','jumping','jump_down','start_jumping','sprinting','sprint_down','start_sprinting','stop_sprinting',
  'sneaking','sneak_down','start_sneaking','stop_sneaking','persist_sneak','sneak_toggle_down',
  'jump_released_raw','jump_pressed_raw','jump_current_raw','sneak_released_raw','sneak_pressed_raw','sneak_current_raw',
  'ascend','descend','north_jump','change_height','auto_jumping_in_water','up_left','up_right','down_left','down_right',
  'want_up','want_down','want_down_slow','want_up_slow','ascend_block','descend_block',
  'start_flying','stop_flying','block_breaking_delay_enabled','hotbar_only_touch','camera_relative_movement_enabled',
  'rot_controlled_by_move_direction','missed_swing','start_using_item','item_interact','block_action','item_stack_request'])
function adapt(name,original,from,to){
  if(!ALLOWED.has(name))throw Error(`No audited adapter for ${name}`)
  const p={...original}
  if(name==='resource_pack_client_response'){
    p.resourcepackids??=[]
    p.response_status_name??=p.response_status==='completed'?'resourcepackstackfinished':'resourcepackhaveallpacks'
  }
  if(name==='text'){
    p.category??=(['chat','whisper','announcement'].includes(p.type)?'authored':['translation','popup','jukebox_popup'].includes(p.type)?'parameters':'message_only')
    p.has_filtered_message??=Boolean(p.filtered_message);p.filtered_message??='';p.platform_chat_id??='';p.xuid??=''
  }
  if(name==='start_game'){
    p.server_editor_connection_policy??=0
    p.allow_anonymous_block_drops_in_editor_worlds??=false
    p.has_server_join_info??=false
    // This server does NOT use runtime hashes. The actual version-specific palette index is sent.
    if(p.block_network_ids_are_hashes)throw Error('Hashed block IDs are not implemented')
  }
  if(name==='player_auth_input' && (from>=2168 || to>=2168)){
    const flags=Array.isArray(p.input_data)?p.input_data:Object.keys(p.input_data||{}).filter(k=>p.input_data[k]===true)
    if(flags.some(f=>!MOTION_FLAGS.has(f)))throw Error(`Unsupported input semantics in modern adapter: ${flags.filter(f=>!MOTION_FLAGS.has(f)).join(', ')}`)
    if(p.predicted_vehicle!=null||p.vehicle_rotation!=null)throw Error('Modern vehicle actions are not implemented')
    // >=2168 has option tags independent of flags; base1001 conditionally encodes fields by flags.
    // Derive the base presence bits from actual payloads, so no supplied action is silently dropped.
    const present=new Set(flags)
    for(const [field,flag] of [['transaction','item_interact'],['item_stack_request','item_stack_request'],['block_action','block_action']]){
      if(p[field]!=null)present.add(flag)
      else if(present.has(flag))throw Error(`Missing ${field} payload for ${flag}`)
    }
    if(p.transaction){
      // In creative the inventory delta belongs to ItemStackRequest, not legacy InventoryActions.
      // The two schema families encode legacy action sources differently; refuse nonempty ones.
      if(p.transaction.legacy?.legacy_request_id!==0||!Array.isArray(p.transaction.actions)||p.transaction.actions.length!==0||!p.transaction.data)
        throw Error('Modern auth-input legacy inventory deltas are not implemented')
      p.transaction={...p.transaction,legacy:{legacy_request_id:0,legacy_transactions:undefined}}
    }
    if(p.block_action){
      if(!Array.isArray(p.block_action)||p.block_action.length>64)throw Error('Invalid block action list')
      const positioned=new Set(['start_break','abort_break','crack_break','predict_break','continue_break'])
      p.block_action=p.block_action.map(a=>{
        if(!positioned.has(a.action)||!a.position||![a.position.x,a.position.y,a.position.z,a.face].every(Number.isInteger))
          throw Error(`Unsupported modern block action ${a.action}`)
        return {...a,position:{...a.position}}
      })
    }
    p.input_data=to>=2168?[...present]:Object.fromEntries([...present].map(f=>[f,true]))
    p.vehicle_rotation=undefined;p.predicted_vehicle=undefined
  }
  return p
}
// Stop before a newly added required top-level field is silently serialized as false/zero.
function assertTopLevel(name,params,types){
  let shape=types['packet_'+name]
  if(!Array.isArray(shape)||shape[0]!=='container')throw Error(`Unknown packet schema ${name}`)
  for(const f of shape[1]){
    if(f.anon||!f.name)continue
    let type=f.type,seen=new Set()
    while(typeof type==='string' && types[type] && !seen.has(type)){seen.add(type);type=types[type]}
    if(type==='void'||(Array.isArray(type)&&['switch','option','optionalOnRemaining'].includes(type[0])))continue
    if(params[f.name]===undefined)throw Error(`Missing required field ${name}.${f.name}; add an explicit version adapter`)
  }
}
module.exports={ALLOWED,adapt,assertTopLevel}
