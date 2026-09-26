#!/usr/bin/env python3
"""Create a dedicated ONLINE LAN configuration; never overwrite a working server.json."""
import argparse
import json
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[1]
p = argparse.ArgumentParser()
p.add_argument('--version', default='1.26.30', help='Exact version or protocol from the included catalog')
p.add_argument('--port', type=int, default=19132)
p.add_argument('--loopback', action='store_true')
o = p.parse_args()
catalog = json.loads(ROOT.joinpath('resources/protocol-catalog.json').read_text())
v = next((v for v in catalog if o.version in (v['version'], str(v['protocol']))), None)
if v is None or not 1 <= o.port <= 65535:
    p.error('Version must exist in resources/protocol-catalog.json; port must be 1..65535')
if v.get('server_status') == 'blocked-unverified-data':
    p.error('No verified version-specific NetherGames data for this profile. Start with 1.26.30 / 1001; schema presence alone is insufficient.')
config = json.loads(ROOT.joinpath('server.example.json').read_text())
config.update({'host': '127.0.0.1' if o.loopback else '0.0.0.0', 'port': o.port,
               'protocols': [v['protocol']], 'advertise-protocol': v['protocol'],
               'experimental-codecs': v['protocol'] > 1001,
               'online-mode': True, 'encryption': True, 'test-mode': False, 'max-players': 1,
               'allow-building': True, 'playtest-log': True,
               'world-directory': str(ROOT / 'data/world-playtest'),
               'server-name': 'MPE Creative Playtest ' + v['version']})
target = ROOT / ('server.playtest-' + str(v['protocol']) + '-' + str(o.port) + '.json')
if target.exists():
    existing = json.loads(target.read_text())
    if existing != config:
        p.error(str(target.name) + ' was edited; refusing to overwrite it. Run with MPE_CONFIG pointing to it, or rename it.')
else:
    target.write_text(json.dumps(config, indent=2) + '\n')
print(str(target))
print('Online authentication + encryption enabled. Test on a private LAN; no router ports are opened.', file=sys.stderr)
