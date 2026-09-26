'use strict'
// Cloudburst's null vanilla registration ID is wire 0xffff, not the biome
// palette value (e.g. plains=1) contained in a LevelChunk.
const VANILLA_ID=0xffff
const FLOAT_FIELDS=['temperature','downfall','redSporeDensity','blueSporeDensity','ashDensity','whiteAshDensity','foliageSnow','depth','scale']
function convertVanillaBiomes(input){
  if(!input||typeof input!=='object'||Array.isArray(input)||!input['minecraft:plains'])throw Error('Expected the pinned vanilla biome map including plains')
  const result={}
  for(const [name,v] of Object.entries(input)){
    if(!/^minecraft:[a-z0-9_]+$/.test(name)||!v||typeof v!=='object'||Array.isArray(v))throw Error('Invalid vanilla biome entry')
    if(v.id!=null)throw Error(`Unexpected explicit/custom biome ID in vanilla input: ${name}`)
    if(v.chunkGenData!=null)throw Error('Client-side biome generation is not implemented')
    for(const field of FLOAT_FIELDS)if(typeof v[field]!=='number'||!Number.isFinite(v[field]))throw Error(`Invalid ${name}.${field}`)
    const color=v.mapWaterColor
    if(!color||['r','g','b','a'].some(k=>!Number.isInteger(color[k])||color[k]<0||color[k]>255))throw Error('Invalid biome ARGB colour')
    if(typeof v.rain!=='boolean'||(v.tags!=null&&(!Array.isArray(v.tags)||v.tags.some(t=>typeof t!=='string'))))throw Error('Invalid biome rain/tags')
    // Preserve the full versioned registry and its values, with only the native
    // serializer's spelling and representation changed. Do not sort by chunk ID.
    result[name]={...v,id:VANILLA_ID,mapWaterColour:{...color}}
    delete result[name].mapWaterColor
  }
  return result
}
module.exports={VANILLA_ID,convertVanillaBiomes}
