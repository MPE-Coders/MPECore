#[derive(Clone, Copy, Debug, PartialEq)]
pub struct Vector3 { pub x: f32, pub y: f32, pub z: f32 }
impl Vector3 {
    pub const fn new(x: f32, y: f32, z: f32) -> Self { Self { x, y, z } }
    pub fn is_finite(self) -> bool { self.x.is_finite() && self.y.is_finite() && self.z.is_finite() }
    pub fn distance_squared(self, other: Self) -> f32 {
        (self.x-other.x).powi(2)+(self.y-other.y).powi(2)+(self.z-other.z).powi(2)
    }
    pub fn chunk(self) -> (i32, i32) {
        ((self.x.floor() as i32).div_euclid(16), (self.z.floor() as i32).div_euclid(16))
    }
}
#[cfg(test)] mod tests {
    use super::*;
    #[test] fn negative_chunk_coordinates() {
        assert_eq!(Vector3::new(-0.1, 64.0, -16.1).chunk(), (-1,-2));
        assert!(!Vector3::new(f32::NAN,0.0,0.0).is_finite());
    }
}
