'use strict'
// Data conversion only. No copied engine code. See resources/modern/2193.sources.json.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto')
const nbt=require('prismarine-nbt')
async function parseCompound(bytes,label){
  const {parsed}=await nbt.parse(bytes)
  if(parsed.type!=='compound')throw Error(label+' must be a compound')
  return parsed
}
async function main(){
  const [source,dest]=process.argv.slice(2)
  if(!source||!dest)throw Error('Usage: node tools/import-2193.cjs SOURCE OUTPUT')
  const read=name=>fs.readFileSync(path.join(source,name))
  const write=(name,data)=>fs.writeFileSync(path.join(dest,name),data)
  const json=(name,data)=>write(name,JSON.stringify(data,null,2)+'\n')
  const palette=read('block_states.nbt')
  // The exact ordered sequence is retained; its index is the wire runtime ID.
  let offset=0,count=0
  while(offset<palette.length){
    const {value,size}=nbt.protos.littleVarint.read(palette,offset,'nbt')
    if(!Number.isInteger(size)||size<1)throw Error('Invalid NBT root length')
    if(value.type!=='compound'||value.value.name?.type!=='string'||value.value.states?.type!=='compound')throw Error('Invalid block-state root')
    offset+=size;if(++count>200000)throw Error('Too many block states')
  }
  if(offset!==palette.length||count<1000)throw Error('Incomplete 2193 palette')
  write('block_states-2193.nbt',palette)
  json('block_state_info-2193.json',{kind:'ordered-states-without-legacy-meta',states:count,palette_sha256:crypto.createHash('sha256').update(palette).digest('hex')})
  const components=await parseCompound(read('item_components.nbt'),'Item components')
  const raw=JSON.parse(read('runtime_item_states.json')),items={},ids=new Set()
  if(!Array.isArray(raw))throw Error('Expected runtime item array')
  for(const item of raw){
    if(!item.name?.startsWith('minecraft:')||!Number.isInteger(item.id)||Math.abs(item.id)>32767||ids.has(item.id)||items[item.name]||typeof item.componentBased!=='boolean')throw Error('Invalid item entry')
    ids.add(item.id)
    const component=components.value[item.name]
    if(item.componentBased&&!component)throw Error('Missing required item components: '+item.name)
    if(component&&component.type!=='compound')throw Error('Invalid item component')
    const entry={runtime_id:item.id,component_based:item.componentBased,version:item.version}
    if(component)entry.component_nbt=nbt.writeUncompressed({...component,name:''},'little').toString('base64')
    items[item.name]=entry
  }
  json('required_item_list-2193.json',items)
  let entities=read('entity_identifiers.dat')
  if(entities[0]===0x77)entities=entities.subarray(1)
  const actors=await parseCompound(entities,'Entity identifiers')
  if(actors.value.idlist?.type!=='list')throw Error('Entity registry lacks idlist')
  write('entity_identifiers-2193.nbt',nbt.writeUncompressed(actors,'littleVarint'))
  const biomes=JSON.parse(read('stripped_biome_definitions.json'))
  const plains=biomes['minecraft:plains']
  if(!plains?.mapWaterColor||typeof plains.temperature!=='number')throw Error('Missing plains definition')
  // The flat generator emits ONLY plains id 1. This is a server-defined subset,
  // not a complete vanilla biome registry or support for client-side generation.
  json('biome_definitions-2193.json',{'minecraft:plains':{...plains,id:1,mapWaterColour:plains.mapWaterColor}})
  console.log(JSON.stringify({protocol:2193,palette_states:count,items:raw.length,entity_count:actors.value.idlist.value.value.length,biomes:'plains-only'}))
}
main().catch(e=>{console.error('2193 IMPORT FAILED:',e.stack);process.exitCode=1})
