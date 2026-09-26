#!/usr/bin/env python3
"""Local fixtures only: launcher configuration and archive handling; NOT a Minecraft login."""
import importlib.util
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('runtime_installer', ROOT / 'tools/fetch-php-runtime.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)

class ToolingTests(unittest.TestCase):
    def setup_tree(self, folder):
        (folder / 'tools').mkdir()
        (folder / 'resources').mkdir()
        shutil.copy(ROOT / 'tools/playtest-config.py', folder / 'tools')
        shutil.copy(ROOT / 'resources/protocol-catalog.json', folder / 'resources')
        shutil.copy(ROOT / 'server.example.json', folder)
        (folder / 'server.json').write_text('{"untouched":true}')
        return folder / 'tools/playtest-config.py'

    def test_online_lan_configuration_never_changes_main_config(self):
        with tempfile.TemporaryDirectory() as t:
            folder = Path(t); script = self.setup_tree(folder)
            proc = subprocess.run([sys.executable, str(script), '--version', '1001'], capture_output=True, text=True)
            self.assertEqual(proc.returncode, 0, proc.stderr)
            c = json.loads(Path(proc.stdout.strip()).read_text())
            self.assertEqual(c['host'], '0.0.0.0'); self.assertEqual(c['protocols'], [1001])
            self.assertTrue(c['online-mode']); self.assertTrue(c['encryption']); self.assertFalse(c['test-mode'])
            self.assertEqual(c['max-players'], 1)
            self.assertEqual(json.loads((folder / 'server.json').read_text()), {'untouched': True})
            c['port'] = 20000
            Path(proc.stdout.strip()).write_text(json.dumps(c))
            second = subprocess.run([sys.executable, str(script), '--version', '1001'], capture_output=True)
            self.assertNotEqual(second.returncode, 0)
            self.assertEqual(json.loads(Path(proc.stdout.strip()).read_text())['port'], 20000)

    def test_explicit_modern_profile_and_invalid_port(self):
        with tempfile.TemporaryDirectory() as t:
            script = self.setup_tree(Path(t))
            proc = subprocess.run([sys.executable, str(script), '--version', '1.26.51', '--loopback'], capture_output=True, text=True)
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn('No verified version-specific', proc.stderr)
            self.assertFalse(list(Path(t).glob('server.playtest-*.json')))
            invalid = subprocess.run([sys.executable, str(script), '--port', '65536'], capture_output=True)
            self.assertNotEqual(invalid.returncode, 0)

    def test_report_separates_reused_session_ids_and_not_official_identity(self):
        with tempfile.TemporaryDirectory() as t:
            journal = Path(t) / 'fixture.jsonl'
            journal.write_text('\n'.join(json.dumps({'run': run, 'sid': 1, 'protocol': 1001,
                'phase': 'PLAY', 'event': event, 'detail': 'fixture'}) for run,event in
                [('a','initialized'),('a','movement_accepted'),('b','interaction_committed')]))
            proc = subprocess.run([sys.executable, str(ROOT / 'tools/playtest-report.py'), str(journal)], capture_output=True, text=True)
            self.assertEqual(proc.returncode, 0, proc.stderr)
            r = json.loads(proc.stdout)
            self.assertEqual(len(r['sessions']), 2)
            self.assertIn('NOT verified', r['scope'])
            self.assertFalse(r['sessions']['a:1']['server_observed']['block_action_committed'])
            self.assertFalse(r['sessions']['b:1']['server_observed']['client_initialized'])
            journal.write_text('{bad')
            proc = subprocess.run([sys.executable, str(ROOT / 'tools/playtest-report.py'), str(journal)], capture_output=True)
            self.assertNotEqual(proc.returncode, 0); self.assertEqual(proc.stdout, b'')

    def test_archive_safe_regular_file_and_rejects_traversal(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t); archive = root / 'test.tar.gz'; dest = root / 'out'; dest.mkdir()
            with tarfile.open(archive, 'w:gz') as tar:
                info = tarfile.TarInfo('bin/example'); info.size = 3
                tar.addfile(info, io.BytesIO(b'abc'))
            installer.safe_extract(archive, dest)
            self.assertEqual((dest / 'bin/example').read_bytes(), b'abc')
            with tarfile.open(archive, 'w:gz') as tar:
                info = tarfile.TarInfo('../escape'); info.size = 3
                tar.addfile(info, io.BytesIO(b'abc'))
            with self.assertRaises(ValueError): installer.safe_extract(archive, dest)
            self.assertFalse((root / 'escape').exists())

    def test_archive_rejects_external_symlink_and_runtime_http(self):
        with tempfile.TemporaryDirectory() as t:
            root = Path(t); archive = root / 'test.tar.gz'; dest = root / 'out'; dest.mkdir()
            with tarfile.open(archive, 'w:gz') as tar:
                info = tarfile.TarInfo('link'); info.type = tarfile.SYMTYPE; info.linkname = '../../escape'
                tar.addfile(info)
            with self.assertRaises(ValueError): installer.safe_extract(archive, dest)
            with self.assertRaises(ValueError): installer.fetch('http://example.invalid/runtime')

if __name__ == '__main__':
    unittest.main(verbosity=2)
