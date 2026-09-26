use crate::math::Vector3;
use std::time::Instant;
pub struct Player {
    pub sid: u64,
    pub runtime_id: u64,
    pub name: String,
    pub uuid: String,
    pub authenticated: bool,
    pub position: Vector3,
    pub pitch: f32,
    pub yaw: f32,
    pub last_client_tick: Option<u64>,
    pub last_move: Instant,
    pub initialized: bool,
}
impl Player {
    pub fn new(sid: u64, runtime_id: u64, name: String, uuid: String, authenticated: bool, position: Vector3) -> Self {
        Self { sid, runtime_id, name, uuid, authenticated, position, pitch:0.0, yaw:0.0,
            last_client_tick: None, last_move: Instant::now(), initialized:false }
    }
    pub fn teleport(&mut self, position: Vector3) {
        self.position=position; self.last_move=Instant::now();
    }
    /// Creative flight / movement bounds. NOT vanilla physics or a complete anti-cheat.
    pub fn move_to(&mut self, p: Vector3, pitch: f32, yaw: f32, tick: u64) -> bool {
        if !p.is_finite() || !pitch.is_finite() || !yaw.is_finite() { return false; }
        if self.last_client_tick.is_some_and(|old| tick <= old) { return false; }
        let elapsed = self.last_move.elapsed().as_secs_f32().clamp(0.05, 1.0);
        let allowed = 4.0 + elapsed * 20.0;
        if p.x.abs() > 100_000.0 || p.z.abs() > 100_000.0 || p.y < -80.0 || p.y > 318.0 ||
            self.position.distance_squared(p) > allowed * allowed { return false; }
        self.position = p;
        self.pitch = pitch.clamp(-90.0,90.0); self.yaw = yaw.rem_euclid(360.0);
        self.last_client_tick = Some(tick); self.last_move=Instant::now(); true
    }
}
#[cfg(test)] mod tests {
    use super::*;
    #[test] fn rejects_non_finite_and_world_bounds() {
        let mut p=Player::new(1,1,"a".into(),"b".into(),false,Vector3::new(0.5,64.0,0.5));
        assert!(!p.move_to(Vector3::new(f32::NAN,64.0,0.5),0.0,0.0,1));
        assert!(!p.move_to(Vector3::new(0.5,-81.0,0.5),0.0,0.0,1));
        assert!(p.move_to(Vector3::new(0.6,64.0,0.5),0.0,0.0,1));
        assert!(!p.move_to(Vector3::new(0.7,64.0,0.5),0.0,0.0,1));
    }
}
