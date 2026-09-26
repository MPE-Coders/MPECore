#!/usr/bin/env python3
"""Launch the REAL Rust binary in a temporary world. No mocked engine can pass this test."""
import os
import pathlib
import selectors
import struct
import subprocess
import sys
import tempfile
import time

EXE = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else 'target/release/mpe-core').resolve()
if not EXE.is_file():
    raise SystemExit('Rust binary missing: cargo build --release --locked first')

def string(s):
    b = s.encode()
    return struct.pack('<I', len(b)) + b

class Engine:
    def __init__(self, directory):
        self.p = subprocess.Popen([str(EXE), '--stdio'], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, env={**os.environ, 'MPE_WORLD_DIR': str(directory)})
        self.sel = selectors.DefaultSelector()
        self.sel.register(self.p.stdout, selectors.EVENT_READ)
        self.buffer = bytearray()
        assert self.receive()[0] == 0x80

    def send(self, data):
        self.p.stdin.write(struct.pack('<I', len(data)) + data)
        self.p.stdin.flush()

    def receive(self, timeout=5):
        end = time.monotonic() + timeout
        while time.monotonic() < end:
            if len(self.buffer) >= 4:
                n = struct.unpack_from('<I', self.buffer)[0]
                assert 0 < n <= 1048576, 'Invalid IPC frame size'
                if len(self.buffer) >= n + 4:
                    frame = bytes(self.buffer[4:4+n])
                    del self.buffer[:4+n]
                    return frame
            if not self.sel.select(max(0, end-time.monotonic())):
                continue
            chunk = self.p.stdout.read1(262144)
            assert chunk, 'Engine exited: ' + self.p.stderr.read().decode(errors='replace')
            self.buffer.extend(chunk)
        raise AssertionError('Engine IPC reply timeout')

    def join(self):
        self.send(b'\x01' + struct.pack('<Q', 1) + string('UnitPlayer') + string('unit-uuid') + b'\x00')
        admitted = self.receive()
        assert admitted[0] == 0x81
        assert struct.unpack_from('<fff', admitted, 17) == (0.5, 64.0, 0.5)
        self.send(b'\x08' + struct.pack('<Q', 1))
        assert self.receive()[0] == 0x88

    def query(self, x, y, z, nonce='probe'):
        self.send(b'\x09' + struct.pack('<Q', 1) + string(nonce) + struct.pack('<iii', x, y, z))
        f = self.receive()
        assert f[0] == 0x89
        length = struct.unpack_from('<I', f, 9)[0]
        assert f[13:13+length].decode() == nonce
        offset = 13 + length
        pos = struct.unpack_from('<fff', f, offset)
        xyz = struct.unpack_from('<iii', f, offset+20)
        assert xyz == (x,y,z)
        return pos, f[offset+32]

    def set(self, x, y, z, block):
        self.send(b'\x0a' + struct.pack('<Q', 1) + string('edit') + struct.pack('<iiiB', x,y,z,block))
        return self.receive()

    def interact(self, action, x, y, z, face, block=0):
        self.send(b'\x0b' + struct.pack('<Q', 1) + string('game_test') + struct.pack('<BiiibB', action, x, y, z, face, block))
        return self.receive()

    def stop(self):
        if self.p.poll() is None:
            self.send(b'\x07')
            assert self.p.wait(timeout=5) == 0
        self.sel.close()

    def close(self):
        if self.p.poll() is None:
            self.p.kill()
        self.p.wait()
        self.sel.close()

with tempfile.TemporaryDirectory(prefix='mpe-engine-test-') as directory:
    a = Engine(directory)
    try:
        a.join()
        a.send(b'\x04' + struct.pack('<Qii', 1,0,0))
        chunk = a.receive()
        assert chunk[0] == 0x83 and chunk[17] == 8
        pos=18; grass=0
        for expected in range(-4,4):
            assert struct.unpack_from('<b',chunk,pos)[0] == expected
            pos += 1
            blocks=chunk[pos:pos+4096]; pos += 4096
            assert set(blocks) <= {0,1}
            grass += blocks.count(1)
        assert grass == 1024 and pos == len(chunk)
        print('PASS actual Rust join/initialized and canonical grass chunk')
        a.send(b'\x03'+struct.pack('<QfffffQ',1,0.6,64.,0.5,0.,0.,1))
        assert a.receive()[0] == 0x85
        a.send(b'\x03'+struct.pack('<QfffffQ',1,float('nan'),64.,0.5,0.,0.,2))
        correction = a.receive()
        assert correction[0] == 0x84 and abs(struct.unpack_from('<f',correction,9)[0]-0.6)<1e-5
        assert len(correction) == 38 and correction[-1] == 1, 'Correction must include authoritative ground contact'
        print('PASS actual Rust accepted movement and prediction correction with ground flag')
        assert a.query(2,64,2)[1] == 0
        assert a.set(2,64,2,7)[0] == 0x8a
        assert a.query(2,64,2)[1] == 7
        assert a.set(2,64,2,255)[0] == 0x8b
        assert a.set(99999,64,99999,7)[0] == 0x8b
        print('PASS actual Rust persisted edit, nonce probe, ID/reach rejection')
        assert a.interact(1, 2,63,0,1,7)[0] == 0x8a
        assert a.query(2,64,0)[1] == 7
        assert a.interact(1, 2,63,0,1,7)[0] == 0x8c, 'Occupied placement was accepted'
        assert a.interact(0, 2,64,0,1)[0] == 0x8a
        assert a.query(2,64,0)[1] == 0
        assert a.interact(1, 0,63,0,1,7)[0] == 0x8c, 'Block intersects player'
        assert a.interact(1, 100,63,0,1,7)[0] == 0x8c, 'Reach bypass'
        a.send(b'\x0c' + struct.pack('<Q',1)); assert a.receive()[0] == 0x8d
        print('PASS actual Rust gameplay place, break, collision/reach rejection, safe teleport')
        a.stop()
    finally:
        a.close()
    b = Engine(directory)
    try:
        b.join()
        assert b.query(2,64,2)[1] == 7, 'Edit disappeared on REAL process restart'
        assert b.set(2,64,2,0)[0] == 0x8a
        assert b.query(2,64,2)[1] == 0
        b.stop()
        print('PASS actual Rust process restart retains journal and accepts restoration')
    finally:
        b.close()
print('PASS real Rust IPC suite; does not test Bedrock login')
