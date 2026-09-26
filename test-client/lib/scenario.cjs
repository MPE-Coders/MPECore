'use strict'
const assert=require('node:assert/strict'),crypto=require('node:crypto')
const {text,command,motion}=require('./packets.cjs')
const {decodeColumn,blockAt}=require('./chunks.cjs')
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms))
function jsonMessage(value,prefix,nonce){
  if(typeof value.message!=='string'||!value.message.startsWith(prefix))return false
  try{return JSON.parse(value.message.slice(prefix.length)).nonce===nonce}catch{return false}
}
async function scenario(client,inbox,o,report){
  const check=(name,evidence)=>{report.checks.push({name,passed:true,evidence});o.log?.(`PASS ${name}`)}
  const start=await inbox.expect('start_game',()=>true,o.timeout)
  check('start-game',{runtime:String(start.runtime_entity_id),protocol:o.protocol})
  await inbox.expect('spawn',()=>true,o.timeout);check('spawn-and-initialization',{})
  const chunk=await inbox.expect('level_chunk',p=>p.sub_chunk_count>0&&Buffer.isBuffer(p.payload),o.timeout)
  const decoded=decodeColumn(chunk.payload,chunk.sub_chunk_count)
  check('chunk-storage-decoded',{x:chunk.x,z:chunk.z,sections:decoded.sections.size})
  if(o.scenario==='connect')return
  // Wait for initialization to reach the server before issuing game commands.
  await sleep(150)
  const nonce=()=>crypto.randomBytes(10).toString('hex')
  const probe=async(x=0,y=63,z=0)=>{
    await sleep(160);const n=nonce();client.queue('command_request',command(`/mpe probe ${n} ${x} ${y} ${z}`))
    const p=await inbox.expect('text',v=>jsonMessage(v,'MPE-PROBE ',n),o.timeout)
    return JSON.parse(p.message.slice('MPE-PROBE '.length))
  }
  const baseline=await probe()
  assert.equal(baseline.block.id,1,'Spawn terrain must have canonical grass at Y=63')
  const surfacePacket=await inbox.expect('level_chunk',p=>p.x===0&&p.z===0&&p.sub_chunk_count>=8,o.timeout)
  const surface=decodeColumn(surfacePacket.payload,surfacePacket.sub_chunk_count)
  const expected=o.paletteData||{air:baseline.air_runtime,grass:baseline.grass_runtime}
  if(o.paletteData)assert.equal(baseline.palette_sha256,o.paletteData.sha256,'Server and client loaded different palette bytes')
  assert.equal(blockAt(surface,0,63,0),expected.grass,'Y=63 must be the expected grass runtime ID')
  // Y=59 is in transmitted section 3. Initial chunks can omit all-air section 4 above the ground.
  assert.equal(blockAt(surface,0,59,0),expected.air,'Y=59 must be air in this four-layer flat generator')
  check('grass-runtime-and-nbt',{runtime:expected.grass,verification:o.paletteData?'independent-prismarine-nbt':'server-reported-map',sha256:baseline.palette_sha256})
  const chatToken=`${o.chat} ${nonce()}`;client.queue('text',text(chatToken,o.username))
  await inbox.expect('text',p=>p.message===`<${o.displayName||o.username}> ${chatToken}`||p.message?.endsWith(`> ${chatToken}`),o.timeout)
  check('chat-echo',{matched:true})
  const p=baseline.position;assert(p.every(Number.isFinite),'Probe position must be finite')
  let next={x:p[0],y:p[1]+1.62,z:p[2]}
  // 20 real input ticks, not an instantaneous multi-block teleport.
  for(let i=1;i<=20;i++){
    next={...next,x:p[0]+i*0.05};client.queue('player_auth_input',motion(next,i,o.protocol>=2168,{x:0.05,y:0,z:0}));await sleep(50)
  }
  const moved=await probe()
  assert(Math.abs(moved.position[0]-p[0]-1)<0.15,'Rust did not accept expected movement')
  assert(moved.tick>baseline.tick,'Simulation tick did not advance')
  check('authoritative-movement',{before:p,after:moved.position,tick:moved.tick})
  if(o.scenario==='creative'){await require('./creative.cjs').creativeScenario(client,inbox,o,moved,probe,check,start);return}
  if(o.scenario!=='edit')return
  const x=Math.floor(moved.position[0])+3,y=64,z=Math.floor(moved.position[2])+3
  const before=await probe(x,y,z)
  const blocks=require('../../resources/blocks.json'),oldName=blocks.find(b=>b.id===before.block.id)?.name
  assert(oldName,'Cannot restore unknown original canonical block')
  async function edit(name){
    await sleep(160);const n=nonce();client.queue('command_request',command(`/setblock ${x} ${y} ${z} ${name} ${n}`))
    const response=await inbox.expect('text',p=>jsonMessage(p,'MPE-EDIT ',n),o.timeout)
    return JSON.parse(response.message.slice('MPE-EDIT '.length))
  }
  let dirty=false
  try{
    // Mark dirty before waiting for ACK: a timeout does not prove the edit was not committed.
    dirty=true;await edit('diamond_block');const after=await probe(x,y,z);assert.equal(after.block.id,7)
    await inbox.expect('update_block',p=>p.position?.x===x&&p.position?.y===y&&p.position?.z===z&&p.block_runtime_id===after.block.runtime,o.timeout)
    check('durable-edit-and-update-block',{position:[x,y,z],canonical:7,runtime:after.block.runtime})
  }finally{
    if(dirty){await edit(oldName);const restored=await probe(x,y,z);assert.equal(restored.block.id,before.block.id);check('restore-edited-block',{canonical:before.block.id})}
  }
}
module.exports={scenario,jsonMessage}
