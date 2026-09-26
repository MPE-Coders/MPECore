use std::collections::{HashMap, VecDeque};
use std::io::{self, BufWriter, Write};
use std::sync::mpsc::{sync_channel, TryRecvError};
use std::time::{Duration, Instant};
use crate::network::ipc::{self, Decoder, Encoder};
use crate::player::Player;
use crate::world::World;
use crate::world::interaction::Interaction;

pub struct Server {
    world: World,
    players: HashMap<u64, Player>,
    next_runtime_id: u64,
    chunk_requests: VecDeque<(u64,i32,i32)>,
    ticks: u64,
}
impl Server {
    pub fn new()->io::Result<Self>{
        let directory=std::env::var("MPE_WORLD_DIR").unwrap_or_else(|_|"data/world".into());
        Ok(Self{world:World::open(&std::path::Path::new(&directory).join("blocks.wal"))?,players:HashMap::new(),next_runtime_id:1,chunk_requests:VecDeque::new(),ticks:0})
    }
    pub fn run(mut self)->io::Result<()> {
        // One bounded reader and one owner of the game state. Region workers are a future milestone.
        let (tx,rx)=sync_channel::<io::Result<Vec<u8>>>(4096);
        std::thread::spawn(move || {
            let mut input=io::stdin().lock();
            loop { match ipc::read_frame(&mut input) {
                Ok(Some(frame))=>if tx.send(Ok(frame)).is_err(){break},
                Ok(None)=>break,
                Err(e)=>{let _=tx.send(Err(e));break;}
            }}
        });
        let mut out=BufWriter::new(io::stdout().lock());
        let mut ready=Encoder::new(0x80);ready.string(env!("CARGO_PKG_VERSION"));ready.i32(64);
        ipc::write_frame(&mut out,&ready.0)?;out.flush()?;
        let tick_duration=Duration::from_millis(50);let mut running=true;
        while running {
            let started=Instant::now();
            for _ in 0..2048 {
                match rx.try_recv(){
                    Ok(frame)=>{if !self.handle(&frame?,&mut out)?{running=false;break}},
                    Err(TryRecvError::Empty)=>break,
                    Err(TryRecvError::Disconnected)=>{running=false;break;}
                }
            }
            // Bound chunk work per tick; IPC stdout is drained continuously by the gateway.
            for _ in 0..16 {
                let Some((sid,x,z))=self.chunk_requests.pop_front() else {break};
                if !self.players.contains_key(&sid){continue;}
                let chunk=self.world.generate(x,z);
                let mut e=Encoder::new(0x83);e.u64(sid);e.i32(chunk.x);e.i32(chunk.z);e.u8(chunk.sections.len() as u8);
                for section in chunk.sections { e.u8(section.y as u8);e.0.extend_from_slice(&section.blocks[..]); }
                ipc::write_frame(&mut out,&e.0)?;
            }
            self.ticks+=1;
            out.flush()?;
            if let Some(left)=tick_duration.checked_sub(started.elapsed()){std::thread::sleep(left)}
        }
        Ok(())
    }
    fn handle(&mut self,frame:&[u8],out:&mut impl Write)->io::Result<bool>{
        let mut d=Decoder::new(frame);let op=d.u8()?;
        match op {
            1=>{
                let sid=d.u64()?;let name=d.string(64)?;let uuid=d.string(64)?;let authenticated=d.u8()?!=0;d.finish()?;
                if self.players.len()>=32 || self.players.contains_key(&sid) ||
                    self.players.values().any(|p|p.uuid==uuid || p.name.eq_ignore_ascii_case(&name)) {
                    let mut e=Encoder::new(0x82);e.u64(sid);e.string("Server full or duplicate identity");ipc::write_frame(out,&e.0)?;
                } else {
                    let id=self.next_runtime_id;self.next_runtime_id+=1;
                    let position=self.world.spawn();self.players.insert(sid,Player::new(sid,id,name,uuid,authenticated,position));
                    let mut e=Encoder::new(0x81);e.u64(sid);e.u64(id);e.vector(position);ipc::write_frame(out,&e.0)?;
                }
            },
            2=>{let sid=d.u64()?;d.finish()?;self.players.remove(&sid);self.chunk_requests.retain(|v|v.0!=sid);},
            3=>{
                let sid=d.u64()?;let pos=d.vector()?;let pitch=d.f32()?;let yaw=d.f32()?;let tick=d.u64()?;d.finish()?;
                if let Some(p)=self.players.get_mut(&sid) {
                    if pos.is_finite() && pos.y < -70.0 {
                        p.teleport(self.world.spawn());
                        let mut e=Encoder::new(0x8d);e.u64(sid);e.vector(p.position);e.f32(p.pitch);e.f32(p.yaw);e.u64(tick);ipc::write_frame(out,&e.0)?;
                    } else if self.world.collides(pos) || !p.move_to(pos,pitch,yaw,tick){
                        let mut e=Encoder::new(0x84);e.u64(sid);e.vector(p.position);e.f32(p.pitch);e.f32(p.yaw);e.u64(tick);e.u8(self.world.on_ground(p.position) as u8);ipc::write_frame(out,&e.0)?;
                    } else {
                        let mut e=Encoder::new(0x85);e.u64(sid);e.vector(p.position);e.u64(tick);e.f32(p.pitch);e.f32(p.yaw);ipc::write_frame(out,&e.0)?;
                    }
                }
            },
            13=>{
                let sid=d.u64()?;let pos=d.vector()?;let pitch=d.f32()?;let yaw=d.f32()?;let tick=d.u64()?;let flags=d.u8()?;d.finish()?;
                if flags & !3 != 0 {return Err(io::Error::new(io::ErrorKind::InvalidData,"Invalid physics flags"));}
                if let Some(p)=self.players.get_mut(&sid).filter(|p|p.initialized) {
                    if let Some(correct)=crate::player::physics::advance(p,&self.world,pos,pitch,yaw,tick,flags&1!=0,flags&2!=0) {
                        if p.position.y < -70.0 {
                            p.teleport(self.world.spawn());
                            let mut e=Encoder::new(0x8d);e.u64(sid);e.vector(p.position);e.f32(p.pitch);e.f32(p.yaw);e.u64(tick);ipc::write_frame(out,&e.0)?;
                        } else {
                            let mut e=Encoder::new(0x8e);e.u64(sid);e.vector(p.position);e.f32(p.pitch);e.f32(p.yaw);e.u64(tick);
                            e.f32(p.vertical_velocity);e.u8(self.world.on_ground(p.position) as u8);e.u8(correct as u8);ipc::write_frame(out,&e.0)?;
                        }
                    }
                }
            },
            4=>{
                let sid=d.u64()?;let x=d.i32()?;let z=d.i32()?;d.finish()?;
                if let Some(p)=self.players.get(&sid) {
                    let (px,pz)=p.position.chunk();
                    if (x as i64-px as i64).abs()<=10 && (z as i64-pz as i64).abs()<=10 && self.chunk_requests.len()<2048 && !self.chunk_requests.contains(&(sid,x,z)) {
                        self.chunk_requests.push_back((sid,x,z));
                    }
                }
            },
            6=>{
                let command=d.string(256)?;d.finish()?;
                let text=match command.as_str(){
                    "list"=>format!("{} player(s): {}",self.players.len(),self.players.values().map(|p|format!("{}#{} [{}]",p.name,p.runtime_id,if p.authenticated{"online"}else{"offline"})).collect::<Vec<_>>().join(", ")),
                    "status"=>format!("tick={} players={} pending_chunks={} target=20TPS; single state owner",self.ticks,self.players.len(),self.chunk_requests.len()),
                    _=>"Engine commands: list, status".into()
                };
                let mut e=Encoder::new(0x86);e.u8(1);e.string(&text);ipc::write_frame(out,&e.0)?;
            },
            7=>{d.finish()?;return Ok(false)},
            8=>{
                let sid=d.u64()?;d.finish()?;
                if let Some(p)=self.players.get_mut(&sid) {
                    if !p.initialized {p.initialized=true;let mut e=Encoder::new(0x88);e.u64(p.sid);e.string(&p.name);e.string(&p.uuid);e.u8(p.authenticated as u8);e.vector(p.position);ipc::write_frame(out,&e.0)?;}
                }
            },
            9=>{
                // Authoritative probe: returned only for an initialized player. The nonce binds the response to a client assertion.
                let sid=d.u64()?;let nonce=d.string(64)?;let x=d.i32()?;let y=d.i32()?;let z=d.i32()?;d.finish()?;
                if let Some(p)=self.players.get(&sid).filter(|p|p.initialized) {
                    let mut e=Encoder::new(0x89);e.u64(sid);e.string(&nonce);e.vector(p.position);e.u64(self.ticks);
                    e.i32(x);e.i32(y);e.i32(z);e.u8(self.world.get_block(x,y,z));ipc::write_frame(out,&e.0)?;
                }
            },
            10=>{
                // Permission checking is the trusted gateway's responsibility. The engine repeats bounds/reach checks.
                let sid=d.u64()?;let nonce=d.string(64)?;let x=d.i32()?;let y=d.i32()?;let z=d.i32()?;let id=d.u8()?;d.finish()?;
                if let Some(p)=self.players.get(&sid).filter(|p|p.initialized) {
                    let target=crate::math::Vector3::new(x as f32+0.5,y as f32,z as f32+0.5);
                    if !World::valid(x,y,z,id) || p.position.distance_squared(target)>32.0*32.0 {
                        let mut e=Encoder::new(0x8b);e.u64(sid);e.string(&nonce);e.string("Edit outside allowed bounds/reach");ipc::write_frame(out,&e.0)?;
                    } else {
                        let previous=self.world.set_block(x,y,z,id)?;
                        let mut e=Encoder::new(0x8a);e.u64(sid);e.string(&nonce);e.i32(x);e.i32(y);e.i32(z);e.u8(id);e.u8(previous);ipc::write_frame(out,&e.0)?;
                    }
                }
            },
            11=>{
                let sid=d.u64()?;let nonce=d.string(64)?;
                let action=d.u8()?;let x=d.i32()?;let y=d.i32()?;let z=d.i32()?;
                let face=d.u8()? as i8;let block=d.u8()?;d.finish()?;
                if let Some(p)=self.players.get(&sid).filter(|p|p.initialized) {
                    let request=Interaction{action,x,y,z,face,block};
                    let positions:Vec<_>=self.players.values().map(|p|p.position).collect();
                    match request.validate(&self.world,p.position,&positions) {
                        Ok((tx,ty,tz,id))=>{
                            let previous=self.world.set_block(tx,ty,tz,id)?;
                            let mut e=Encoder::new(0x8a);e.u64(sid);e.string(&nonce);e.i32(tx);e.i32(ty);e.i32(tz);e.u8(id);e.u8(previous);ipc::write_frame(out,&e.0)?;
                        },
                        Err(reason)=>{
                            let mut e=Encoder::new(0x8c);e.u64(sid);e.string(&nonce);e.string(reason);
                            let target=request.target().unwrap_or((x,y,z));
                            // Correct both the clicked block and the predicted placement, in one response.
                            e.u8(2);
                            for (tx,ty,tz) in [(x,y,z),target] {e.i32(tx);e.i32(ty);e.i32(tz);e.u8(self.world.get_block(tx,ty,tz));}
                            ipc::write_frame(out,&e.0)?;
                        }
                    }
                }
            },
            12=>{
                let sid=d.u64()?;d.finish()?;
                let spawn=self.world.spawn();
                if let Some(p)=self.players.get_mut(&sid).filter(|p|p.initialized) {
                    p.teleport(spawn);
                    let mut e=Encoder::new(0x8d);e.u64(sid);e.vector(spawn);e.f32(p.pitch);e.f32(p.yaw);e.u64(p.last_client_tick.unwrap_or(0));ipc::write_frame(out,&e.0)?;
                }
            },
            _=>return Err(io::Error::new(io::ErrorKind::InvalidData,"unknown IPC opcode")),
        }
        Ok(true)
    }
}
