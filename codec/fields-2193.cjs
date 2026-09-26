'use strict'
/** Explicit changes verified against the installed 1001 and 2193 packet schemas. */
function adaptFields(name,p,from,to){
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
module.exports={adaptFields}
