#!/usr/bin/env python3
"""Isolated LOCAL server + real UDP/RakNet Bedrock test client. Requires installed dependencies.
This script never edits server.json, binds a public address or stops an existing server.
"""
import argparse
import json
import os
import pathlib
import re
import signal
import socket
import subprocess
import tempfile
import time
import sys
ROOT=pathlib.Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser()
parser.add_argument('--version', default='1.26.30')
parser.add_argument('--multi',action='store_true',help='Three simultaneous protocol clients on this one isolated server')
parser.add_argument('--scenario', choices=['connect','smoke','edit','creative'], default='creative')
parser.add_argument('--timeout', type=int, default=120)
a=parser.parse_args()
if not 10 <= a.timeout <= 300:
    parser.error('--timeout must be 10..300 seconds')
if a.version=='12193': parser.error('26.51 uses protocol 2193, not 12193')
if a.version.startswith('26.'): a.version='1.'+a.version
catalog=json.loads((ROOT/'resources/protocol-catalog.json').read_text())
profile=next((p for p in catalog if a.version in [p['version'],str(p['protocol']),*p.get('aliases',[])]),None)
if profile is None or profile.get('server_status')=='blocked-unverified-data':
    parser.error('Choose a version from ./client --list-protocols')
reportdir=ROOT/'tests/results/e2e'/('multi' if a.multi else str(profile['protocol']))
reportdir.mkdir(parents=True,exist_ok=True)
with tempfile.TemporaryDirectory(prefix='mpe-e2e-') as temp:
    temp=pathlib.Path(temp)
    with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as s:
        s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    config=json.loads((ROOT/'server.example.json').read_text())
    marker='MPE-E2E-'+os.urandom(6).hex()
    config.update({'host':'127.0.0.1','port':port,'server-name':marker,'online-mode':False,'test-mode':a.scenario=='edit',
                   'protocols':[profile['protocol']],'advertise-protocol':profile['protocol'],
                   'experimental-codecs':profile['protocol']>1001,'world-directory':str(temp/'world'),
                   'view-distance':2,'max-view-distance':2,'auth-timeout':60})
    if a.multi:config.update({'protocols':[975,1001,2193],'advertise-protocol':2193,'experimental-codecs':True})
    cfg=temp/'server.json';cfg.write_text(json.dumps(config))
    env={**os.environ,'MPE_CONFIG':str(cfg)}
    server=None
    try:
        with (reportdir/'server.log').open('w') as log:
            server=subprocess.Popen([str(ROOT/'start.sh'),'--no-build'],cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
            end=time.monotonic()+a.timeout
            ready=False
            while time.monotonic()<end:
                if server.poll() is not None:
                    raise RuntimeError('Server exited during startup; inspect '+str(reportdir/'server.log'))
                log.flush()
                if f'Listening on 127.0.0.1:{port}/UDP' in (reportdir/'server.log').read_text(errors='replace'):
                    ready=True;break
                time.sleep(0.1)
            if not ready:
                raise RuntimeError('Server startup timeout; no login test was performed')
            command=[str(ROOT/'client'),'127.0.0.1',str(port),'--version',profile['version'],'--offline',
                     '--username','MPE_E2E','--scenario',a.scenario,'--report',str(reportdir/'client.json')]
            if a.multi:command=[os.environ.get('MPE_NODE','node'),str(ROOT/'test-client/multiversion.cjs'),'127.0.0.1',str(port)]
            result=subprocess.run(command,cwd=ROOT,timeout=a.timeout,check=False)
            if result.returncode:
                raise RuntimeError('Real Bedrock client scenario failed')
            if a.multi:
                print('PASS isolated simultaneous 975/1001/2193 clients');sys.exit(0)
            report=json.loads((reportdir/'client.json').read_text())
            if not report.get('success') or report.get('discovery',{}).get('motd')!=marker:
                raise RuntimeError('Unexpected server/report; refusing a false positive')
            print('PASS isolated real Bedrock scenario:',profile['version'],a.scenario)
    except Exception as e:
        print('E2E FAILED:',e,file=sys.stderr)
        # Print bounded diagnostics from this isolated OFFLINE instance, not the
        # user's production server, raw packets, credentials or authentication cache.
        logfile=reportdir/'server.log'
        if logfile.exists():
            tail=logfile.read_bytes()[-32768:].decode('utf-8',errors='replace')
            tail=re.sub(r'eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+','[JWT REDACTED]',tail)
            tail=re.sub(r'\x1b\[[0-9;]*m','',tail)
            print('--- isolated server log tail ---\n'+tail,file=sys.stderr)
        sys.exit(1)
    finally:
        if server and server.poll() is None:
            os.killpg(server.pid,signal.SIGTERM)
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid,signal.SIGKILL);server.wait()
