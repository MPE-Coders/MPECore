//! Append-only, checksummed block journal. This is NOT the Bedrock LevelDB format.
//! A partial final record is discarded on restart; a complete corrupt record is fatal.
use std::fs::{self, File, OpenOptions};
use std::io::{self, Read, Seek, SeekFrom, Write};
use std::path::Path;

const MAGIC: &[u8; 8] = b"MPEWAL02";
const RECORD: usize = 17;
pub type Edit = (i32, i32, i32, u8);
pub struct Journal { file: File }
fn checksum(bytes: &[u8]) -> u32 {
    bytes.iter().fold(0x811c9dc5u32, |a, b| (a ^ *b as u32).wrapping_mul(0x01000193))
}
impl Journal {
    pub fn open(path: &Path) -> io::Result<(Self, Vec<Edit>)> {
        if let Some(parent) = path.parent() { fs::create_dir_all(parent)?; }
        let mut file = OpenOptions::new().create(true).truncate(false).read(true).write(true).open(path)?;
        if file.metadata()?.len() == 0 { file.write_all(MAGIC)?; file.sync_all()?; }
        file.seek(SeekFrom::Start(0))?;
        let mut header = [0; 8]; file.read_exact(&mut header)?;
        if &header != MAGIC { return Err(io::Error::new(io::ErrorKind::InvalidData, "Unsupported world journal header")); }
        let len = file.metadata()?.len() as usize;
        // Bound memory and loading work. Offline compaction is a later feature.
        if len > 128 * 1024 * 1024 { return Err(io::Error::new(io::ErrorKind::InvalidData, "Journal exceeds 128 MiB; offline compaction needed")); }
        let complete = (len - 8) / RECORD;
        let mut edits = Vec::with_capacity(complete);
        for _ in 0..complete {
            let mut b = [0; RECORD]; file.read_exact(&mut b)?;
            if checksum(&b[..13]) != u32::from_le_bytes(b[13..17].try_into().unwrap()) {
                return Err(io::Error::new(io::ErrorKind::InvalidData, "Corrupt world journal record; refusing silent data loss"));
            }
            let x = i32::from_le_bytes(b[0..4].try_into().unwrap());
            let y = i32::from_le_bytes(b[4..8].try_into().unwrap());
            let z = i32::from_le_bytes(b[8..12].try_into().unwrap());
            edits.push((x,y,z,b[12]));
        }
        let recovered_len = 8 + complete * RECORD;
        if recovered_len != len { file.set_len(recovered_len as u64)?; file.sync_all()?; }
        file.seek(SeekFrom::End(0))?;
        Ok((Self { file }, edits))
    }
    pub fn append(&mut self, edit: Edit) -> io::Result<()> {
        if self.file.metadata()?.len() + RECORD as u64 > 128 * 1024 * 1024 {
            return Err(io::Error::other("World journal is full"));
        }
        let (x,y,z,id) = edit;
        let mut b = Vec::with_capacity(RECORD);
        b.extend_from_slice(&x.to_le_bytes()); b.extend_from_slice(&y.to_le_bytes());
        b.extend_from_slice(&z.to_le_bytes()); b.push(id);
        b.extend_from_slice(&checksum(&b).to_le_bytes());
        self.file.write_all(&b)?; self.file.sync_data()
    }
}
#[cfg(test)] mod tests {
    use super::*;
    fn temp(label: &str) -> std::path::PathBuf {
        std::env::temp_dir().join(format!("mpe-wal-{}-{label}-{}",std::process::id(),std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()))
    }
    #[test] fn durable_record_and_partial_tail_recovery() {
        let p=temp("tail"); let (mut j, e)=Journal::open(&p).unwrap(); assert!(e.is_empty());
        j.append((-1,64,2,7)).unwrap(); drop(j);
        OpenOptions::new().append(true).open(&p).unwrap().write_all(&[1,2,3]).unwrap();
        let (j,e)=Journal::open(&p).unwrap(); assert_eq!(e,vec![(-1,64,2,7)]); drop(j);
        assert_eq!(fs::metadata(&p).unwrap().len(),25); fs::remove_file(p).unwrap();
    }
    #[test] fn corrupt_record_is_not_silently_skipped() {
        let p=temp("corrupt"); let (mut j,_)=Journal::open(&p).unwrap(); j.append((1,64,2,7)).unwrap(); drop(j);
        let mut f=OpenOptions::new().write(true).open(&p).unwrap(); f.seek(SeekFrom::Start(9)).unwrap(); f.write_all(&[0xff]).unwrap(); drop(f);
        assert!(Journal::open(&p).is_err()); fs::remove_file(p).unwrap();
    }
}
