'use strict'
// Independent client-side prerequisite check, based on the Mojang packet contract.
// Decoding a StartGame packet alone is not proof that its dependencies arrived.
const JIGSAW_KEYS=['processors','template_pools','jigsaws','structure_sets']
class WorldStartGuard {
  constructor(protocol){
    if(!Number.isInteger(protocol)||protocol<0)throw Error('Invalid protocol')
    this.protocol=protocol;this.index=0;this.started=false;this.seen={};this.biomes=null
    this.required=[]
    if(protocol>=712)this.required.push('jigsaw_structure_data')
    if(protocol>=924)this.required.push('voxel_shapes')
  }
  accept(name,params){
    const index=this.index++
    if(name==='biome_definition_list'&&this.protocol>=827){
      if(this.biomes)throw Error('Duplicate biome registry')
      this.biomes={index,...require('./biomes.cjs').inspectVanillaBiomes(params,this.protocol)}
    }
    if(name==='jigsaw_structure_data'||name==='voxel_shapes'){
      if(!this.required.includes(name))throw Error(`Unexpected ${name} for protocol ${this.protocol}`)
      if(this.started)throw Error(`${name} arrived after StartGame`)
      if(this.seen[name])throw Error(`Duplicate ${name} before StartGame`)
      const evidence={index}
      if(name==='jigsaw_structure_data'){
        const root=params?.structure_data
        if(root?.type!=='compound'||!root.value)throw Error('JigsawStructureData must contain a compound root')
        evidence.lists={}
        for(const key of JIGSAW_KEYS){
          const list=root.value[key]
          if(list?.type!=='list'||!Array.isArray(list.value?.value)||
             !['compound','end'].includes(list.value.type)||
             (list.value.type==='end'&&list.value.value.length!==0))throw Error(`Missing or invalid JigsawStructureData list: ${key}`)
          evidence.lists[key]=list.value.value.length
        }
      }else{
        if(!Array.isArray(params?.shapes)||!Array.isArray(params?.name_map))throw Error('VoxelShapes requires shapes and name_map arrays')
        if(this.protocol>=944&&(!Number.isInteger(params.custom_shape_count)||params.custom_shape_count<0||params.custom_shape_count>params.shapes.length))throw Error('Invalid VoxelShapes custom_shape_count')
        // This check deliberately describes the current server: no custom shapes.
        if(params.shapes.length||params.name_map.length||(params.custom_shape_count??0)!==0)throw Error('This flat-world test expects an explicit empty custom-shape registry')
        Object.assign(evidence,{shapes:0,names:0,custom_shapes:0})
      }
      this.seen[name]=evidence
    }
    if(name!=='start_game')return null
    if(this.started)throw Error('Duplicate StartGame')
    for(const required of this.required){
      if(!this.seen[required])throw Error(`StartGame received before ${required}${required==='jigsaw_structure_data'?' (missingStructureData)':''}`)
    }
    this.started=true
    return {protocol:this.protocol,start_packet_index:index,received:{...this.seen}}
  }
}
function validateWorldStartWire(packet,serializer){
  if(!['jigsaw_structure_data','voxel_shapes','start_game','biome_definition_list'].includes(packet.data?.name))return
  // The upstream NBT reader can tolerate a missing final TAG_End. Require the
  // original complete packet to match re-encoding, instead of trusting parse success.
  if(!Buffer.isBuffer(packet.fullBuffer)||!packet.fullBuffer.equals(serializer.createPacketBuffer(packet.data))){
    throw Error(`Non-canonical or truncated world-start data: ${packet.data.name}`)
  }
}
function attachWorldStartGuard(client,inbox,protocol){
  const guard=new WorldStartGuard(protocol)
  // bedrock-protocol emits this after wire decoding, before its per-packet handlers.
  client.on('packet',packet=>{
    try{
      validateWorldStartWire(packet,client.serializer)
      const proof=guard.accept(packet.data.name,packet.data.params)
      if(proof)inbox.put('world_start_ready',proof)
      if(packet.data.name==='biome_definition_list'&&guard.biomes)inbox.put('world_biomes_ready',guard.biomes)
    }catch(error){inbox.fail(error);client.close()}
  })
  return guard
}
module.exports={WorldStartGuard,attachWorldStartGuard,validateWorldStartWire,JIGSAW_KEYS}
