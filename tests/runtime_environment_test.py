"""Runtime isolation regressions. Synthetic environments; CI also runs actual encrypted E2E."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[1]
class RuntimeEnvironmentTests(unittest.TestCase):
    def test_shell_removes_only_bundled_loader_paths(self):
        env={**os.environ,'LD_LIBRARY_PATH':'/opt/keep:/tmp/a/.runtime/php/bin/php7/lib:/tmp/b/.runtime/php/bin/php7/lib/:/opt/other'}
        p=subprocess.run(['bash','-c','source "$1"; printf "%s" "${LD_LIBRARY_PATH-unset}"','bash',str(ROOT/'tools/runtime-env.sh')],env=env,capture_output=True,text=True,check=True)
        self.assertEqual(p.stdout,'/opt/keep:/opt/other')
    def test_clean_shell_does_not_gain_loader_path(self):
        env={k:v for k,v in os.environ.items() if k!='LD_LIBRARY_PATH'}
        p=subprocess.run(['bash','-c','source "$1"; printf "%s" "${LD_LIBRARY_PATH-unset}"','bash',str(ROOT/'tools/runtime-env.sh')],env=env,capture_output=True,text=True,check=True)
        self.assertEqual(p.stdout,'unset')
    @unittest.skipUnless(shutil.which('php'),'PHP CLI required')
    def test_nonphp_children_do_not_inherit_private_libs_or_config(self):
        script=r'''require $argv[1];
putenv('MPE_BUNDLED_PHP_LIB=/fixture/.runtime/php/bin/php7/lib');
putenv('LD_LIBRARY_PATH=/opt/keep:/fixture/.runtime/php/bin/php7/lib:/opt/other');
putenv('OPENSSL_CONF=/fixture/openssl.cnf');
putenv('MPE_PARENT_OPENSSL_CONF_SET=');putenv('MPE_PARENT_OPENSSL_CONF=');
$native=mpe\network\ipc\ChildProcess::environment(['node']);
$php=mpe\network\ipc\ChildProcess::environment([PHP_BINARY]);
echo json_encode([$native['LD_LIBRARY_PATH'],isset($native['OPENSSL_CONF']),$php['LD_LIBRARY_PATH'],$php['OPENSSL_CONF']]);'''
        p=subprocess.run(['php','-r',script,str(ROOT/'gateway/src/network/ipc/ChildProcess.php')],check=True,capture_output=True,text=True)
        self.assertEqual(json.loads(p.stdout),['/opt/keep:/opt/other',False,'/opt/keep:/fixture/.runtime/php/bin/php7/lib:/opt/other','/fixture/openssl.cnf'])
    @unittest.skipUnless(shutil.which('php'),'PHP CLI required')
    def test_administrator_openssl_config_is_preserved(self):
        script=r'''require $argv[1];putenv('OPENSSL_CONF=/php.cnf');
putenv('MPE_PARENT_OPENSSL_CONF_SET=x');putenv('MPE_PARENT_OPENSSL_CONF=/admin.cnf');
echo mpe\network\ipc\ChildProcess::environment(['node'])['OPENSSL_CONF'];'''
        p=subprocess.run(['php','-r',script,str(ROOT/'gateway/src/network/ipc/ChildProcess.php')],check=True,capture_output=True,text=True)
        self.assertEqual(p.stdout,'/admin.cnf')
    def test_metadata_cannot_be_truncated_or_fabricated(self):
        spec=importlib.util.spec_from_file_location('metadata_correction',ROOT/'tools/prepare-native-metadata.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        for data in [b'[]',json.dumps([0]*16913).encode(),b'{}']:
            with self.assertRaises(ValueError):module.verify(data)
