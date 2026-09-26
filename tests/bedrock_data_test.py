#!/usr/bin/env python3
"""Independent synthetic NBT / historical Git tests. Not a Bedrock client or vendor test."""
import json
from pathlib import Path
import struct
import subprocess
import sys
import tempfile
import unittest
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from lib.bedrock_data import NetworkNBT, DataError, inspect_palette, protocol_constants, Source

def u(n):
    b=bytearray()
    while n>=128:b.append((n&127)|128);n>>=7
    b.append(n);return bytes(b)
def st(s):
    b=s.encode();return u(len(b))+b
def named(t,k,v):return bytes([t])+st(k)+v
def block(name,props=b''):
    return b'\x0a\0'+named(8,'name',st(name))+named(10,'states',props+b'\0')+b'\0'
DEFS=[{'id':0,'variants':[{'name':'minecraft:air','states':{}}]},
      {'id':1,'variants':[{'name':'minecraft:grass','states':{}}]}]
CODEC='''<?php class ProtocolInfo {
 const PROTOCOL_1_20_80 = 671;
 const PROTOCOL_1_26_30 = 1001;
 const CURRENT_PROTOCOL = self::PROTOCOL_1_26_30;
 const ACCEPTED_PROTOCOL = [self::PROTOCOL_1_20_80, 1001,];
}'''
class BedrockDataTests(unittest.TestCase):
    def test_runtime_id_is_position_not_sorted_name(self):
        data=block('minecraft:grass')+block('minecraft:air')
        r=inspect_palette(data,[4,0],DEFS,True)
        self.assertEqual(r['canonical_runtime_map'],{0:1,1:0})
        self.assertEqual(r['entries'][0]['metadata'],4)
    def test_byte_and_int_states_remain_distinct(self):
        b=block('test:b',named(1,'x',b'\x01'))+block('test:b',named(3,'x',u(2)))
        defs=[{'id':0,'variants':[{'name':'test:b','states':{'x':[1,1]}}]},
              {'id':1,'variants':[{'name':'test:b','states':{'x':[3,1]}}]}]
        self.assertEqual(inspect_palette(b,[0,1],defs)['canonical_runtime_map'],{0:0,1:1})
    def test_truncated_and_duplicate_states_fail(self):
        with self.assertRaises(DataError):inspect_palette(block('minecraft:air')[:-1],[0],DEFS[:1])
        with self.assertRaises(DataError):inspect_palette(block('minecraft:air')*2,[0,0],DEFS[:1])
    def test_meta_and_missing_mapping_fail(self):
        data=block('minecraft:air')
        for meta in [[],[True],[0,1]]:
            with self.assertRaises(DataError):inspect_palette(data,meta,DEFS[:1])
        with self.assertRaises(DataError):inspect_palette(data,[0],DEFS)
    def test_varint_overflow_and_negative_collection_rejected(self):
        with self.assertRaises(DataError):NetworkNBT(b'\xff'*5).uint()
        with self.assertRaises(DataError):NetworkNBT(b'\x01').count()
        with self.assertRaises(DataError):list(NetworkNBT(b'\x0a\0\x07'+st('x')+b'\x01').roots())
    def test_duplicate_compound_member_and_wrong_root_rejected(self):
        bad=b'\x0a\0'+named(1,'x',b'\0')+named(1,'x',b'\1')+b'\0'
        with self.assertRaises(DataError):list(NetworkNBT(bad).roots())
        with self.assertRaises(DataError):list(NetworkNBT(b'\x09\0\0\0').roots())
    def test_protocol_history_is_explicit_not_interval(self):
        r=protocol_constants(CODEC)
        self.assertEqual(r['accepted_protocols'],[671,1001]);self.assertNotIn(672,r['accepted_protocols'])
        self.assertEqual(r['current_protocol'],1001)
    def test_parser_never_evaluates_expressions_or_cycles(self):
        for source in [CODEC.replace('1001,','shell_exec("bad"),'),CODEC.replace('self::PROTOCOL_1_26_30;', 'self::CURRENT_PROTOCOL;'), '<?php const CURRENT_PROTOCOL=1;']:
            with self.assertRaises(DataError):protocol_constants(source)
    def test_read_only_historical_git_snapshot(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t)
            def git(*args):return subprocess.run(['git','-C',t,*args],check=True,capture_output=True,text=True).stdout.strip()
            git('init','-q');git('config','user.name','Fixture');git('config','user.email','fixture@example.invalid')
            (p/'sample.json').write_text('old');git('add','.');git('commit','-qm','fixture');ref=git('rev-parse','HEAD')
            (p/'sample.json').write_text('new')
            src=Source(p,ref);self.assertEqual(src.read('sample.json'),b'old');self.assertEqual((p/'sample.json').read_text(),'new')
            self.assertEqual(src.files(),['sample.json'])
            with self.assertRaises(DataError):src.read('../escape')
    def test_audit_crosschecks_actual_php_report_fields(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);(p/'codec/src').mkdir(parents=True);(p/'data').mkdir()
            (p/'codec/src/ProtocolInfo.php').write_text(CODEC)
            defs=json.loads((ROOT/'resources/blocks.json').read_text())
            content=b''
            for d in defs:
                v=d['variants'][0];props=b''
                for key,typed in (v['states'].items() if v['states'] else []):
                    tag,value=typed
                    payload=st(value) if tag==8 else struct.pack('<b',value) if tag==1 else u((value<<1)^(value>>31))
                    props+=named(tag,key,payload)
                content+=block(v['name'],props)
            profile=json.loads((ROOT/'resources/protocols/1001.json').read_text())
            for key in ['block_palette','block_meta','items','entity_identifiers','biomes']:
                value=content if key=='block_palette' else json.dumps([0]*len(defs)).encode() if key=='block_meta' else b'{"minecraft:air":{"runtime_id":-158,"component_based":false}}' if key=='items' else b'fixture'
                (p/'data'/profile[key]).write_bytes(value)
            cmd=[sys.executable,str(ROOT/'tools/upstream-audit.py'),'--data-root',str(p/'data'),'--codec-root',str(p/'codec')]
            done=subprocess.run(cmd,capture_output=True,text=True);self.assertEqual(done.returncode,0,done.stdout+done.stderr)
            entry=json.loads(done.stdout)['profiles']['1001'];palette=entry['palette']
            loaded={'1001':{'sha256':palette['sha256'],'count':palette['states'],'canonical_runtime_map':palette['canonical_runtime_map'],'profile_assets':entry['assets']}}
            path=p/'loaded.json';path.write_text(json.dumps(loaded))
            checked=subprocess.run(cmd+['--compare-loaded',str(path)],capture_output=True,text=True)
            self.assertEqual(checked.returncode,0,checked.stdout+checked.stderr)
            loaded['1001']['count']+=1;path.write_text(json.dumps(loaded))
            bad=subprocess.run(cmd+['--compare-loaded',str(path)],capture_output=True,text=True)
            self.assertNotEqual(bad.returncode,0);self.assertIn('disagrees',bad.stdout)
    def test_source_symlink_escape_is_rejected(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);(p/'src').mkdir();(p/'secret').write_text('private');(p/'src/link').symlink_to(p/'secret')
            with self.assertRaises(DataError):Source(p/'src').read('link')
    def test_audit_cli_uses_fixtures_and_records_no_official_client(self):
        with tempfile.TemporaryDirectory() as t:
            p=Path(t);(p/'codec/src').mkdir(parents=True);(p/'data').mkdir()
            (p/'codec/src/ProtocolInfo.php').write_text(CODEC)
            (p/'data/recipe.json').write_text('{}')
            cmd=[sys.executable,str(ROOT/'tools/upstream-audit.py'),'--data-root',str(p/'data'),'--codec-root',str(p/'codec'),'--inventory-only']
            ok=subprocess.run(cmd,capture_output=True,text=True);self.assertEqual(ok.returncode,0,ok.stderr)
            self.assertFalse(json.loads(ok.stdout)['official_client_tested'])
            no=subprocess.run(cmd[:-1],capture_output=True,text=True);self.assertNotEqual(no.returncode,0)
            self.assertFalse(json.loads(no.stdout)['success'])
if __name__=='__main__':unittest.main(verbosity=2)
