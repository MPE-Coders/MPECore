"""Local corruption fixtures, not a claim about downloaded Minecraft data."""
import hashlib
import importlib.util
from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('modern_prepare',ROOT/'tools/prepare-modern-data.py')
prepare=importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare)
class ModernDataTests(unittest.TestCase):
    def test_git_blob_hash_not_raw_file_hash(self):
        data=b'fixture'
        entry={'path':'fixture','bytes':len(data),'git_blob_sha1':hashlib.sha1(b'blob 7\0'+data).hexdigest()}
        prepare.verify(data,entry)
        with self.assertRaises(ValueError):prepare.verify(b'fixturE',entry)
        with self.assertRaises(ValueError):prepare.verify(data+b'x',entry)
        with self.assertRaises(ValueError):prepare.verify(data,{**entry,'git_blob_sha1':hashlib.sha1(data).hexdigest()})
    def test_native_profile_has_no_network_side_effect(self):
        import os,subprocess,sys,tempfile,json
        with tempfile.TemporaryDirectory() as d:
            config=Path(d)/'config.json';config.write_text(json.dumps({'protocols':[975]}))
            result=subprocess.run([sys.executable,str(ROOT/'tools/prepare-modern-data.py'),'--if-config'],env={**os.environ,'MPE_CONFIG':str(config)},capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr)
if __name__=='__main__':unittest.main()
