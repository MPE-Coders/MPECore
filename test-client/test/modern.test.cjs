'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {parseArgs}=require('../lib/args.cjs')
const {adaptFields}=require('../../codec/fields-2193.cjs')
test('26.51 spelling and wire protocol converge without a 12193 alias',()=>{
 for(const v of ['26.51','1.26.51','2193'])assert.equal(parseArgs(['--version',v]).version,'1.26.51')
 assert.throws(()=>parseArgs(['--version','12193']),/2193, not 12193/)
})
test('StartGame chat logging remains explicit in both representations',()=>{
 const a={is_logging_chat:true};adaptFields('start_game',a,1001,2193);assert.equal(a.is_chat_logging,true)
 const b={is_chat_logging:false};adaptFields('start_game',b,2193,1001);assert.equal(b.is_logging_chat,false)
 const c={};adaptFields('text',c,1001,2193);assert.deepEqual(c,{})
})
