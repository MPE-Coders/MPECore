'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {WorldStartGuard,JIGSAW_KEYS}=require('../lib/world-start.cjs')
const structures=()=>({structure_data:{type:'compound',name:'',value:Object.fromEntries(JIGSAW_KEYS.map(k=>[k,{type:'list',value:{type:'compound',value:[]}}]))}})
const voxels=()=>({shapes:[],name_map:[],custom_shape_count:0})
function ready(protocol=2193){const g=new WorldStartGuard(protocol);g.accept('jigsaw_structure_data',structures());g.accept('voxel_shapes',voxels());return g}
test('A decodable StartGame without structure data is not a valid modern world start',()=>{
  assert.throws(()=>new WorldStartGuard(2193).accept('start_game',{}),/missingStructureData/)
})
test('Structure data alone cannot hide missing voxel prerequisites',()=>{
  const g=new WorldStartGuard(2193);g.accept('jigsaw_structure_data',structures())
  assert.throws(()=>g.accept('start_game',{}),/voxel_shapes/)
})
test('Empty compound does not replace the four typed structure lists',()=>{
  assert.throws(()=>new WorldStartGuard(2193).accept('jigsaw_structure_data',{structure_data:{type:'compound',value:{}}}),/processors/)
  for(const key of JIGSAW_KEYS){const p=structures();p.structure_data.value[key]={type:'string',value:''};assert.throws(()=>new WorldStartGuard(2193).accept('jigsaw_structure_data',p))}
})
test('An NBT end-typed empty list is valid, but a populated end list is not',()=>{
  const p=structures();p.structure_data.value.jigsaws.value.type='end'
  new WorldStartGuard(2193).accept('jigsaw_structure_data',p)
  p.structure_data.value.jigsaws.value.value.push({});assert.throws(()=>new WorldStartGuard(2193).accept('jigsaw_structure_data',p))
})
test('Missing, inconsistent and nonempty custom voxel registries are rejected in flat tests',()=>{
  for(const p of [{},{shapes:[],name_map:[]},{...voxels(),custom_shape_count:1},{...voxels(),name_map:[{}]}]){
    assert.throws(()=>new WorldStartGuard(2193).accept('voxel_shapes',p))
  }
})
test('Received packet order, not sent intent, is recorded for each native/bridged profile',()=>{
  for(const id of [975,1001,2193]){
    const proof=ready(id).accept('start_game',{})
    assert.equal(proof.start_packet_index,2);assert.equal(proof.received.jigsaw_structure_data.index,0)
    assert.equal(proof.received.voxel_shapes.index,1);assert.equal(proof.protocol,id)
  }
})
test('Late and duplicate data/StartGame are rejected',()=>{
  const g=ready();assert.throws(()=>g.accept('voxel_shapes',voxels()),/Duplicate/)
  g.accept('start_game',{});assert.throws(()=>g.accept('jigsaw_structure_data',structures()),/after/)
  assert.throws(()=>g.accept('start_game',{}),/Duplicate/)
})
test('Older clients are not required to understand packets introduced later',()=>{
  assert.deepEqual(new WorldStartGuard(671).accept('start_game',{}).received,{})
  const g=new WorldStartGuard(766);g.accept('jigsaw_structure_data',structures());assert(g.accept('start_game',{}))
  const early=new WorldStartGuard(924);early.accept('jigsaw_structure_data',structures())
  early.accept('voxel_shapes',{shapes:[],name_map:[]});assert(early.accept('start_game',{}))
})
