'use strict'
// Static schema diagnostics only. Never log authentication or actual player packets.
const {ALLOWED}=require('../codec/adapters.cjs')
const old=require('minecraft-data')('bedrock_1.26.30').protocol.types
const modern=require('minecraft-data')('bedrock_1.26.51').protocol.types
function fields(types,name){const t=types['packet_'+name];return Array.isArray(t)&&t[0]==='container'?t[1]:[]}
for(const name of ALLOWED){
 const a=fields(old,name),b=fields(modern,name)
 const added=b.filter(x=>!a.some(y=>y.name===x.name))
 const changed=b.filter(x=>a.some(y=>y.name===x.name&&JSON.stringify(y.type)!==JSON.stringify(x.type)))
 const removed=a.filter(x=>!b.some(y=>y.name===x.name))
 if(added.length||changed.length||removed.length)console.log(JSON.stringify({name,added,changed,removed}))
}
