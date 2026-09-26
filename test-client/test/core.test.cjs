'use strict'
const test=require('node:test'),assert=require('node:assert/strict'),dgram=require('node:dgram')
const path=require('node:path'),{execFileSync}=require('node:child_process')
const {parseArgs,catalog}=require('../lib/args.cjs'),{MAGIC,pingPacket,parsePong,ping}=require('../lib/ping.cjs')
const {Inbox}=require('../lib/events.cjs'),{Reader,readStorage,decodeSections,blockAt}=require('../lib/chunks.cjs')
const {motion}=require('../lib/packets.cjs'),{adapt,assertTopLevel}=require('../../codec/adapters.cjs')
const {scenario,jsonMessage}=require('../lib/scenario.cjs')
function pong(timestamp=123n){const extra=Buffer.from('MCPE;Fixture;1001;1.26.30;0;8;123;Test;Adventure;1;19132;19133;');const b=Buffer.alloc(35+extra.length);b[0]=0x1c;b.writeBigInt64BE(timestamp,1);b.writeBigInt64BE(1n,9);MAGIC.copy(b,17);b.writeUInt16BE(extra.length,33);extra.copy(b,35);return b}
let fixture
function chunk(){fixture??=JSON.parse(execFileSync(process.env.MPE_PHP||'php',[path.join(__dirname,'../../tests/emit_chunks.php')],{encoding:'utf8'}));return {x:0,z:0,sub_chunk_count:fixture.count,payload:Buffer.from(fixture.payload,'base64')}}
test('31 explicit unique protocol profiles; no broad ranges',()=>{assert.equal(catalog.length,31);assert.equal(new Set(catalog.map(p=>p.protocol)).size,31);assert.equal(catalog.at(-1).protocol,2193)})
test('CLI resolves version and protocol and validates port',()=>{assert.equal(parseArgs(['localhost','19132','--version','2193']).version,'1.26.51');assert.throws(()=>parseArgs(['x','65536']));assert.throws(()=>parseArgs(['--version','9999']));assert.throws(()=>parseArgs(['--bogus']));assert.equal(parseArgs([]).offline,false)})
test('RakNet ping and exact pong validation',()=>{assert.equal(pingPacket(123n).length,33);assert.equal(parsePong(pong(),123n).protocol,1001);assert.throws(()=>parsePong(pong(),124n));assert.throws(()=>parsePong(pong().subarray(0,34)));const bad=pong();bad[17]=1;assert.throws(()=>parsePong(bad))})
test('Actual loopback UDP request/reply, fixture server only',async()=>{
  const server=dgram.createSocket('udp4');server.on('message',(b,remote)=>server.send(pong(b.readBigInt64BE(1)),remote.port,remote.address))
  await new Promise(resolve=>server.bind(0,'127.0.0.1',resolve))
  try{const p=await ping('127.0.0.1',server.address().port,1000);assert.equal(p.motd,'Fixture');assert.equal(p.protocol,1001)}finally{await new Promise(resolve=>server.close(resolve))}
})
test('Actual PHP serializer -> independent JS decoder, grass and edited upper section',()=>{
  const c=chunk(),decoded=decodeSections(c.payload,c.sub_chunk_count)
  assert.equal(blockAt(decoded,0,63,0),1017);assert.equal(blockAt(decoded,15,59,15),1000);assert.equal(blockAt(decoded,5,64,6),1119);assert.equal(decoded.sections.size,9)
})
test('Decoder rejects truncation, overflow and unsupported disk palette',()=>{assert.throws(()=>decodeSections(Buffer.from([8,1]),8));assert.throws(()=>readStorage(new Reader(Buffer.from([0]))));assert.throws(()=>new Reader(Buffer.from([255,255,255,255,255])).uvar())})
test('Independent decoder understands every supported palette bit-width',()=>{
  for(const bits of [1,2,3,4,5,6,8,16]){
    const per=Math.floor(32/bits),n=Math.min(2**bits,256),words=Buffer.alloc(Math.ceil(4096/per)*4)
    for(let i=0;i<4096;i++){const off=Math.floor(i/per)*4;words.writeUInt32LE((words.readUInt32LE(off)|((i%n)<<((i%per)*bits)))>>>0,off)}
    const uv=v=>{const b=[];do{let k=v%128;v=Math.floor(v/128);if(v)k|=128;b.push(k)}while(v);return Buffer.from(b)}
    const b=Buffer.concat([Buffer.from([(bits<<1)|1]),words,uv(n*2),...Array.from({length:n},(_,i)=>uv((i+100)*2))]);const values=readStorage(new Reader(b))
    for(let i=0;i<4096;i++)assert.equal(values[i],i%n+100)
  }
})
test('Inbox does not treat a sent packet as evidence',async()=>{const i=new Inbox();await assert.rejects(i.expect('text',()=>true,10),/Timeout/);i.put('text',{message:'unrelated'});await assert.rejects(i.expect('text',x=>x.message==='expected',10),/Timeout/)})
test('Inbox preserves early evidence and propagates fatal failures',async()=>{const i=new Inbox();i.put('spawn',{});await i.expect('spawn');const p=i.expect('text');i.fail(Error('lost'));await assert.rejects(p,/lost/)})
test('Nonce matcher rejects unrelated, malformed, and wrong-nonce responses',()=>{assert.equal(jsonMessage({message:'MPE-PROBE {bad'},'MPE-PROBE ','n'),false);assert.equal(jsonMessage({message:'MPE-PROBE {"nonce":"other"}'},'MPE-PROBE ','n'),false);assert.equal(jsonMessage({message:'MPE-PROBE {"nonce":"n"}'},'MPE-PROBE ','n'),true)})
test('Modern motion adapter changes structure, not coordinates or protocol claim',()=>{const p=motion({x:1,y:65.62,z:2},12,true);const old=adapt('player_auth_input',p,2193,1001);assert.equal(old.input_data.vertical_collision,true);assert.deepEqual(old.position,p.position);assert.equal(old.tick,12n);const round=adapt('player_auth_input',old,1001,2193);assert.deepEqual(round.input_data,['vertical_collision'])})
test('Modern adapter refuses unsupported inventory and unknown packet semantics',()=>{assert.throws(()=>adapt('player_auth_input',{input_data:['item_interact'],transaction:{}},2193,1001));assert.throws(()=>adapt('not_implemented',{},1001,2193));assert.throws(()=>assertTopLevel('x',{}, {packet_x:['container',[{name:'new_field',type:'bool'}]]}))})
test('Required-field guard allows explicit optional fields, not missing bools',()=>{assertTopLevel('x',{}, {packet_x:['container',[{name:'optional',type:['option','string']}]]});assertTopLevel('x',{ok:false}, {packet_x:['container',[{name:'ok',type:'bool'}]]})})
test('A start packet without spawn does not pass connection scenario',async()=>{const i=new Inbox();i.put('world_biomes_ready',{fixture:true});i.put('world_start_ready',{fixture:true});i.put('start_game',{runtime_entity_id:1n});await assert.rejects(scenario({},i,{scenario:'connect',timeout:10},{checks:[]}),/spawn/)})
test('Full scenario logic with deterministic fake transport; NOT a Bedrock login',async()=>{
  const i=new Inbox(),c=chunk();let position=[0.5,64,0.5],tick=1,id=0
  i.put('world_biomes_ready',{fixture:true});i.put('world_start_ready',{fixture:true});i.put('start_game',{runtime_entity_id:1n});i.put('spawn',{});i.put('level_chunk',c)
  const client={queue(name,p){
    if(name==='text')i.put('text',{message:`<Unit> ${p.message}`})
    if(name==='player_auth_input'){position=[p.position.x,p.position.y-1.62,p.position.z];tick++}
    if(name==='command_request'){
      const a=p.command.split(' ')
      if(a[1]==='probe'){
        const [x,y,z]=a.slice(3).map(Number),b=y===63?1:id
        i.put('text',{message:'MPE-PROBE '+JSON.stringify({nonce:a[2],position,tick:tick++,block:{x,y,z,id:b,runtime:1000+b*17},grass_runtime:1017,air_runtime:1000,palette_sha256:'fixture'})})
      }else if(a[0]==='/setblock'){
        const previous=id;id=a[4]==='diamond_block'?7:0;i.put('update_block',{position:{x:+a[1],y:+a[2],z:+a[3]},block_runtime_id:1000+id*17})
        i.put('text',{message:'MPE-EDIT '+JSON.stringify({nonce:a[5],previous,id})})
      }
    }
  }}
  const report={checks:[]};await scenario(client,i,{scenario:'edit',timeout:100,protocol:1001,chat:'hello',username:'Unit'},report)
  assert(report.checks.some(c=>c.name==='authoritative-movement'));assert(report.checks.some(c=>c.name==='restore-edited-block'));assert.equal(id,0)
})
