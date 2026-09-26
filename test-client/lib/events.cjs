'use strict'
/** A bounded event inbox. Tests require received evidence, never merely a successful send(). */
class Inbox {
  constructor(){this.history=new Map();this.waiters=new Set();this.error=null;this.observers=new Set()}
  put(name,value){
    for(const observer of this.observers)if(observer.name===name&&observer.test(value))observer.matches++
    const h=this.history.get(name)||[];h.push(value);if(h.length>64)h.shift();this.history.set(name,h)
    for(const w of [...this.waiters])if(w.name===name){try{if(w.test(value)){clearTimeout(w.timer);this.waiters.delete(w);w.resolve(value)}}catch(e){clearTimeout(w.timer);this.waiters.delete(w);w.reject(e)}}
  }
  watch(name,test=()=>true){
    const observer={name,test,matches:0,close:()=>this.observers.delete(observer)}
    this.observers.add(observer);return observer
  }
  fail(error){if(this.error)return;this.error=error;for(const w of this.waiters){clearTimeout(w.timer);w.reject(error)}this.waiters.clear()}
  expect(name,test=()=>true,timeout=10000){
    if(this.error)return Promise.reject(this.error)
    for(const v of [...(this.history.get(name)||[])].reverse()){if(test(v))return Promise.resolve(v)}
    return new Promise((resolve,reject)=>{const w={name,test,resolve,reject};w.timer=setTimeout(()=>{this.waiters.delete(w);reject(Error(`Timeout waiting for ${name}`))},timeout);this.waiters.add(w)})
  }
}
module.exports={Inbox}
