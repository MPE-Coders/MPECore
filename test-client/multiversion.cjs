#!/usr/bin/env node
'use strict'
// Real simultaneous clients of DIFFERENT versions on ONE server. Local offline test only.
const assert=require('node:assert/strict'), fs=require('node:fs'),path=require('node:path')
const {Client}=require('bedrock-protocol')
const {Inbox}=require('./lib/events.cjs')
const {packReply,command}=require('./lib/packets.cjs')
const {useItem,playerInventory}=require('./lib/creative.cjs')
const {attachRaknet11}=require('./lib/raknet.cjs')
const {loadPalette}=require('./lib/palette.cjs')
const ROOT=path.resolve(__dirname,'..')
const sleep=ms=>new Promise(r=>setTimeout(r,ms))
async function run(host,port){
  if(host!=='127.0.0.1')throw Error('Multiversion mutation test is loopback-only')
  const peers=[];let finished=false
  try{
    for(const [version,protocol] of [['1.26.20',975],['1.26.30',1001],['1.26.51',2193]]){
      const c=new Client({host,port,version,username:`Multi_${protocol}`,offline:true,delayedInit:true,autoInitPlayer:true,
        transport:'raknet',raknetBackend:'jsp-raknet',useRaknetWorkers:false,connectTimeout:15000,conLog:()=>{}})
      const inbox=new Inbox();peers.push({c,inbox,version,protocol})
      const profile=require(`${ROOT}/resources/protocols/${protocol}.json`)
      const file=protocol===2193?`${ROOT}/.runtime/bedrock/2193/${profile.block_palette}`:`${ROOT}/vendor/nethergamesmc/bedrock-data/${profile.block_palette}`
      const peer=peers.at(-1);peer.palette=loadPalette(file)
      c.on('error',e=>inbox.fail(e));c.on('kick',p=>inbox.fail(Error(p.message||'kick')))
      c.on('close',()=>{if(!finished)inbox.fail(Error('Premature close'))})
      for(const e of ['start_game','spawn','level_chunk','inventory_content','update_block','text'])c.on(e,p=>inbox.put(e,p||{}))
      c.on('resource_packs_info',()=>c.queue('resource_pack_client_response',packReply('have_all_packs')))
      c.on('resource_pack_stack',()=>{c.queue('resource_pack_client_response',packReply('completed'));c.queue('client_cache_status',{enabled:false})})
      c.on('start_game',()=>c.queue('request_chunk_radius',{chunk_radius:2,max_radius:2}))
      c.once('connect_allowed',()=>{attachRaknet11(c);c.connect()})
      const ready=Promise.all([inbox.expect('spawn',()=>true,20000),inbox.expect('level_chunk',p=>p.x===0&&p.z===0,20000),inbox.expect('inventory_content',playerInventory,20000)])
      c.init();const result=await ready;peer.held=result[2].input[0]
    }
    await sleep(300)
    const target={x:4,y:64,z:0},clicked={x:4,y:63,z:0}
    const changes=peers.map(p=>p.inbox.expect('update_block',q=>q.position.x===4&&q.position.y===64&&q.position.z===0&&q.block_runtime_id===p.palette.grass,10000))
    peers[2].c.queue('inventory_transaction',useItem('click_block',clicked,peers[2].held,[0.5,64,0.5],peers[2].palette.grass))
    await Promise.all(changes)
    // A second protocol removes the first one's block; every session uses its OWN palette.
    const removals=peers.map(p=>p.inbox.expect('update_block',q=>q.position.x===4&&q.position.y===64&&q.position.z===0&&q.block_runtime_id===p.palette.air,10000))
    peers[0].c.queue('inventory_transaction',useItem('break_block',target,peers[0].held,[0.5,64,0.5],peers[0].palette.grass))
    await Promise.all(removals)
    assert(new Set(peers.map(p=>p.palette.grass)).size>1,'This must exercise different palette IDs')
    const report={success:true,scope:'simultaneous encrypted offline clients; not official-client graphics or player avatars',protocols:peers.map(p=>p.protocol),grassIds:peers.map(p=>p.palette.grass),crossProtocolPlacement:true,crossProtocolBreak:true}
    console.log('PASS multiversion same port: '+JSON.stringify(report))
    return report
  }finally{finished=true;for(const p of peers){p.c.close();p.inbox.fail(Error('Test finished'))}}
}
run(process.argv[2]||'127.0.0.1',Number(process.argv[3]||19132)).catch(e=>{console.error(e);process.exitCode=1})
