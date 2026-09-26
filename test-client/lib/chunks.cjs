'use strict'
/** Independent bounded decoder for the v8/v9 network palettes emitted by this milestone. */
class Reader {
  constructor(buffer) { this.b=buffer;this.o=0 }
  byte(){if(this.o>=this.b.length)throw Error('Truncated chunk');return this.b[this.o++]}
  u32(){if(this.o+4>this.b.length)throw Error('Truncated word');const n=this.b.readUInt32LE(this.o);this.o+=4;return n}
  uvar(){let v=0;for(let i=0;i<5;i++){const b=this.byte();if(i===4&&b>15)throw Error('Varint overflow');v+=(b&127)*2**(7*i);if(!(b&128))return v}throw Error('Oversized varint')}
  svar(){const v=this.uvar();return v%2?-(v+1)/2:v/2}
}
function readStorage(r) {
  const header=r.byte(),bits=header>>1
  if(!(header&1)||![0,1,2,3,4,5,6,8,16].includes(bits))throw Error('Unsupported network palette header')
  if(bits===0){const id=r.svar();if(id<0)throw Error('Invalid singleton runtime');return new Uint32Array(4096).fill(id)}
  const perWord=Math.floor(32/bits),words=Array.from({length:Math.ceil(4096/perWord)},()=>r.u32())
  const n=r.svar();if(n<1||n>Math.min(4096,2**bits))throw Error('Invalid palette size')
  const palette=Array.from({length:n},()=>{const id=r.svar();if(id<0)throw Error('Invalid runtime');return id})
  const values=new Uint32Array(4096),mask=2**bits-1
  for(let i=0;i<4096;i++){const k=(words[Math.floor(i/perWord)] >>> ((i%perWord)*bits))&mask;if(k>=n)throw Error('Palette index out of range');values[i]=palette[k]}
  return values
}
function decodeSections(payload,count,minSection=-4){
  if(!Buffer.isBuffer(payload)||payload.length>4*1024*1024||!Number.isInteger(count)||count<1||count>24)throw Error('Invalid chunk payload/count')
  const r=new Reader(payload),sections=new Map()
  for(let i=0;i<count;i++){
    const version=r.byte(),layers=r.byte();if(![8,9].includes(version)||layers<1||layers>2)throw Error('Unsupported subchunk format/layers')
    let y=minSection+i;if(version===9){y=r.byte();if(y>127)y-=256}
    if(y< -4||y>19||sections.has(y))throw Error('Duplicate/out-of-range section')
    for(let layer=0;layer<layers;layer++){const values=readStorage(r);if(layer===0)sections.set(y,values)}
  }
  return {sections,bytesRead:r.o}
}
function blockAt(decoded,x,y,z){
  const section=decoded.sections.get(Math.floor(y/16));if(!section)throw Error('Requested section was not sent')
  const local=v=>((v%16)+16)%16
  return section[(local(x)<<8)|(local(z)<<4)|local(y)]
}
/** Strict MPE full-column validation: 24 biome storages, no borders/block entities. */
function decodeColumn(payload,count,minSection=-4) {
  const decoded=decodeSections(payload,count,minSection)
  const r=new Reader(payload);r.o=decoded.bytesRead
  const biomes=[]
  for(let i=0;i<24;i++)biomes.push(readStorage(r))
  if(r.byte()!==0)throw Error('MPE column unexpectedly contains border blocks')
  if(r.o!==payload.length)throw Error('Unexpected trailing column data / block entities')
  return {...decoded,biomes,bytesRead:r.o}
}
module.exports={Reader,readStorage,decodeSections,decodeColumn,blockAt}
