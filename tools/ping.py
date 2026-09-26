#!/usr/bin/env python3
"""Harmless RakNet unconnected ping. A pong is NOT proof of successful player login."""
import argparse, socket, struct, time
parser=argparse.ArgumentParser();parser.add_argument('host',nargs='?',default='127.0.0.1');parser.add_argument('--port',type=int,default=19132);a=parser.parse_args()
magic=bytes.fromhex('00ffff00fefefefefdfdfdfd12345678')
with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:
    s.settimeout(3);s.sendto(b'\x01'+struct.pack('>q',int(time.time()*1000))+magic+struct.pack('>q',123456),(a.host,a.port))
    data,_=s.recvfrom(65535)
    if len(data)<35 or data[0]!=0x1c or data[17:33]!=magic: raise SystemExit('Unexpected RakNet pong')
    n=struct.unpack_from('>H',data,33)[0];print(data[35:35+n].decode('utf8',errors='replace'))
