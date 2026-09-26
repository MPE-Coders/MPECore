import hashlib
import io
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]/'tools'))
from lib import node_runtime as runtime

class NodeRuntimeTests(unittest.TestCase):
    def fake_node(self, root, version):
        node=root/'bin/node';node.parent.mkdir(parents=True,exist_ok=True)
        node.write_text('#!/bin/sh\nprintf "%s\\n" "'+version+'"\n');node.chmod(0o755)
        return node
    def test_existing_node24_is_selected_without_downloading(self):
        with tempfile.TemporaryDirectory() as d:
            node=self.fake_node(Path(d)/'system','v24.21.0')
            with patch.object(runtime,'download') as fetch:
                self.assertEqual(runtime.ensure_node(Path(d)/'project',env={'PATH':str(node.parent)}),node)
                fetch.assert_not_called()
    def test_node22_no_download_is_an_actionable_error(self):
        with tempfile.TemporaryDirectory() as d:
            node=self.fake_node(Path(d),'v22.22.1')
            with patch.object(runtime,'download') as fetch:
                with self.assertRaisesRegex(ValueError,'node-install.sh'):
                    runtime.ensure_node(Path(d),allow_download=False,env={'PATH':str(node.parent)})
                fetch.assert_not_called()
    def test_explicit_old_node_is_not_silently_overridden(self):
        with tempfile.TemporaryDirectory() as d:
            node=self.fake_node(Path(d),'v22.22.1')
            with self.assertRaisesRegex(ValueError,'MPE_NODE'):
                runtime.ensure_node(Path(d),env={'MPE_NODE':str(node)})
    def test_activation_is_a_copy_not_a_global_path_change(self):
        env={'PATH':'/usr/bin','LD_LIBRARY_PATH':'/keep:/a/.runtime/php/bin/php7/lib'}
        active=runtime.activated_env(Path('/local/bin/node'),env)
        self.assertEqual(env['PATH'],'/usr/bin')
        self.assertEqual(active['PATH'],'/local/bin:/usr/bin')
        self.assertEqual(active['LD_LIBRARY_PATH'],'/keep')
    def archive(self, root, records):
        archive=root/'fixture.tar.gz'
        with tarfile.open(archive,'w:gz') as tar:
            for name, kind, data in records:
                member=tarfile.TarInfo(name)
                if kind=='symlink':member.type=tarfile.SYMTYPE;member.linkname=data;tar.addfile(member)
                elif kind=='hardlink':member.type=tarfile.LNKTYPE;member.linkname=data;tar.addfile(member)
                else:
                    member.size=len(data);member.mode=0o755
                    tar.addfile(member,io.BytesIO(data))
        return archive
    def test_npm_relative_symlinks_remain_inside_runtime(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);archive=self.archive(root,[('node/lib/npm.js','file',b'x'),('node/bin/npm','symlink','../lib/npm.js')])
            runtime.unpack(archive,root/'out','node')
            self.assertEqual((root/'out/node/bin/npm').read_bytes(),b'x')
    def test_unsafe_archives_fail_before_extracting(self):
        cases=[[('../escape','file',b'x')],[('/tmp/escape','file',b'x')],
            [('node/link','symlink','../../escape')],[('node/link','hardlink','node/file')],
            [('node/a','symlink','b'),('node/a/evil','file',b'x')],
            [('node/a','file',b'x'),('node/a','file',b'y')]]
        for records in cases:
            with self.subTest(records=records), tempfile.TemporaryDirectory() as d:
                root=Path(d);a=self.archive(root,records)
                with self.assertRaises(ValueError):runtime.unpack(a,root/'out','node')
                self.assertFalse((root/'out').exists())
    def test_checksum_failure_prevents_unpack_and_install(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);response=io.BytesIO(b'not-the-pinned-archive')
            response.geturl=lambda:'https://nodejs.org/download/release/fixture'
            with patch.object(runtime.urllib.request,'build_opener') as opener:
                opener.return_value.open.return_value=response
                with self.assertRaisesRegex(ValueError,'SHA-256'):
                    runtime.download('https://nodejs.org/fixture',root/'archive',hashlib.sha256(b'other').hexdigest())
    def test_redirects_cannot_downgrade_tls_or_leave_official_host(self):
        for url in ['http://nodejs.org/archive','https://elsewhere.invalid/archive']:
            with self.assertRaises(ValueError):runtime.HttpsOnly().redirect_request(None,None,302,'',{},url)
