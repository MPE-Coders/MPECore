#!/usr/bin/env python3
"""Fetch a PHP runtime over HTTPS; require SHA-256 before extracting/executing it.
The default trust source is GitHub's release asset digest, not a baked-in hash of a moving tag.
An explicit MPE_PHP_ARCHIVE_URL must be paired with MPE_PHP_ARCHIVE_SHA256.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tarfile
import tempfile
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / '.runtime/php'
NAME = 'PHP-8.4-Linux-x86_64-PM5.tar.gz'
META = 'https://api.github.com/repos/pmmp/PHP-Binaries/releases/tags/pm5-php-8.4-latest'

def fetch(url):
    if not url.startswith('https://'):
        raise ValueError('Only HTTPS runtime downloads are allowed')
    return urlopen(Request(url, headers={'User-Agent': 'MPE-Core-runtime-installer/0.4.0',
                                        'Accept': 'application/vnd.github+json'}), timeout=60)

def safe_extract(archive, destination):
    base = destination.resolve()
    def inside(path):
        resolved = path.resolve()
        return resolved == base or base in resolved.parents
    with tarfile.open(archive, 'r:gz') as tar:
        members = tar.getmembers()
        if sum(m.size for m in members) > 600 * 1024 * 1024:
            raise ValueError('Runtime extraction limit')
        for m in members:
            p = base / m.name
            if not inside(p) or m.isdev() or m.isfifo():
                raise ValueError('Unsafe runtime archive member')
            if m.issym() and not inside(p.parent / m.linkname):
                raise ValueError('Unsafe archive symlink')
            if m.islnk() and not inside(base / m.linkname):
                raise ValueError('Unsafe archive hard link')
        if sys.version_info >= (3, 12):
            tar.extractall(base, filter='data')
        else:
            for m in members:
                if not inside(base / m.name):
                    raise ValueError('Archive path crosses a symlink')
                tar.extract(m, base)

def main():
    url = os.environ.get('MPE_PHP_ARCHIVE_URL')
    expected = os.environ.get('MPE_PHP_ARCHIVE_SHA256')
    metadata = {}
    if bool(url) != bool(expected):
        raise ValueError('Set BOTH MPE_PHP_ARCHIVE_URL and MPE_PHP_ARCHIVE_SHA256')
    if not url:
        with fetch(META) as response:
            release = json.loads(response.read(8 * 1024 * 1024))
        asset = next((a for a in release.get('assets', []) if a.get('name') == NAME), None)
        if not asset:
            raise ValueError('Expected PHP asset is not in the selected release')
        digest = asset.get('digest') or ''
        if not digest.startswith('sha256:'):
            raise ValueError('GitHub supplies no SHA256 digest. Use an independently verified URL + SHA256, or MPE_PHP. Refusing unchecked execution.')
        expected = digest[7:]
        url = asset['browser_download_url']
        metadata = {'release_id': release['id'], 'tag': release['tag_name'], 'asset_id': asset['id']}
    if not re.fullmatch(r'[0-9a-fA-F]{64}', expected):
        raise ValueError('Invalid SHA256')
    ROOT.joinpath('.runtime').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='php-fetch-', dir=ROOT / '.runtime') as tmp:
        folder = Path(tmp)
        archive = folder / 'runtime.tar.gz'
        h = hashlib.sha256()
        size = 0
        print('Fetching verified local PHP runtime...', file=sys.stderr)
        with fetch(url) as response, archive.open('wb') as out:
            while True:
                data = response.read(1024 * 1024)
                if not data:
                    break
                size += len(data)
                if size > 200 * 1024 * 1024:
                    raise ValueError('Runtime download limit')
                h.update(data)
                out.write(data)
        if h.hexdigest() != expected.lower():
            raise ValueError('PHP archive SHA256 mismatch; not extracted')
        extracted = folder / 'extracted'
        extracted.mkdir()
        safe_extract(archive, extracted)
        if not extracted.joinpath('bin/php7/bin/php').is_file():
            raise ValueError('Unexpected runtime layout')
        if DEST.exists():
            raise ValueError('Runtime directory already exists; remove the incomplete directory explicitly')
        shutil.move(str(extracted), str(DEST))
        ROOT.joinpath('.runtime/php-artifact.json').write_text(json.dumps({**metadata,
            'url': url, 'sha256': h.hexdigest(), 'size': size}, indent=2) + '\n')

if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        print('PHP runtime installation failed: ' + str(e), file=sys.stderr)
        sys.exit(1)
