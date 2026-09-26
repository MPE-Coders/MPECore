'use strict'
/** Explicit changes verified against the installed 1001 and 2193 packet schemas. */
const ITEM_PACKETS=new Set(['inventory_content','inventory_slot','mob_equipment','inventory_transaction','player_auth_input'])
function itemIds(value,from,to,depth=0){
  if(depth>64)throw Error('Item data nesting limit')
  if(value===null||typeof value!=='object'||Buffer.isBuffer(value))return value
  if(Array.isArray(value))return value.map(v=>itemIds(v,from,to,depth+1))
  const copy={...value}
  if(Number.isInteger(copy.network_id)&&'has_stack_id' in copy&&copy.has_stack_id){
    const original=copy.stack_id
    if(from===1001&&to===2193){
      const id=original?.id
      if(!Number.isInteger(id)||id< -2147483648||id>2147483647)throw Error('Invalid typed item stack ID')
      const expected=id>=0?'item_stack_net_id':id%2!==0?'item_stack_request_id':'item_stack_legacy_request_id'
      if(original.type!==expected)throw Error('Inconsistent typed item stack ID')
      copy.stack_id=id
    }else if(from===2193&&to===1001){
      const id=original
      if(!Number.isInteger(id)||id< -2147483648||id>2147483647)throw Error('Invalid item stack ID')
      copy.stack_id={type:id>=0?'item_stack_net_id':id%2!==0?'item_stack_request_id':'item_stack_legacy_request_id',id}
    }
  }
  for(const [key,child] of Object.entries(copy)){
    if(key!=='stack_id')copy[key]=itemIds(child,from,to,depth+1)
  }
  return copy
}
function adaptFields(name,p,from,to){
  if(ITEM_PACKETS.has(name)&&((from===1001&&to===2193)||(from===2193&&to===1001))){
    for(const key of Object.keys(p))p[key]=itemIds(p[key],from,to)
  }
  if(name==='start_game'){
    // 2193 REMOVES this 1001 field. Our server opts out of chat telemetry.
    // Reconstruct only that explicit false value when validating through 1001.
    if(to===1001)p.is_chat_logging=false
    if(from===1001&&to===2193){
      if(p.is_chat_logging===true)throw Error('2193 cannot represent the old chat logging flag')
      delete p.is_chat_logging
    }
  }
  if(name==='level_chunk' && (from===2193||to===2193)){
    // 2193 writes the blob list unconditionally, even with cache disabled.
    // MPE currently sends full chunks, not client-cache references.
    if(p.cache_enabled || (p.blobs&&p.blobs.length))throw Error('Cached chunk blobs are not implemented')
    p.blobs=[]
  }
}
module.exports={adaptFields,itemIds}
