'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {request11}=require('../lib/raknet.cjs')
let installed=true
try{require.resolve('jsp-raknet')}catch{installed=false}
test('Real JSP request encoder sends RakNet 11 and preserves offline magic',{skip:!installed},()=>{
  const Request=require('jsp-raknet/js/protocol/OpenConnectionRequest1').default
  const wire=request11(1400)
  assert.equal(wire[0],5)
  assert.equal(wire.subarray(1,17).toString('hex'),'00ffff00fefefefefdfdfdfd12345678')
  assert.equal(wire[17],11)
  const decoded=new Request();decoded.buffer=wire;decoded.decode()
  assert.equal(decoded.protocol,11)
})
test('Invalid RakNet MTUs are rejected before any network activity',()=>{
  for(const mtu of [-1,0,575,1493,NaN,Infinity])assert.throws(()=>request11(mtu))
})
test('26.50 alias resolves the same 2193 schema, not 2169',()=>{
  const {parseArgs}=require('../lib/args.cjs')
  for(const v of ['26.50','1.26.50'])assert.equal(parseArgs(['--version',v]).version,'1.26.51')
})
