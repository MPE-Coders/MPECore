'use strict'
// jsp-raknet 2.2.0 hardcodes 10 and its upstream adapter subscribes too late.
// Scope this to one test-client instance; do not patch node_modules/prototypes.
function request11(mtu) {
  if(!Number.isInteger(mtu)||mtu<576||mtu>1492)throw Error('Invalid test MTU')
  const Request=require('jsp-raknet/js/protocol/OpenConnectionRequest1').default
  const packet=new Request();packet.mtuSize=mtu;packet.protocol=11;packet.encode()
  return packet.buffer
}
function attachRaknet11(client) {
  const transport=client.connection
  if(client.options.raknetBackend!=='jsp-raknet'||client.options.useRaknetWorkers)throw Error('Unexpected test transport')
  const {Client}=require('jsp-raknet')
  class VersionedClient extends Client {
    sendConnectionRequest() {
      this.sendBuffer(request11(this.mtuSize))
      this.emit('connecting',{mtuSize:this.mtuSize,protocol:11})
    }
  }
  transport.connect=()=>{
    const rak=transport.raknet=new VersionedClient(client.options.host,client.options.port)
    rak.on('connected',()=>transport.onConnected())
    rak.on('encapsulated',(packet,addr)=>transport.onEncapsulated(packet,addr.hash))
    rak.on('disconnect',reason=>transport.onCloseConnection(reason))
    rak.on('error',error=>client.onConnectionError(error instanceof Error?error:Error(String(error))))
    return rak.connect()
  }
}
module.exports={attachRaknet11,request11}
