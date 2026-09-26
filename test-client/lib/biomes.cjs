'use strict'
// This test server publishes vanilla biome definitions only. Do not confuse
// the registration sentinel 0xffff with the numeric biome stored in a chunk.
function inspectVanillaBiomes(packet,protocol){
  const entries=packet?.biome_definitions,strings=packet?.string_list
  if(!Array.isArray(entries)||entries.length===0||!Array.isArray(strings))throw Error('Missing biome registry')
  const names=new Set()
  for(const entry of entries){
    const name=Number.isInteger(entry.name_index)?strings[entry.name_index]:null
    if(typeof name!=='string'||!name.startsWith('minecraft:')||names.has(name))throw Error('Invalid or duplicated biome name index')
    names.add(name)
    if(entry.biome_id!==0xffff)throw Error(`Vanilla biome ${name}: registration ID must be 65535, not chunk biome ID ${entry.biome_id}`)
    for(const key of ['temperature','downfall','snow_foliage','depth','scale']){
      if(typeof entry[key]!=='number'||!Number.isFinite(entry[key]))throw Error(`Invalid biome field ${name}.${key}`)
    }
    if(typeof entry.rain!=='boolean'||!Number.isInteger(entry.map_water_colour))throw Error('Invalid biome colour/rain')
    if(entry.tags!=null && (!Array.isArray(entry.tags)||entry.tags.some(i=>!Number.isInteger(i)||typeof strings[i]!=='string')))throw Error('Invalid biome tag index')
    if(entry.chunk_generation!=null)throw Error('This flat-world test does not accept client-side biome generation data')
  }
  if(protocol===2193&&entries.length!==89)throw Error(`2193 requires its 89 pinned vanilla biome definitions, got ${entries.length}`)
  if(!names.has('minecraft:plains'))throw Error('No vanilla plains definition')
  return {definitions:entries.length,native_registration_id:65535,flat_chunk_biome_id:1}
}
module.exports={inspectVanillaBiomes}
