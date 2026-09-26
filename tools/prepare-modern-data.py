#!/usr/bin/env python3
"""Fetch pinned 2193 inputs and build a separate bundle; native profiles are untouched."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import urllib.request
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
LIMIT = 16 * 1024 * 1024

def verify(data, entry):
    digest = hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()
    if len(data) != entry['bytes'] or digest != entry['git_blob_sha1']:
        raise ValueError('Pinned upstream blob mismatch: ' + entry['path'])

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--protocol', type=int)
    p.add_argument('--if-config', action='store_true')
    args = p.parse_args()
    if args.if_config:
        config = ROOT / os.environ.get('MPE_CONFIG', 'server.json')
        if not config.exists(): config = ROOT / 'server.example.json'
        if 2193 not in json.loads(config.read_text()).get('protocols', []): return 0
    elif args.protocol != 2193:
        p.error('Only 2193 (26.50/26.51) is implemented, not 12193')
    subprocess.run(['node', '-e', 'if(Number(process.versions.node.split(".")[0])<24)throw Error("2193 requires Node >=24")'], check=True)
    if not (ROOT/'node_modules/bedrock-protocol').is_dir():
        subprocess.run(['bash', str(ROOT/'tools/node-install.sh')], cwd=ROOT, check=True)
    spec_path = ROOT/'resources/modern/2193.sources.json'
    spec = json.loads(spec_path.read_text())
    dest = ROOT/'.runtime/bedrock/2193'
    source = dest/'source'
    source.mkdir(parents=True, exist_ok=True)
    for name, entry in spec['inputs'].items():
        target = source/name
        if target.exists():
            verify(target.read_bytes(), entry)
            continue
        url = 'https://raw.githubusercontent.com/' + entry['repository'] + '/' + entry['commit'] + '/' + entry['path']
        request = urllib.request.Request(url, headers={'User-Agent':'MPECore-versioned-assets/1'})
        with urllib.request.urlopen(request, timeout=60) as response:
            if urllib.parse.urlparse(response.geturl()).hostname != 'raw.githubusercontent.com':
                raise ValueError('Unexpected data download host')
            data = response.read(LIMIT+1)
        if len(data)>LIMIT: raise ValueError('Asset size limit')
        verify(data, entry)
        with tempfile.NamedTemporaryFile(dir=source, delete=False) as f:
            temp=Path(f.name); f.write(data)
        temp.replace(target)
        print('Fetched verified 2193 input:', name, flush=True)
    with tempfile.TemporaryDirectory(dir=dest, prefix='import-') as temp:
        output = Path(temp)
        subprocess.run(['node', str(ROOT/'tools/import-2193.cjs'), str(source), str(output)], cwd=ROOT, check=True)
        outputs = {f.name:{'bytes':f.stat().st_size,'sha256':hashlib.sha256(f.read_bytes()).hexdigest()} for f in output.iterdir() if f.is_file()}
        receipt={'schema':1,'protocol':2193,'source_manifest_sha256':hashlib.sha256(spec_path.read_bytes()).hexdigest(),'outputs':outputs}
        for f in output.iterdir(): os.replace(f,dest/f.name)
        receipt_path=dest/'bundle.json.tmp'
        receipt_path.write_text(json.dumps(receipt,indent=2)+'\n')
        os.replace(receipt_path,dest/'bundle.json')
    print('Prepared dedicated 2193 palette/items. This does not verify a Minecraft login.')
    return 0

if __name__=='__main__':
    try: sys.exit(main())
    except (OSError, ValueError, subprocess.SubprocessError) as e:
        print('MODERN DATA FAILED:', e, file=sys.stderr); sys.exit(1)
