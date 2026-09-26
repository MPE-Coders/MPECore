pub mod format;
pub mod generator;
pub mod storage;
pub mod interaction;
use std::collections::HashMap;
use std::io;
use std::path::Path;
use crate::math::Vector3;
use crate::block::{BlockStateId, AIR, GRASS, BLOCK_COUNT};
use format::{Chunk, ChunkSection};
use generator::flat::FlatGenerator;
use storage::Journal;

/// One game-state owner. Indexed overlays avoid scanning every edit for each chunk.
pub struct World {
    generator: FlatGenerator,
    overlays: HashMap<(i32,i32), HashMap<(u8,i32,u8),BlockStateId>>,
    journal: Option<Journal>,
    edited_blocks: usize,
}
impl World {
    pub fn new() -> Self { Self { generator: FlatGenerator, overlays: HashMap::new(), journal: None, edited_blocks:0 } }
    pub fn open(path: &Path) -> io::Result<Self> {
        let (journal, edits)=Journal::open(path)?;
        let mut w=Self::new();
        for (x,y,z,id) in edits {
            if !Self::valid(x,y,z,id) { return Err(io::Error::new(io::ErrorKind::InvalidData,"Unknown block or out-of-bounds edit in journal")); }
            w.apply(x,y,z,id)?;
        }
        w.journal=Some(journal); Ok(w)
    }
    pub fn valid(x:i32,y:i32,z:i32,id:u8)->bool {
        (-100_000..=100_000).contains(&x) && (-100_000..=100_000).contains(&z) && (-64..320).contains(&y) && id < BLOCK_COUNT
    }
    pub fn spawn(&self) -> Vector3 {
        // Search nearby columns, rather than assuming old Y=64 is still above ground.
        for radius in 0i32..=8 {
            for x in -radius..=radius { for z in -radius..=radius {
                if x.abs().max(z.abs()) != radius { continue; }
                for y in (-64..318).rev() {
                    let p=Vector3::new(x as f32+0.5,y as f32+1.0,z as f32+0.5);
                    if self.get_block(x,y,z)!=AIR && !self.collides(p) { return p; }
                }
            }}
        }
        Vector3::new(0.5,64.0,0.5)
    }
    pub fn get_block(&self,x:i32,y:i32,z:i32)->BlockStateId {
        self.overlays.get(&(x.div_euclid(16),z.div_euclid(16)))
            .and_then(|c|c.get(&(x.rem_euclid(16) as u8,y,z.rem_euclid(16) as u8))).copied()
            .unwrap_or(if (60..64).contains(&y) {GRASS} else {AIR})
    }
    fn apply(&mut self,x:i32,y:i32,z:i32,id:u8)->io::Result<()> {
        let c=self.overlays.entry((x.div_euclid(16),z.div_euclid(16))).or_default();
        let key=(x.rem_euclid(16) as u8,y,z.rem_euclid(16) as u8);
        if !c.contains_key(&key) {
            if self.edited_blocks>=1_000_000 {return Err(io::Error::other("Edited block limit reached"));}
            self.edited_blocks+=1;
        }
        c.insert(key,id); Ok(())
    }
    pub fn set_block(&mut self,x:i32,y:i32,z:i32,id:u8)->io::Result<u8> {
        if !Self::valid(x,y,z,id) {return Err(io::Error::new(io::ErrorKind::InvalidInput,"Block/position out of range"));}
        let previous=self.get_block(x,y,z);
        if previous==id {return Ok(previous);}
        let key=(x.rem_euclid(16) as u8,y,z.rem_euclid(16) as u8);
        let exists=self.overlays.get(&(x.div_euclid(16),z.div_euclid(16))).is_some_and(|c|c.contains_key(&key));
        if !exists && self.edited_blocks>=1_000_000 {return Err(io::Error::other("Edited block limit reached"));}
        // Persist before acknowledging. A disk error stops the engine rather than acknowledging an unsaved edit.
        if let Some(j)=self.journal.as_mut(){j.append((x,y,z,id))?;}
        self.apply(x,y,z,id)?; Ok(previous)
    }
    pub fn generate(&self, x:i32,z:i32)->Chunk {
        let mut chunk=self.generator.generate(x,z);
        if let Some(edits)=self.overlays.get(&(x,z)) {
            let max=edits.keys().map(|(_,y,_)|y.div_euclid(16)).max().unwrap_or(3).max(3);
            for sy in 4..=max { chunk.sections.push(ChunkSection::empty(sy as i8)); }
            for (&(lx,y,lz),&id) in edits {
                let section=(y.div_euclid(16)+4) as usize;
                chunk.sections[section].blocks[ChunkSection::index(lx as usize,y.rem_euclid(16) as usize,lz as usize)]=id;
            }
        }
        chunk
    }
    /// Ground contact for the correction packet; not a simulation of gravity.
    pub fn on_ground(&self, p: Vector3) -> bool {
        p.is_finite() && !self.collides(p)
            && self.collides(Vector3::new(p.x, p.y - 0.02, p.z))
    }
    /// Basic solid AABB rejection, not full gravity, step-up, fluids, or vanilla movement.
    pub fn collides(&self,p:Vector3)->bool {
        if !p.is_finite(){return true;}
        let epsilon=0.001f32;
        for x in ((p.x-0.3+epsilon).floor() as i32)..=((p.x+0.3-epsilon).floor() as i32) {
            for z in ((p.z-0.3+epsilon).floor() as i32)..=((p.z+0.3-epsilon).floor() as i32) {
                for y in ((p.y+epsilon).floor() as i32)..=((p.y+1.8-epsilon).floor() as i32) {
                    if self.get_block(x,y,z)!=AIR{return true;}
                }
            }
        }
        false
    }
}
#[cfg(test)] mod tests {
    use super::*;
    #[test] fn negative_coordinates_and_high_sections() {
        let mut w=World::new(); assert_eq!(w.get_block(-1,63,-17),GRASS);
        w.set_block(-1,100,-17,7).unwrap(); let c=w.generate(-1,-2);
        assert_eq!(c.sections.len(),11); assert_eq!(c.sections[10].blocks[ChunkSection::index(15,4,15)],7);
    }
    #[test] fn ground_contact_is_not_always_true() {
        let w = World::new();
        assert!(w.on_ground(Vector3::new(0.5, 64.0, 0.5)));
        assert!(!w.on_ground(Vector3::new(0.5, 65.0, 0.5)));
        assert!(!w.on_ground(Vector3::new(0.5, 63.0, 0.5)));
        assert!(!w.on_ground(Vector3::new(f32::NAN, 64.0, 0.5)));
    }
    #[test] fn collision_and_bounds() {
        let mut w=World::new(); let original=w.spawn();assert!(!w.collides(original));
        w.set_block(0,64,0,2).unwrap(); assert!(w.collides(original));assert!(!w.collides(w.spawn()));assert_eq!(w.spawn().y,65.0);
        assert!(w.set_block(1,320,1,2).is_err()); assert!(w.set_block(1,64,1,250).is_err());
    }
}
