'use strict'
const test=require('node:test'), assert=require('node:assert/strict')
const {Inbox}=require('../lib/events.cjs')
test('Chunk resend observer survives bounded inbox history and excludes other columns',()=>{
  const inbox=new Inbox(),watch=inbox.watch('level_chunk',p=>p.x===0&&p.z===0)
  inbox.put('level_chunk',{x:0,z:0})
  for(let i=0;i<100;i++)inbox.put('level_chunk',{x:1,z:0})
  inbox.put('level_chunk',{x:0,z:0})
  assert.equal(watch.matches,2);assert.equal(inbox.history.get('level_chunk').length,64)
  watch.close();inbox.put('level_chunk',{x:0,z:0});assert.equal(watch.matches,2)
})
