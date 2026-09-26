#!/usr/bin/env python3
"""Install the upstream 26.30 metadata correction, without modifying vendor or worlds."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
import urllib.request
import urllib.parse
ROOT=Path(__file__).resolve().parents[1]
NAME='block_state_meta_map-1.26.30.json'
COMMIT='e08720dda2f32bc7cab7f0bf5bde354f3434f56e'
BLOB='910a64d51fb1a1dbf86c5a6e959bc8b4e9686837'
URL='https://raw.githubusercontent.com/NetherGamesMC/BedrockData/'+COMMIT+'/'+NAME

def verify(data):
    digest=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
    if len(data)>512*1024 or digest!=BLOB:
        raise ValueError('26.30 metadata does not match the pinned upstream blob')
    values=json.loads(data)
    if not isinstance(values,list) or len(values)!=16913 or any(type(v) is not int for v in values):
        raise ValueError('26.30 metadata is not the exact 16913-entry integer list')
    return data

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--if-config',action='store_true')
    a=p.parse_args()
    if a.if_config:
        cfg=ROOT/os.environ.get('MPE_CONFIG','server.json')
        if not cfg.exists():cfg=ROOT/'server.example.json'
        if 1001 not in json.loads(cfg.read_text()).get('protocols',[]):return 0
    dest=ROOT/'.runtime/bedrock/1001'/NAME
    if dest.exists():verify(dest.read_bytes());return 0
    req=urllib.request.Request(URL,headers={'User-Agent':'MPECore-versioned-assets/1'})
    with urllib.request.urlopen(req,timeout=60) as response:
        if urllib.parse.urlparse(response.geturl()).hostname!='raw.githubusercontent.com':
            raise ValueError('Unexpected metadata download host')
        data=verify(response.read(512*1024+1))
    dest.parent.mkdir(parents=True,exist_ok=True)
    temp=None
    try:
        with tempfile.NamedTemporaryFile(dir=dest.parent,delete=False) as f:
            temp=Path(f.name);f.write(data)
        os.replace(temp,dest)
    finally:
        if temp is not None:temp.unlink(missing_ok=True)
    print('Prepared verified native 1001 metadata: 16913 entries; vendor unchanged.')
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except (OSError,ValueError) as e:
        print('NATIVE METADATA FAILED:',e,file=sys.stderr);sys.exit(1)
