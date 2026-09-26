//! Minimal air/solid-cube movement, advanced by client input ticks, not wall-clock ticks.
//! Not a full vanilla movement implementation: no water, ladders, crawling or vehicle physics.
use super::Player;
use crate::{math::Vector3, world::World};
use std::time::Instant;

const GRAVITY:f32=0.08;
const DRAG:f32=0.98;
const JUMP:f32=0.42;

fn vertical(world:&World, p:Vector3, dy:f32)->(f32,bool) {
    // Sweep instead of testing only the endpoint: a fast fall cannot tunnel through a floor.
    let steps=(dy.abs()/0.2).ceil().max(1.0) as usize;
    let step=dy/steps as f32;
    let mut y=p.y;
    for _ in 0..steps {
        let next=Vector3::new(p.x,y+step,p.z);
        if world.collides(next) {
            let mut lo=0.0;let mut hi=1.0;
            for _ in 0..16 {
                let mid=(lo+hi)*0.5;
                if world.collides(Vector3::new(p.x,y+step*mid,p.z)){hi=mid;}else{lo=mid;}
            }
            // Snap near a whole-block collision surface; never accumulate eye-height offsets.
            y+=step*lo;
            if (y-y.round()).abs()<0.002 {y=y.round();}
            return (y,true);
        }
        y=next.y;
    }
    (y,false)
}

/// Return None for stale input; a replay must not advance gravity or reset velocity.
pub fn advance(p:&mut Player,world:&World,predicted:Vector3,pitch:f32,yaw:f32,tick:u64,flying:bool,jump:bool)->Option<bool> {
    if p.last_client_tick.is_some_and(|old|tick<=old){return None;}
    let steps=p.last_client_tick.map_or(1,|old|(tick-old).clamp(1,5)) as usize;
    let limit=4.0+p.last_move.elapsed().as_secs_f32().clamp(0.05,1.0)*20.0;
    if !predicted.is_finite()||!pitch.is_finite()||!yaw.is_finite()||
        predicted.x.abs()>100_000.0||predicted.z.abs()>100_000.0||!(-80.0..=318.0).contains(&predicted.y)||
        p.position.distance_squared(predicted)>limit*limit {
        p.last_client_tick=Some(tick);return Some(true);
    }
    p.last_client_tick=Some(tick);p.last_move=Instant::now();
    p.pitch=pitch.clamp(-90.0,90.0);p.yaw=yaw.rem_euclid(360.0);
    if flying {
        p.vertical_velocity=0.0;
        if world.collides(predicted){return Some(true);}
        p.position=predicted;return Some(false);
    }
    let old=p.position;
    for i in 1..=steps {
        let fraction=i as f32/steps as f32;
        let horizontal=Vector3::new(old.x+(predicted.x-old.x)*fraction,p.position.y,old.z+(predicted.z-old.z)*fraction);
        if !world.collides(horizontal){p.position=horizontal;}
        let grounded=world.on_ground(p.position);
        if grounded && p.vertical_velocity<=0.0 {p.vertical_velocity=if jump {JUMP}else{0.0};}
        let (y,hit)=vertical(world,p.position,p.vertical_velocity);
        p.position.y=y;
        if hit || world.on_ground(p.position){p.vertical_velocity=0.0;}
        else{p.vertical_velocity=(p.vertical_velocity-GRAVITY)*DRAG;}
    }
    Some(p.position.distance_squared(predicted)>0.12*0.12)
}

#[cfg(test)] mod tests {
    use super::*;
    fn player(y:f32)->Player{Player::new(1,1,"p".into(),"u".into(),false,Vector3::new(0.5,y,0.5))}
    #[test] fn standing_does_not_levitate(){
        let w=World::new();let mut p=player(64.0);
        for tick in 1..=400 {let predict=Vector3::new(0.5,64.03,0.5);advance(&mut p,&w,predict,0.0,0.0,tick,false,false);}
        assert_eq!(p.position.y,64.0);assert_eq!(p.vertical_velocity,0.0);
    }
    #[test] fn gravity_jump_land_and_replay(){
        let w=World::new();let mut p=player(64.0);
        let predict=p.position;advance(&mut p,&w,predict,0.0,0.0,1,false,true);
        assert!((p.position.y-64.42).abs()<0.001);
        let mut peak=p.position.y;
        for t in 2..40 {let predict=p.position;advance(&mut p,&w,predict,0.0,0.0,t,false,false);peak=peak.max(p.position.y);}
        assert!(peak>65.0 && peak<65.4);assert_eq!(p.position.y,64.0);
        let before=p.position;assert!(advance(&mut p,&w,before,0.0,0.0,39,false,true).is_none());assert_eq!(p.position.y,64.0);
    }
    #[test] fn fall_cannot_tunnel_and_flight_can_be_disabled(){
        let w=World::new();let mut p=player(90.0);
        for t in 1..80 {let predict=p.position;advance(&mut p,&w,predict,0.0,0.0,t,false,false);}
        assert_eq!(p.position.y,64.0);
        assert_eq!(advance(&mut p,&w,Vector3::new(0.5,68.0,0.5),0.0,0.0,80,true,false),Some(false));
        for t in 81..130 {let predict=p.position;advance(&mut p,&w,predict,0.0,0.0,t,false,false);}
        assert_eq!(p.position.y,64.0);
    }
}
