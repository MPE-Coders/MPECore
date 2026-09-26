'use strict'
const {adapt,assertTopLevel}=require('./adapters.cjs')
const CATALOG=new Map(require('../resources/protocol-catalog.json').map(p=>[p.protocol,p.version]))
const MAX_PACKET=4*1024*1024
class CodecEngine {
  constructor(){this.codecs=new Map()}
  codec(id){
    if(![1001,2168,2169,2193].includes(id))throw Error(`Not an experimental bridge endpoint: ${id}`)
    if(!this.codecs.has(id)){
      const version=CATALOG.get(id),data=require('minecraft-data')('bedrock_'+version)
      if(!data?.protocol?.types)throw Error(`Installed minecraft-data has no exact ${version} schema`)
      const {createSerializer,createDeserializer}=require('bedrock-protocol/src/transforms/serializer')
      this.codecs.set(id,{version,types:data.protocol.types,serializer:createSerializer(version),deserializer:createDeserializer(version)})
    }
    return this.codecs.get(id)
  }
  convert(buffer,from,to){
    if(!Buffer.isBuffer(buffer)||buffer.length<1||buffer.length>MAX_PACKET)throw Error('Packet size out of bounds')
    const src=this.codec(from),dest=this.codec(to),parsed=src.deserializer.parsePacketBuffer(buffer).data
    const reencoded=src.serializer.createPacketBuffer(parsed)
    if(!buffer.equals(reencoded))throw Error(`Non-canonical/trailing source data for ${parsed.name}`)
    const params=adapt(parsed.name,parsed.params,from,to)
    assertTopLevel(parsed.name,params,dest.types)
    const result=dest.serializer.createPacketBuffer({name:parsed.name,params})
    if(result.length>MAX_PACKET)throw Error('Encoded packet too large')
    const verification=dest.deserializer.parsePacketBuffer(result).data
    if(verification.name!==parsed.name)throw Error('Destination packet identity changed')
    return {name:parsed.name,buffer:result}
  }
}
module.exports={CodecEngine,MAX_PACKET}
