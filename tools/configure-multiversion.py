#!/usr/bin/env python3
"""Enable the three regression-tested profiles in the main config. Preserve worlds/auth/bind settings."""
import argparse
import json
import os
from pathlib import Path
import tempfile
import time
ROOT=Path(__file__).resolve().parents[1]

def configure(target:Path, defaults:Path):
    if target.is_symlink():raise ValueError('Refusing a symlink configuration')
    raw=target.read_bytes() if target.exists() else None
    config=json.loads(raw) if raw is not None else json.loads(defaults.read_bytes())
    if not isinstance(config,dict):raise ValueError('Configuration must be a JSON object')
    config.update({'protocols':[975,1001,2193], 'advertise-protocol':2193, 'experimental-codecs':True})
    data=(json.dumps(config,ensure_ascii=False,indent=2)+'\n').encode()
    backup=None
    if raw is not None:
        backup=target.with_name(target.name+'.'+str(time.time_ns())+'.bak')
        fd=os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'wb') as f:f.write(raw)
    target.parent.mkdir(parents=True,exist_ok=True)
    name=None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent,delete=False) as f:
            name=Path(f.name);f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(name,0o600);os.replace(name,target)
    finally:
        if name is not None:name.unlink(missing_ok=True)
    return backup

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config',type=Path,default=Path(os.environ.get('MPE_CONFIG',ROOT/'server.json')))
    a=p.parse_args()
    try:
        backup=configure(a.config,ROOT/'server.example.json')
        print('Enabled: 1.26.20/975, 1.26.30/1001, 1.26.50-51/2193. Run ./start.sh')
        if backup:print('Previous configuration saved to',backup)
        print('Bind address, online authentication, encryption and world directory were not changed.')
    except (OSError,ValueError) as e:p.exit(1,str(e)+'\n')
if __name__=='__main__':main()
