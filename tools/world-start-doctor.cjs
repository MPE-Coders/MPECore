#!/usr/bin/env node
'use strict'
// Decode actual PHP-generated startup bytes with independent Prismarine codecs,
// then enforce prerequisites and re-run missing/late/corrupt negative controls.
const fs=require('node:fs'),assert=require('node:assert/strict')
const {createSerializer,createDeserializer}=require('bedrock-protocol/src/transforms/serializer')
const {WorldStartGuard,validateWorldStartWire}=require('../test-client/lib/world-start.cjs')
const source=JSON.parse(fs.readFileSync(process.argv[2]||'data/world-start-codec.json','utf8'))
if(source.schema!==1||!Array.isArray(source.profiles)||source.profiles.length===0)throw Error('No actual startup corpus')
for(const profile of source.profiles){
  const serializer=createSerializer(profile.version),decoder=createDeserializer(profile.version)
  const guard=new WorldStartGuard(profile.protocol),decoded=[]
  const parseExact=bytes=>{const packet=decoder.parsePacketBuffer(bytes);validateWorldStartWire(packet,serializer);return packet.data}
  for(const entry of profile.packets){
    const bytes=Buffer.from(entry.wire,'base64'),data=parseExact(bytes)
    assert(bytes.equals(serializer.createPacketBuffer(data)),`Independent re-encode mismatch: ${data.name}`)
    guard.accept(data.name,data.params);decoded.push(data)
    // Decode+canonical-boundary check rejects truncation even when the NBT reader tolerates missing TAG_End.
    assert.throws(()=>parseExact(bytes.subarray(0,-1)),`Accepted truncated ${data.name}`)
    assert.throws(()=>parseExact(Buffer.concat([bytes,Buffer.from([0])])),`Accepted trailing data in ${data.name}`)
  }
  assert(guard.started,'Startup corpus must contain StartGame')
  for(const name of guard.required){
    const missing=new WorldStartGuard(profile.protocol)
    assert.throws(()=>{for(const p of decoded.filter(p=>p.name!==name))missing.accept(p.name,p.params)},/StartGame received before/)
    const late=new WorldStartGuard(profile.protocol)
    const start=decoded.find(p=>p.name==='start_game');assert.throws(()=>late.accept(start.name,start.params),/StartGame received before/)
  }
  console.log(`PASS actual world-start wire ${profile.version}/${profile.protocol}: ordered prerequisites, independent decode and negative controls`)
}
