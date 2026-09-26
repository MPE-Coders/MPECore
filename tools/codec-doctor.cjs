#!/usr/bin/env node
'use strict'
/** Requires the REAL installed dependency codecs. This test is not a client login. */
const fs=require('node:fs'),path=require('node:path')
const {text,command,motion,packReply}=require('../test-client/lib/packets.cjs')
const {useItem}=require('../test-client/lib/creative.cjs')
const catalog=require('../resources/protocol-catalog.json')
const {createSerializer,createDeserializer}=require('bedrock-protocol/src/transforms/serializer')
const only=process.argv.find(a=>a.startsWith('--protocol='))?.split('=')[1]
const out=process.argv.find(a=>a.startsWith('--out='))?.slice(6)
const fixtures=[];let packets=0
try{
  for(const p of catalog.filter(p=>!only||p.protocol===Number(only))){
    const data=require('minecraft-data')('bedrock_'+p.version)
    if(!data?.protocol?.types)throw Error(`Missing exact ${p.version} schema`)
    const serializer=createSerializer(p.version),deserializer=createDeserializer(p.version)
    const cases=[['request_network_settings',{client_protocol:p.protocol}],
      ['resource_pack_client_response',packReply('completed')],['text',text('CODEC-PROBE','UnitPlayer')],
      ['request_chunk_radius',{chunk_radius:2,max_radius:2}],['set_local_player_as_initialized',{runtime_entity_id:42n}],
      ['inventory_transaction',useItem('break_block',{x:2,y:64,z:0},{network_id:0,count:0,metadata:0,has_stack_id:false,block_runtime_id:0,extra:{has_nbt:'false',can_place_on:[],can_destroy:[]}},[0.5,64,0.5],1)],['command_request',command('/pos')],['player_auth_input',motion({x:0.75,y:65.62,z:0.5},1,p.protocol>=2168)]]
    for(const [name,params] of cases){
      const b=serializer.createPacketBuffer({name,params}),decoded=deserializer.parsePacketBuffer(b).data
      if(decoded.name!==name||!serializer.createPacketBuffer(decoded).equals(b))throw Error(`Codec roundtrip mismatch ${p.protocol}/${name}`)
      fixtures.push({protocol:p.protocol,version:p.version,name,data:b.toString('base64')});packets++
    }
    console.log(`PASS real Prismarine schema ${p.version}/${p.protocol}`)
  }
  if(!packets)throw Error('No selected profiles')
  if(out){const file=path.resolve(out);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(fixtures,null,2)+'\n')}
  console.log(`${packets} real packet roundtrips. Does NOT prove native client compatibility.`)
}catch(e){console.error('CODEC DOCTOR FAILED: '+e.message);process.exitCode=1}
