import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('source_check', Path(__file__).resolve().parents[1] / 'tools/check-source.py')
source_check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source_check)


class SourceCompletenessTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.manifest = self.root / 'manifest.json'

    def write(self, files):
        self.manifest.write_text(json.dumps({'format': 1, 'files': files}))

    def test_complete_source(self):
        self.write(['tests/unit.php'])
        (self.root / 'tests').mkdir()
        (self.root / 'tests/unit.php').write_text('<?php')
        self.assertEqual(source_check.check(self.root, self.manifest), [])

    def test_original_missing_unit_failure(self):
        self.write(['tests/unit.php', 'src/main.rs'])
        self.assertEqual(source_check.check(self.root, self.manifest), ['src/main.rs', 'tests/unit.php'])

    def test_directory_is_not_a_file(self):
        self.write(['tests/unit.php'])
        (self.root / 'tests/unit.php').mkdir(parents=True)
        self.assertEqual(source_check.check(self.root, self.manifest), ['tests/unit.php'])

    def test_duplicate_empty_and_invalid_manifests(self):
        for paths in [[], ['a', 'a'], [1], '../bad']:
            self.write(paths)
            with self.assertRaises(ValueError):
                source_check.check(self.root, self.manifest)

    def test_path_traversal_is_rejected(self):
        for path in ['../other', '/tmp/file', 'a/../b', 'a\\b', './a', '.git/config', '', '.']:
            self.write([path])
            with self.assertRaises(ValueError):
                source_check.check(self.root, self.manifest)

    def test_external_symlink_is_rejected(self):
        self.write(['outside'])
        (self.root / 'outside').symlink_to(self.root.parent)
        with self.assertRaises(ValueError):
            source_check.check(self.root, self.manifest)


if __name__ == '__main__':
    unittest.main()
