'use strict'
const test=require('node:test'),assert=require('node:assert/strict')
const {parseArgs}=require('../lib/args.cjs')
const {adaptFields}=require('../../codec/fields-2193.cjs')
test('26.51 spelling and wire protocol converge without a 12193 alias',()=>{
 for(const v of ['26.51','1.26.51','2193'])assert.equal(parseArgs(['--version',v]).version,'1.26.51')
 assert.throws(()=>parseArgs(['--version','12193']),/2193, not 12193/)
})
test('Removed StartGame chat flag has explicit false semantics, not a guessed rename',()=>{
 const a={is_chat_logging:false};adaptFields('start_game',a,1001,2193);assert.equal(a.is_chat_logging,undefined)
 const b={};adaptFields('start_game',b,2193,1001);assert.equal(b.is_chat_logging,false)
 assert.throws(()=>adaptFields('start_game',{is_chat_logging:true},1001,2193),/cannot represent/)
 const c={};adaptFields('text',c,1001,2193);assert.deepEqual(c,{})
})
test('Modern chunks carry an empty explicit blob array with cache disabled',()=>{
 const p={cache_enabled:false};adaptFields('level_chunk',p,1001,2193);assert.deepEqual(p.blobs,[])
 assert.throws(()=>adaptFields('level_chunk',{cache_enabled:true},1001,2193),/not implemented/)
 assert.throws(()=>adaptFields('level_chunk',{cache_enabled:false,blobs:[1n]},2193,1001),/not implemented/)
})
