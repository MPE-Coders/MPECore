//! Server-validated creative interactions. Packet coordinates are never trusted writes.
use crate::block::{AIR, BLOCK_COUNT};
use crate::math::Vector3;
use super::World;

pub const BREAK: u8 = 0;
pub const PLACE: u8 = 1;
#[derive(Clone, Copy, Debug)]
pub struct Interaction {
    pub action: u8,
    pub x: i32, pub y: i32, pub z: i32,
    pub face: i8,
    pub block: u8,
}
impl Interaction {
    pub fn target(&self)->Option<(i32,i32,i32)> {
        if self.action==BREAK { return Some((self.x,self.y,self.z)); }
        if self.action!=PLACE {return None;}
        let (dx,dy,dz)=match self.face {0=>(0,-1,0),1=>(0,1,0),2=>(0,0,-1),3=>(0,0,1),4=>(-1,0,0),5=>(1,0,0),_=>return None};
        Some((self.x.checked_add(dx)?,self.y.checked_add(dy)?,self.z.checked_add(dz)?))
    }
    pub fn validate(&self,world:&World,feet:Vector3,players:&[Vector3])->Result<(i32,i32,i32,u8),&'static str> {
        if !feet.is_finite() || !World::valid(self.x,self.y,self.z,AIR) {return Err("Invalid clicked position");}
        let (x,y,z)=self.target().ok_or("Invalid block face/action")?;
        let eye=Vector3::new(feet.x,feet.y+1.62,feet.z);
        let center=Vector3::new(self.x as f32+0.5,self.y as f32+0.5,self.z as f32+0.5);
        // A generous creative reach, with server coordinates (never the client-supplied position).
        if eye.distance_squared(center)>6.5*6.5 {return Err("Block outside creative reach");}
        if world.get_block(self.x,self.y,self.z)==AIR {return Err("Clicked block is air");}
        // Check occlusion up to the clicked block, so a client cannot edit through a wall.
        let distance=eye.distance_squared(center).sqrt();
        let steps=(distance/0.1).ceil().max(1.0) as usize;
        for step in 1..steps {
            let t=step as f32/steps as f32;
            let bx=(eye.x+(center.x-eye.x)*t).floor() as i32;
            let by=(eye.y+(center.y-eye.y)*t).floor() as i32;
            let bz=(eye.z+(center.z-eye.z)*t).floor() as i32;
            if (bx,by,bz)==(self.x,self.y,self.z) {break;}
            if world.get_block(bx,by,bz)!=AIR {return Err("Clicked block is occluded");}
        }
        let id=if self.action==BREAK {AIR} else {self.block};
        if !World::valid(x,y,z,id) || (self.action==PLACE && (id==AIR||id>=BLOCK_COUNT)) {return Err("Invalid placement");}
        if self.action==PLACE {
            if world.get_block(x,y,z)!=AIR {return Err("Destination is occupied");}
            if players.iter().any(|p| Self::overlaps_player(x,y,z,*p)) {return Err("Placement intersects a player");}
        }
        Ok((x,y,z,id))
    }
    pub fn overlaps_player(x:i32,y:i32,z:i32,p:Vector3)->bool {
        p.x+0.3>x as f32 && p.x-0.3<(x+1) as f32 &&
        p.y+1.8>y as f32 && p.y<(y+1) as f32 &&
        p.z+0.3>z as f32 && p.z-0.3<(z+1) as f32
    }
}
#[cfg(test)] mod tests {
    use super::*;
    #[test] fn creative_place_break_reach_and_collision() {
        let mut w=World::new();let player=w.spawn();
        let place=Interaction{action:PLACE,x:2,y:63,z:0,face:1,block:7};
        assert_eq!(place.validate(&w,player,&[player]).unwrap(),(2,64,0,7));
        w.set_block(2,64,0,7).unwrap();
        assert!(place.validate(&w,player,&[player]).is_err());
        let destroy=Interaction{action:BREAK,x:2,y:64,z:0,face:1,block:0};
        assert_eq!(destroy.validate(&w,player,&[player]).unwrap(),(2,64,0,AIR));
        assert!(Interaction{x:20,..place}.validate(&w,player,&[player]).is_err());
        assert!(Interaction{x:0,..place}.validate(&w,player,&[player]).is_err());
        assert!(Interaction{face:7,..place}.validate(&w,player,&[player]).is_err());
    }
    #[test] fn invalid_coordinates_and_occlusion() {
        let mut w=World::new(); let p=w.spawn();
        let a=Interaction{action:PLACE,x:i32::MAX,y:63,z:0,face:5,block:7};
        assert!(a.target().is_none()); assert!(a.validate(&w,p,&[p]).is_err());
        w.set_block(1,65,0,2).unwrap();
        let a=Interaction{action:PLACE,x:4,y:63,z:0,face:1,block:7};
        assert!(a.validate(&w,p,&[p]).is_err());
    }
}
