'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {convertVanillaBiomes}=require('../../tools/lib/biomes2193.cjs')
const {inspectVanillaBiomes}=require('../lib/biomes.cjs')
function source(){return {'minecraft:plains':{id:null,temperature:0.8,downfall:0.4,redSporeDensity:0,blueSporeDensity:0,ashDensity:0,whiteAshDensity:0,foliageSnow:0,depth:0.125,scale:0.05,mapWaterColor:{a:165,r:68,g:175,b:245},rain:true,tags:['overworld','plains'],chunkGenData:null}}}
function packet(count=1){return {string_list:Array.from({length:count},(_,i)=>i?`minecraft:fixture_${i}`:'minecraft:plains'),biome_definitions:Array.from({length:count},(_,name_index)=>({name_index,biome_id:65535,temperature:0.8,downfall:0.4,snow_foliage:0,depth:0.125,scale:0.05,map_water_colour:-1522225163,rain:true,tags:null,chunk_generation:null}))}}
test('Importer preserves every biome and maps vanilla null to 0xffff without mutating source',()=>{
  const raw=source();raw['minecraft:fixture_extra']=structuredClone(raw['minecraft:plains'])
  const before=structuredClone(raw),out=convertVanillaBiomes(raw)
  assert.deepEqual(Object.keys(out),Object.keys(raw));assert.deepEqual(raw,before)
  for(const v of Object.values(out)){
    assert.equal(v.id,65535);assert.deepEqual(v.mapWaterColour,before['minecraft:plains'].mapWaterColor)
    assert.equal(v.mapWaterColor,undefined);assert.deepEqual(v.tags,['overworld','plains'])
  }
})
test('Importer never repurposes a declared custom/chunk ID as a vanilla ID',()=>{
  for(const id of [0,1,30000,65535,false,'1']){const v=source();v['minecraft:plains'].id=id;assert.throws(()=>convertVanillaBiomes(v),/explicit\/custom/)}
})
test('Importer refuses missing fields and unsupported chunk generation instead of dropping data',()=>{
  assert.throws(()=>convertVanillaBiomes({}));assert.throws(()=>convertVanillaBiomes([]))
  for(const [key,value] of [['temperature',NaN],['rain','true'],['tags',[3]],['chunkGenData',{}],['mapWaterColor',{a:256,r:0,g:0,b:0}]]){
    const raw=source();raw['minecraft:plains'][key]=value;assert.throws(()=>convertVanillaBiomes(raw))
  }
})
test('Client semantic check rejects the old id=1 packet even though it is syntactically valid',()=>{
  const p=packet();p.biome_definitions[0].biome_id=1
  assert.throws(()=>inspectVanillaBiomes(p,2193),/registration ID must be 65535, not chunk biome ID 1/)
})
test('Client distinguishes full 2193 definitions from the flat world biome palette value',()=>{
  assert.throws(()=>inspectVanillaBiomes(packet(),2193),/89 pinned/)
  const proof=inspectVanillaBiomes(packet(89),2193)
  assert.equal(proof.definitions,89);assert.equal(proof.native_registration_id,65535);assert.equal(proof.flat_chunk_biome_id,1)
  assert.equal(inspectVanillaBiomes(packet(88),1001).definitions,88)
})
test('String index errors, duplicate names, missing plains and bad tag indices cannot pass',()=>{
  let p=packet();p.biome_definitions[0].name_index=9;assert.throws(()=>inspectVanillaBiomes(p,975))
  p=packet(2);p.biome_definitions[1].name_index=0;assert.throws(()=>inspectVanillaBiomes(p,975))
  p=packet();p.string_list[0]='minecraft:forest';assert.throws(()=>inspectVanillaBiomes(p,975),/plains/)
  p=packet();p.biome_definitions[0].tags=[9];assert.throws(()=>inspectVanillaBiomes(p,975),/tag index/)
})
test('Client rejects malformed climate values and unexpected client-side world generation',()=>{
  for(const [key,value] of [['temperature',Infinity],['rain',1],['map_water_colour',0.5],['chunk_generation',{}]]){
    const p=packet();p.biome_definitions[0][key]=value;assert.throws(()=>inspectVanillaBiomes(p,975))
  }
})
