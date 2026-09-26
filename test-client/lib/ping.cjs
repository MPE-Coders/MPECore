'use strict'
const dgram = require('node:dgram')
const dns = require('node:dns').promises
const crypto = require('node:crypto')
const MAGIC = Buffer.from('00ffff00fefefefefdfdfdfd12345678','hex')
function pingPacket(timestamp = BigInt(Date.now())) {
  const b=Buffer.alloc(33);b[0]=1;b.writeBigInt64BE(timestamp,1);MAGIC.copy(b,9);crypto.randomBytes(8).copy(b,25);return b
}
function parsePong(b, timestamp) {
  if (b.length<35 || b[0]!==0x1c || !b.subarray(17,33).equals(MAGIC)) throw Error('Invalid RakNet pong')
  if (timestamp !== undefined && b.readBigInt64BE(1)!==timestamp) throw Error('Pong timestamp mismatch')
  const len=b.readUInt16BE(33)
  if (len>4096 || b.length!==35+len) throw Error('Invalid pong string length')
  const values=b.subarray(35).toString('utf8').split(';')
  if (!['MCPE','MCEE'].includes(values[0]) || !/^\d+$/.test(values[2])) throw Error('Not a Bedrock advertisement')
  return { motd: values[1], protocol: Number(values[2]), version: values[3], players: Number(values[4]), maxPlayers: Number(values[5]) }
}
async function ping(host,port,timeout=3000) {
  const {address}=await dns.lookup(host,{family:4});const socket=dgram.createSocket('udp4')
  const packet=pingPacket(),timestamp=packet.readBigInt64BE(1),start=performance.now()
  return new Promise((resolve,reject)=>{
    let finished=false
    const finish=(err,value)=>{if(finished)return;finished=true;clearTimeout(timer);socket.close();err?reject(err):resolve(value)}
    const timer=setTimeout(()=>finish(Error('RakNet pong timeout')),timeout)
    socket.on('error',err=>finish(err))
    socket.on('message',(b,remote)=>{
      if(remote.address!==address || remote.port!==port)return
      try { const result=parsePong(b,timestamp);finish(null,{...result,rttMs:performance.now()-start}) } catch { /* Ignore unrelated/malformed datagrams, retain timeout. */ }
    })
    socket.send(packet,port,address,err=>{if(err)finish(err)})
  })
}
module.exports={MAGIC,pingPacket,parsePong,ping}
