#!/usr/bin/env python3
"""A reproducible verification gate. Missing tools and unrun network tests never count as success."""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from lib.node_runtime import ensure_node, activated_env
ROOT=Path(__file__).resolve().parents[1]

def version(executable):
    if not shutil.which(executable):return None
    try:
        p=subprocess.run([executable,'--version'],capture_output=True,text=True,timeout=10)
        return p.stdout.splitlines()[0] if p.returncode==0 and p.stdout else None
    except (OSError,subprocess.SubprocessError):return None

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--version',default='1.26.30')
    p.add_argument('--install-deps',action='store_true',help='Allow the normal PHP/Composer/npm installers to access the network')
    p.add_argument('--source-only',action='store_true',help='Only standalone tests; full-network result remains false')
    p.add_argument('--output',type=Path,default=ROOT/'tests/results/verify.json')
    a=p.parse_args()
    catalog=json.loads((ROOT/'resources/protocol-catalog.json').read_text())
    spelling='1.'+a.version if a.version.startswith('26.') else a.version
    chosen=next((v for v in catalog if spelling in [str(v['protocol']),v['version'],*v.get('aliases',[])]),None)
    if not chosen or chosen.get('server_status')=='blocked-unverified-data':
        p.error('Select an implemented native data profile, e.g. 1.26.30 / 1001. Unverified transcode profiles are blocked.')
    output=a.output.resolve();output.parent.mkdir(parents=True,exist_ok=True)
    logs=output.parent/(output.stem+'-logs');logs.mkdir(exist_ok=True)
    report={'schema':1,'created_at':datetime.now(timezone.utc).isoformat(),'version':'0.4.0-alpha',
            'target':chosen,'official_client_verified':False,'full_network_gate_passed':False,
            'source_only_requested':a.source_only,'tools':{k:version(k) for k in ['php','node','cargo','rustc']},'stages':[]}
    def save():
        temporary=output.with_suffix('.tmp')
        temporary.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');temporary.replace(output)
    def skip(name,reason):
        report['stages'].append({'name':name,'status':'not_run','reason':reason});print(name+': NOT RUN — '+reason,flush=True);save();return False
    def run(name,cmd,env=None,timeout=1200):
        log=logs/(name+'.log')
        row={'name':name,'command':cmd,'status':'running','log':str(log)};report['stages'].append(row);save()
        print(name+': running',flush=True)
        try:
            with log.open('w') as stream:
                done=subprocess.run(cmd,cwd=ROOT,env=env,stdout=stream,stderr=subprocess.STDOUT,timeout=timeout)
            row.update({'exit_code':done.returncode,'status':'passed' if done.returncode==0 else 'failed'})
        except (OSError,subprocess.SubprocessError) as exc:row.update({'status':'failed','error':str(exc)})
        print(name+': '+row['status'].upper(),flush=True);save();return row['status']=='passed'
    runtime_env=os.environ.copy();node_ok=False
    if not a.source_only:
        try:
            node=ensure_node(ROOT,allow_download=a.install_deps,env=runtime_env)
            runtime_env=activated_env(node,runtime_env);node_ok=True
            report['tools']['system_node']=report['tools']['node']
            report['tools']['node']=version(str(node));report['tools']['node_path']=str(node)
        except (OSError,ValueError) as error:skip('node-runtime',str(error))
    unit=run('standalone',['bash',str(ROOT/'start.sh'),'--unit'],runtime_env)
    config=json.loads((ROOT/'server.example.json').read_text());config.update({'protocols':[chosen['protocol']],'advertise-protocol':chosen['protocol'],'experimental-codecs':chosen['protocol']==2193})
    with tempfile.TemporaryDirectory(prefix='mpe-verify-') as t:
        configfile=Path(t)/'server.json';configfile.write_text(json.dumps(config));env=runtime_env.copy();env['MPE_CONFIG']=str(configfile)
        rust_ok=False;codec_ok=False;data_ok=False;client_ok=False
        cargo=report['tools']['cargo'];rust=report['tools']['rustc']
        rv=re.search(r'rustc (\d+)\.(\d+)',rust or '')
        if a.source_only:skip('rust','source-only mode')
        elif not cargo or not rv or tuple(map(int,rv.groups()))<(1,74):skip('rust','Rust/Cargo >= 1.74 are required')
        else:
            rust_ok=run('rust-tests',['cargo','test','--locked'])
            if rust_ok:rust_ok=run('rust-build',['cargo','build','--release','--locked'])
            if rust_ok:rust_ok=run('engine-ipc',[sys.executable,str(ROOT/'tests/engine_ipc.py'),str(ROOT/'target/release/mpe-core')])
        if a.source_only:skip('installed-codecs','source-only mode')
        elif not (ROOT/'vendor/autoload.php').is_file() and not a.install_deps:skip('installed-codecs','Composer/vendor absent; use --install-deps on a networked machine')
        else:codec_ok=run('installed-codecs',['bash',str(ROOT/'start.sh'),'--doctor'],env)
        if a.source_only:skip('independent-data','source-only mode')
        elif not (ROOT/'vendor/nethergamesmc/bedrock-data').is_dir():skip('independent-data','Pinned BedrockData checkout/vendor is absent')
        elif not codec_ok:skip('independent-data','Installed registry must pass before comparing it')
        else:data_ok=run('independent-data',[sys.executable,str(ROOT/'tools/audit-loaded.py'),'--compare-loaded',str(ROOT/'data/palettes.loaded.json'),'--output',str(output.parent/'upstream-audit.json')],env)
        nv=re.search(r'v(\d+)\.',report['tools']['node'] or '')
        if a.source_only:skip('real-network-e2e','source-only mode')
        elif not (unit and rust_ok and codec_ok and data_ok):skip('real-network-e2e','Rust/codec/data prerequisites did not all pass')
        elif not node_ok or not nv or int(nv[1])<24:skip('real-network-e2e','Node.js >=24 required for the pinned client')
        else:
            dependencies=(ROOT/'node_modules/bedrock-protocol').is_dir()
            if not dependencies and a.install_deps:dependencies=run('client-dependencies',['bash',str(ROOT/'tools/node-install.sh')],runtime_env)
            if not dependencies:skip('real-network-e2e','Client dependencies absent; use --install-deps')
            else:client_ok=run('real-network-e2e',['bash',str(ROOT/'tools/e2e.sh'),'--version',chosen['version'],'--scenario','creative'],runtime_env,timeout=1800)
    report['full_network_gate_passed']=bool(unit and rust_ok and codec_ok and data_ok and client_ok)
    report['standalone_passed']=unit
    report['note']='A passed headless E2E is not proof of graphics, UI or an official-client playtest.'
    save();print('Report: '+str(output),flush=True)
    if a.source_only:return 0 if unit else 1
    return 0 if report['full_network_gate_passed'] else 1
if __name__=='__main__':sys.exit(main())
