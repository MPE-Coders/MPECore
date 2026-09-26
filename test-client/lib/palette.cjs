'use strict'
const fs=require('node:fs'),crypto=require('node:crypto')
function loadPalette(path){
  const bytes=fs.readFileSync(path)
  if(bytes.length>32*1024*1024)throw Error('Palette too large')
  // A different NBT implementation than NetherGames: verify type-sensitive state matching.
  const proto=require('prismarine-nbt').protos.littleVarint
  let offset=0,index=0,air,grass
  while(offset<bytes.length){
    const {value,size}=proto.read(bytes,offset,'nbt');if(size<=0)throw Error('NBT parser made no progress')
    offset+=size
    const name=value?.value?.name?.value,states=value?.value?.states?.value
    if(name==='minecraft:air'&&states&&Object.keys(states).length===0)air=index
    if(['minecraft:grass','minecraft:grass_block'].includes(name)&&states&&Object.keys(states).length===0)grass=index
    index++
  }
  if(air===undefined||grass===undefined)throw Error('No exact air/grass entry in supplied palette')
  return {air,grass,count:index,sha256:crypto.createHash('sha256').update(bytes).digest('hex')}
}
module.exports={loadPalette}
