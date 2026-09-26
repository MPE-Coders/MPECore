'use strict'
/** Explicit fields added/renamed in the 26.50 protocol. The server opts out of chat telemetry. */
function adaptFields(name,p,from,to){
  if(name==='start_game'){
    p.is_chat_logging??=p.is_logging_chat??false
    p.is_logging_chat??=p.is_chat_logging
  }
}
module.exports={adaptFields}
