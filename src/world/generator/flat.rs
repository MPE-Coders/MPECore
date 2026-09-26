use crate::block::GRASS;
use crate::world::format::{Chunk, ChunkSection, MIN_SECTION, TOP_SECTION, SECTION_COUNT};
pub struct FlatGenerator;
impl FlatGenerator {
    pub fn generate(&self, x: i32, z: i32) -> Chunk {
        let mut sections = Vec::with_capacity(SECTION_COUNT);
        for section_y in MIN_SECTION..=TOP_SECTION {
            let mut section = ChunkSection::empty(section_y);
            if section_y == 3 {
                for x in 0..16 { for z in 0..16 { for y in 12..16 {
                    section.blocks[ChunkSection::index(x,y,z)] = GRASS;
                }}}
            }
            sections.push(section);
        }
        Chunk { x, z, sections }
    }
}
#[cfg(test)] mod tests {
    use super::*;
    #[test] fn grass_surface_and_order() {
        let c = FlatGenerator.generate(-1,2);
        assert_eq!((c.x,c.z),(-1,2)); assert_eq!(c.sections.len(),8);
        assert_eq!(c.sections[0].y,-4);
        assert_eq!(c.sections[7].blocks.iter().filter(|&&v|v==GRASS).count(),1024);
        assert_eq!(c.sections[7].blocks[ChunkSection::index(7,15,9)],GRASS);
        assert_eq!(c.sections[7].blocks[ChunkSection::index(7,11,9)],0);
    }
}
