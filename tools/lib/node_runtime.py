"""Select Node >=24 or install a checksum-pinned official runtime inside this project.
No sudo, package-manager mutations, shell-profile edits or unverified extraction.
"""
from __future__ import annotations
import fcntl
import hashlib
import os
from pathlib import Path, PurePosixPath
import platform
import posixpath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import urllib.parse
import urllib.request

VERSION = '24.21.0'
# From https://nodejs.org/download/release/v24.21.0/SHASUMS256.txt.
# Changing VERSION requires reviewing and updating these immutable pins.
ARCHIVES = {
    ('Linux', 'x86_64'): ('linux-x64', 'fd8e59d5a511510f6a298afb548f18c7d2b1be404d8b4a27d94fbe49f56cb2d6'),
    ('Linux', 'aarch64'): ('linux-arm64', '6ad1325edbdb5649c379b75a237147a666c95d4f9ae8d340fef2d1575d289ad2'),
}
MAX_DOWNLOAD = 100 * 1024 * 1024
MAX_UNPACKED = 512 * 1024 * 1024


def clean_env(env=None):
    result = dict(os.environ if env is None else env)
    if 'LD_LIBRARY_PATH' in result:
        paths = [p for p in result['LD_LIBRARY_PATH'].split(':') if p and
                 not p.rstrip('/').endswith('/.runtime/php/bin/php7/lib')]
        if paths: result['LD_LIBRARY_PATH'] = ':'.join(paths)
        else: result.pop('LD_LIBRARY_PATH', None)
    return result


def probe(executable, env=None):
    try:
        p = subprocess.run([str(executable), '--version'], env=clean_env(env),
                           capture_output=True, text=True, timeout=10)
        match = re.fullmatch(r'v(\d+)\.\d+\.\d+', p.stdout.strip())
        return p.stdout.strip() if p.returncode == 0 and match and int(match[1]) >= 24 else None
    except (OSError, subprocess.SubprocessError):
        return None


def unpack(archive: Path, destination: Path, prefix: str):
    """Extract into an empty staging dir. No devices, hard links or escaping symlinks."""
    with tarfile.open(archive, 'r:*') as tar:
        entries = tar.getmembers()
        if len(entries) > 20000 or sum(m.size for m in entries) > MAX_UNPACKED:
            raise ValueError('Node archive exceeds extraction limits')
        names = set(); links = set()
        for m in entries:
            p = PurePosixPath(m.name)
            if (not p.parts or p.is_absolute() or '..' in p.parts or '\\' in m.name or
                    p.parts[0] != prefix or m.name in names):
                raise ValueError('Unsafe or duplicate Node archive path')
            names.add(m.name)
            if not (m.isdir() or m.isfile() or m.issym()):
                raise ValueError('Unsupported Node archive entry')
            if m.issym():
                target = posixpath.normpath(posixpath.join(str(p.parent), m.linkname))
                if (m.linkname.startswith('/') or '\\' in m.linkname or
                        not target.startswith(prefix + '/')):
                    raise ValueError('Node archive symlink escapes its runtime')
                links.add(p)
        for m in entries:
            p = PurePosixPath(m.name)
            if any(parent in links for parent in p.parents):
                raise ValueError('Node archive writes through a symlink')
        for m in entries:
            out = destination / m.name
            if m.isdir(): out.mkdir(parents=True, exist_ok=True)
            elif m.isfile():
                out.parent.mkdir(parents=True, exist_ok=True)
                with tar.extractfile(m) as src, out.open('xb') as dst:
                    shutil.copyfileobj(src, dst)
                out.chmod(0o755 if m.mode & 0o111 else 0o644)
        for m in entries:
            if m.issym():
                out = destination / m.name
                out.parent.mkdir(parents=True, exist_ok=True)
                out.symlink_to(m.linkname)
        for link in links:
            if not (destination / str(link)).resolve().is_relative_to((destination / prefix).resolve()):
                raise ValueError('Node archive symlink chain escapes its runtime')


class HttpsOnly(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        url = urllib.parse.urlparse(newurl)
        if url.scheme != 'https' or url.hostname != 'nodejs.org':
            raise ValueError('Unexpected Node archive redirect')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download(url, destination, expected):
    sha = hashlib.sha256(); total = 0
    opener = urllib.request.build_opener(HttpsOnly())
    request = urllib.request.Request(url, headers={'User-Agent': 'MPECore-runtime/1'})
    with opener.open(request, timeout=60) as response, destination.open('xb') as out:
        if urllib.parse.urlparse(response.geturl()).scheme != 'https':
            raise ValueError('Node runtime requires HTTPS')
        while data := response.read(1024 * 1024):
            total += len(data)
            if total > MAX_DOWNLOAD: raise ValueError('Node archive download is too large')
            sha.update(data); out.write(data)
    if sha.hexdigest() != expected:
        raise ValueError('Node archive SHA-256 mismatch; nothing was installed')


def ensure_node(root: Path, *, allow_download=True, env=None):
    env = clean_env(env)
    explicit = env.get('MPE_NODE')
    if explicit:
        candidate = Path(explicit).expanduser()
        if not candidate.is_absolute() or candidate.name != 'node' or not probe(candidate, env):
            raise ValueError('MPE_NODE must be an absolute path to a working Node >=24 bin/node')
        return candidate
    spec = ARCHIVES.get((platform.system(), platform.machine()))
    target = root / '.runtime/node' / ('node-v' + VERSION + '-' + spec[0]) if spec else None
    if target and target.exists():
        if probe(target/'bin/node', env) != 'v' + VERSION:
            raise ValueError('Managed Node runtime is incomplete or has the wrong version: ' + str(target))
        return target/'bin/node'
    system = shutil.which('node', path=env.get('PATH'))
    if system and probe(system, env): return Path(system).absolute()
    if not allow_download or env.get('MPE_NO_DOWNLOAD') == '1':
        raise ValueError('Node >=24 is required. Run ./tools/node-install.sh to prepare a local runtime, or set MPE_NODE=/path/to/bin/node')
    if not spec:
        raise ValueError('Automatic Node installation supports Linux x86_64/aarch64. Set MPE_NODE to an installed Node >=24 on this platform')
    parent = target.parent
    parent.mkdir(parents=True, exist_ok=True)
    with (parent/'.install.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if target.exists():
            if probe(target/'bin/node', env) != 'v' + VERSION:
                raise ValueError('Invalid managed Node runtime: ' + str(target))
            return target/'bin/node'
        print('Preparing project-local Node v' + VERSION + '; system Node is unchanged.', file=sys.stderr, flush=True)
        with tempfile.TemporaryDirectory(dir=parent, prefix='.install-') as temp:
            temp = Path(temp); archive = temp/'node.tar.xz'
            url = 'https://nodejs.org/download/release/v' + VERSION + '/' + target.name + '.tar.xz'
            download(url, archive, spec[1])
            unpack(archive, temp, target.name)
            staging = temp/target.name
            if probe(staging/'bin/node', env) != 'v' + VERSION or not (staging/'bin/npm').is_file():
                raise ValueError('Downloaded Node cannot run on this system or npm is missing')
            (staging/'MPE-SHA256').write_text(spec[1] + '\n')
            os.replace(staging, target)
    return target/'bin/node'


def activated_env(node: Path, env=None):
    env = clean_env(env)
    env['MPE_NODE'] = str(node)
    env['PATH'] = str(node.parent) + os.pathsep + env.get('PATH', '')
    return env
