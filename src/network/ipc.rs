use std::io::{self, Read, Write};
use crate::math::Vector3;
pub const MAX_FRAME: usize = 1_048_576;
pub fn read_frame(input: &mut impl Read) -> io::Result<Option<Vec<u8>>> {
    let mut header=[0u8;4];
    match input.read(&mut header[..1]) { Ok(0)=>return Ok(None), Ok(_)=>{}, Err(e)=>return Err(e) }
    input.read_exact(&mut header[1..])?;
    let len=u32::from_le_bytes(header) as usize;
    if len==0 || len>MAX_FRAME { return Err(io::Error::new(io::ErrorKind::InvalidData,"invalid IPC frame size")); }
    let mut bytes=vec![0;len]; input.read_exact(&mut bytes)?; Ok(Some(bytes))
}
pub fn write_frame(output: &mut impl Write, data: &[u8]) -> io::Result<()> {
    if data.is_empty() || data.len()>MAX_FRAME { return Err(io::Error::new(io::ErrorKind::InvalidInput,"IPC frame size")); }
    output.write_all(&(data.len() as u32).to_le_bytes())?; output.write_all(data)
}
pub struct Decoder<'a> { data: &'a [u8], pos: usize }
impl<'a> Decoder<'a> {
    pub fn new(data: &'a [u8]) -> Self { Self {data,pos:0} }
    pub fn take(&mut self,n:usize)->io::Result<&'a [u8]> {
        if n>self.data.len().saturating_sub(self.pos) { return Err(io::Error::new(io::ErrorKind::UnexpectedEof,"IPC truncated")); }
        let r=&self.data[self.pos..self.pos+n];self.pos+=n;Ok(r)
    }
    pub fn u8(&mut self)->io::Result<u8>{Ok(self.take(1)?[0])}
    pub fn u64(&mut self)->io::Result<u64>{Ok(u64::from_le_bytes(self.take(8)?.try_into().unwrap()))}
    pub fn i32(&mut self)->io::Result<i32>{Ok(i32::from_le_bytes(self.take(4)?.try_into().unwrap()))}
    pub fn f32(&mut self)->io::Result<f32>{Ok(f32::from_le_bytes(self.take(4)?.try_into().unwrap()))}
    pub fn vector(&mut self)->io::Result<Vector3>{Ok(Vector3::new(self.f32()?,self.f32()?,self.f32()?))}
    pub fn string(&mut self,max:usize)->io::Result<String>{
        let n=u32::from_le_bytes(self.take(4)?.try_into().unwrap()) as usize;
        if n>max { return Err(io::Error::new(io::ErrorKind::InvalidData,"IPC string too long")); }
        String::from_utf8(self.take(n)?.to_vec()).map_err(|_|io::Error::new(io::ErrorKind::InvalidData,"IPC UTF-8"))
    }
    pub fn finish(self)->io::Result<()> {
        if self.pos!=self.data.len(){Err(io::Error::new(io::ErrorKind::InvalidData,"IPC trailing data"))}else{Ok(())}
    }
}
pub struct Encoder(pub Vec<u8>);
impl Encoder {
    pub fn new(op:u8)->Self{Self(vec![op])}
    pub fn u8(&mut self,v:u8){self.0.push(v)}
    pub fn u64(&mut self,v:u64){self.0.extend(v.to_le_bytes())}
    pub fn i32(&mut self,v:i32){self.0.extend(v.to_le_bytes())}
    pub fn f32(&mut self,v:f32){self.0.extend(v.to_le_bytes())}
    pub fn string(&mut self,v:&str){self.0.extend((v.len() as u32).to_le_bytes());self.0.extend(v.as_bytes())}
    pub fn vector(&mut self,v:Vector3){self.f32(v.x);self.f32(v.y);self.f32(v.z)}
}
#[cfg(test)] mod tests {
    use super::*; use std::io::Cursor;
    #[test] fn frame_and_primitives() {
        let mut e=Encoder::new(1);e.u64(42);e.string("Привет");
        let mut wire=vec![];write_frame(&mut wire,&e.0).unwrap();
        let bytes=read_frame(&mut Cursor::new(wire)).unwrap().unwrap();let mut d=Decoder::new(&bytes);
        assert_eq!(d.u8().unwrap(),1);assert_eq!(d.u64().unwrap(),42);assert_eq!(d.string(100).unwrap(),"Привет");d.finish().unwrap();
    }
    #[test] fn rejects_invalid_length(){assert!(read_frame(&mut Cursor::new(vec![255u8;4])).is_err())}
}
