use crate::block::{BlockStateId, AIR};
pub const MIN_SECTION: i8 = -4;
pub const TOP_SECTION: i8 = 3;
pub const SECTION_COUNT: usize = (TOP_SECTION - MIN_SECTION + 1) as usize;
#[derive(Clone)]
pub struct ChunkSection { pub y: i8, pub blocks: Box<[BlockStateId; 4096]> }
impl ChunkSection {
    pub fn empty(y: i8) -> Self { Self { y, blocks: Box::new([AIR;4096]) } }
    pub const fn index(x: usize, y: usize, z: usize) -> usize { (x << 8) | (z << 4) | y }
}
pub struct Chunk { pub x: i32, pub z: i32, pub sections: Vec<ChunkSection> }
