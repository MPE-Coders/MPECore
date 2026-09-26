import importlib.util,json,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('multiconfig',ROOT/'tools/configure-multiversion.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class MultiConfigTest(unittest.TestCase):
    def test_backup_and_preserve_nonprotocol_settings(self):
        with tempfile.TemporaryDirectory() as d:
            target=Path(d)/'server.json'
            raw=b'{"host":"127.0.0.1","online-mode":true,"encryption":true,"port":20000,"world-directory":"my-world","protocols":[975]}'
            target.write_bytes(raw);backup=module.configure(target,ROOT/'server.example.json')
            self.assertEqual(backup.read_bytes(),raw)
            c=json.loads(target.read_bytes());self.assertEqual(c['protocols'],[975,1001,2193]);self.assertEqual(c['port'],20000)
            self.assertTrue(c['online-mode']);self.assertTrue(c['encryption']);self.assertEqual(c['world-directory'],'my-world')
    def test_invalid_input_is_not_replaced(self):
        with tempfile.TemporaryDirectory() as d:
            target=Path(d)/'server.json';target.write_text('{invalid')
            with self.assertRaises(ValueError):module.configure(target,ROOT/'server.example.json')
            self.assertEqual(target.read_text(),'{invalid')
