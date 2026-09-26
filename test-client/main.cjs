#!/usr/bin/env node
'use strict'
const fs=require('node:fs'),path=require('node:path')
const {parseArgs,catalog}=require('./lib/args.cjs')
const {ping}=require('./lib/ping.cjs'),{Inbox}=require('./lib/events.cjs')
const {packReply}=require('./lib/packets.cjs'),{scenario}=require('./lib/scenario.cjs')
const ROOT=path.resolve(__dirname,'..')
async function main(){
  const o=parseArgs(process.argv.slice(2))
  if(o.help){console.log(`MPE test client 0.4.0
./client HOST PORT [--version VERSION|PROTOCOL] [--offline]
  --scenario connect|smoke|creative|edit   Default: smoke. creative uses real block actions; edit requires operator/test-mode.
  --chat TEXT --username NAME --timeout MILLISECONDS --report FILE
  --palette FILE                Independently decode version-specific network NBT.
  --profiles-folder DIR         Microsoft sign-in cache (online mode).
  --ping-only                   Discovery only: NOT a login test.
  --list-protocols
Default is online sign-in. Offline must be explicitly enabled on the TEST server.
No Microsoft password is requested by this program; follow the dependency's device-code flow.`);return}
  if(o['list-protocols']){for(const p of catalog)console.log(`${p.protocol}\t${p.version}\t${p.codec}\tserver:${p.server_status}`);return}
  const report={tool:'mpe-client',version:'0.4.0',started:new Date().toISOString(),target:{host:o.host,port:o.port},scenario:o.scenario,authentication:o.offline?'offline':'online',checks:[],success:false}
  let client,inbox,finishing=false
  const log=s=>console.log(`[client] ${s}`);o.log=log
  try{
    const discovery=await ping(o.host,o.port,Math.min(5000,o.timeout));report.discovery=discovery
    if(o['ping-only']){report.success=true;report.scope='raknet-discovery-only';log(`PONG ${discovery.version} protocol=${discovery.protocol}`);return}
    const profile=o.version==='auto'?catalog.find(p=>p.protocol===discovery.protocol):catalog.find(p=>p.version===o.version)
    if(!profile)throw Error(`Advertised protocol ${discovery.protocol} is not in the catalog; choose --version explicitly`)
    o.version=profile.version;o.protocol=profile.protocol;report.protocol=profile;log(`Connecting with ${o.version}/${o.protocol}, ${o.offline?'offline':'online'} identity`)
    if(!o.palette){
      const p=require(`../resources/protocols/${o.protocol}.json`),asset=path.join(ROOT,'vendor/nethergamesmc/bedrock-data',p.block_palette)
      if(p.data_status==='explicit-nethergames-aliases' && fs.existsSync(asset))o.palette=asset
      if(o.protocol===2193){const modern=path.join(ROOT,'.runtime/bedrock/2193',p.block_palette);if(fs.existsSync(modern))o.palette=modern}
    }
    if(o.palette)o.paletteData=require('./lib/palette.cjs').loadPalette(o.palette)
    else log('No local NBT palette: grass assertion uses server-reported mapping, not independent palette validation')
    if(Number(process.versions.node.split('.')[0])<24)throw Error('Real protocol client requires Node.js >=24 (upstream bedrock-protocol requirement)')
    const {Client}=require('bedrock-protocol')
    inbox=new Inbox()
    client=new Client({host:o.host,port:o.port,version:o.version,username:o.username,offline:o.offline,
      profilesFolder:o['profiles-folder']||path.join(ROOT,'.client-auth'),delayedInit:true,autoInitPlayer:true,
      transport:'raknet',raknetBackend:'jsp-raknet',useRaknetWorkers:false,connectTimeout:o.timeout,conLog:log})
    const fatal=e=>{if(!finishing)inbox.fail(e instanceof Error?e:Error(String(e)))}
    client.on('error',fatal);client.on('close',()=>fatal(Error('Connection closed before scenario completed')))
    client.on('kick',p=>fatal(Error(`Kicked: ${p.message||'no reason'}`)))
    for(const name of ['start_game','spawn','level_chunk','text','update_block','move_player','inventory_content','creative_content','item_stack_response','container_open','container_close'])client.on(name,p=>inbox.put(name,p||{}))
    client.on('resource_packs_info',p=>{
      try{
        if((p.texture_packs?.length||p.behavior_packs?.length))throw Error('This smoke client does not download resource packs')
        client.queue('resource_pack_client_response',packReply('have_all_packs'))
      }catch(e){fatal(e)}
    })
    client.on('resource_pack_stack',()=>{
      try{client.queue('resource_pack_client_response',packReply('completed'));client.queue('client_cache_status',{enabled:false})}catch(e){fatal(e)}
    })
    client.on('start_game',()=>{try{client.queue('request_chunk_radius',{chunk_radius:2,max_radius:2})}catch(e){fatal(e)}})
    client.on('network_stack_latency',p=>{if(p.needs_response)client.queue('network_stack_latency',{timestamp:p.timestamp,needs_response:false})})
    client.once('connect_allowed',()=>{require('./lib/raknet.cjs').attachRaknet11(client);client.connect()})
    const result=scenario(client,inbox,o,report)
    result.catch(()=>{});client.init();await result
    report.success=true;log('Scenario completed with received evidence')
  }catch(error){report.error=error.message;console.error(`[client] FAIL: ${error.message}`);process.exitCode=1}
  finally{
    finishing=true;client?.close();inbox?.fail(Error('Scenario finished'));report.finished=new Date().toISOString()
    const dest=path.resolve(o.report);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,JSON.stringify(report,null,2)+'\n');log(`Report: ${dest}`)
  }
}
main().catch(e=>{console.error(e.message);process.exitCode=2})
