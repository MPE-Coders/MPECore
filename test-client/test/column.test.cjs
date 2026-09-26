'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {decodeColumn,blockAt}=require('../lib/chunks.cjs')
function column(){return Buffer.concat([Buffer.from([8,1,1,0]),Buffer.from(Array.from({length:24},()=>[1,2]).flat()),Buffer.from([0])])}
test('Full column decoder consumes exactly 24 biome storages and border marker',()=>{
  const payload=column(),c=decodeColumn(payload,1)
  assert.equal(c.bytesRead,payload.length);assert.equal(c.biomes.length,24)
  assert.equal(c.biomes[0][0],1);assert.equal(blockAt(c,0,-64,0),0)
})
test('Valid block sections do not hide a truncated biome tail',()=>{
  const b=column();for(const end of [4,20,b.length-1])assert.throws(()=>decodeColumn(b.subarray(0,end),1))
})
test('Unexplained trailing data and unsupported border data are rejected',()=>{
  assert.throws(()=>decodeColumn(Buffer.concat([column(),Buffer.from([0])]),1))
  const b=column();b[b.length-1]=1;assert.throws(()=>decodeColumn(b,1))
})
