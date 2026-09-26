#!/usr/bin/env python3
"""Isolated LOCAL server + real UDP/RakNet Bedrock test client. Requires installed dependencies.
This script never edits server.json, binds a public address or stops an existing server.
"""
import argparse
import json
import os
import pathlib
import signal
import socket
import subprocess
import tempfile
import time
import sys
ROOT=pathlib.Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser()
parser.add_argument('--version', default='1.26.30')
parser.add_argument('--scenario', choices=['connect','smoke','edit','creative'], default='creative')
parser.add_argument('--timeout', type=int, default=120)
a=parser.parse_args()
if not 10 <= a.timeout <= 300:
    parser.error('--timeout must be 10..300 seconds')
catalog=json.loads((ROOT/'resources/protocol-catalog.json').read_text())
profile=next((p for p in catalog if a.version in [p['version'],str(p['protocol'])]),None)
if profile is None or profile.get('server_status')=='blocked-unverified-data':
    parser.error('Choose a version from ./client --list-protocols')
reportdir=ROOT/'tests/results/e2e'/str(profile['protocol'])
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
            result=subprocess.run(command,cwd=ROOT,timeout=a.timeout,check=False)
            if result.returncode:
                raise RuntimeError('Real Bedrock client scenario failed')
            report=json.loads((reportdir/'client.json').read_text())
            if not report.get('success') or report.get('discovery',{}).get('motd')!=marker:
                raise RuntimeError('Unexpected server/report; refusing a false positive')
            print('PASS isolated real Bedrock scenario:',profile['version'],a.scenario)
    except Exception as e:
        print('E2E FAILED:',e,file=sys.stderr);sys.exit(1)
    finally:
        if server and server.poll() is None:
            os.killpg(server.pid,signal.SIGTERM)
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid,signal.SIGKILL);server.wait()
