'use strict'
const crypto=require('node:crypto')
function text(message,username){return {type:'chat',category:'authored',needs_translation:false,source_name:username,message,parameters:[],xuid:'',platform_chat_id:'',has_filtered_message:false,filtered_message:''}}
function command(line){return {command:line,origin:{type:'player',uuid:crypto.randomUUID(),request_id:'',player_entity_id:0n},internal:false,version:'52'}}
function motion(position,tick,modern=false,delta={x:0,y:0,z:0}){
  return {pitch:0,yaw:0,position,move_vector:{x:delta.x?1:0,z:0},head_yaw:0,
    input_data:modern?['vertical_collision']:{vertical_collision:true},input_mode:'mouse',play_mode:'normal',interaction_model:'crosshair',
    interact_rotation:{x:0,z:0},tick:BigInt(tick),delta,transaction:undefined,item_stack_request:undefined,block_action:undefined,
    vehicle_rotation:undefined,predicted_vehicle:undefined,analogue_move_vector:{x:delta.x?1:0,z:0},camera_orientation:{x:0,y:0,z:1},raw_move_vector:{x:delta.x?1:0,z:0}}
}
function packReply(status){return {response_status:status,response_status_name:status==='completed'?'resourcepackstackfinished':'resourcepackhaveallpacks',resourcepackids:[]}}
module.exports={text,command,motion,packReply}
