#!/usr/bin/env node
'use strict'
// stdout is exclusively the JSON-lines control channel, never a user console.
const util=require('node:util')
for(const k of ['log','info','debug'])console[k]=(...args)=>process.stderr.write(util.format(...args)+'\n')
const {CodecEngine}=require('./engine.cjs'),engine=new CodecEngine()
let pending=''
const MAX_LINE=6*1024*1024
process.stdin.setEncoding('utf8')
process.stdin.on('data',chunk=>{
  pending+=chunk
  if(pending.length>MAX_LINE&&!pending.includes('\n')){process.stderr.write('Codec request overflow\n');process.exit(2)}
  let i
  while((i=pending.indexOf('\n'))!==-1){
    const line=pending.slice(0,i);pending=pending.slice(i+1);let req
    try{
      if(line.length>MAX_LINE)throw Error('Request too large')
      req=JSON.parse(line)
      if(!Number.isSafeInteger(req.id))throw Error('Invalid request ID')
      if(req.method==='capabilities'){
        const versions=[1001,2168,2169,2193].map(id=>({id,version:engine.codec(id).version}))
        process.stdout.write(JSON.stringify({id:req.id,ok:true,versions})+'\n');continue
      }
      if(req.method!=='convert'||typeof req.data!=='string'||!/^([A-Za-z0-9+/]{4})*([A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(req.data))throw Error('Invalid codec request')
      const out=engine.convert(Buffer.from(req.data,'base64'),req.from,req.to)
      process.stdout.write(JSON.stringify({id:req.id,ok:true,name:out.name,data:out.buffer.toString('base64')})+'\n')
    }catch(e){process.stdout.write(JSON.stringify({id:req?.id??null,ok:false,error:e.message})+'\n')}
  }
})
