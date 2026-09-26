#!/usr/bin/env python3
"""Summarize server-observed evidence. Does not certify an official client's rendering or inputs."""
import argparse
import json
from pathlib import Path
p = argparse.ArgumentParser(description=__doc__)
p.add_argument('journal', type=Path)
a = p.parse_args()
if not a.journal.is_file():
    p.error('Journal file does not exist')
if a.journal.stat().st_size > 20 * 1024 * 1024:
    p.error('Journal exceeds 20 MiB limit')
sessions = {}
for i, line in enumerate(a.journal.read_text(errors='replace').splitlines(), 1):
    try:
        v = json.loads(line)
    except json.JSONDecodeError:
        p.error(f'Invalid JSON on line {i}; no success report was generated')
    key = f"{v.get('run', 'legacy')}:{v['sid']}"
    s = sessions.setdefault(key, {'protocol': v['protocol'], 'last_phase': '',
                                 'events': {}, 'last_details': {}, 'server_observed': {}})
    s['protocol'] = v['protocol']
    s['last_phase'] = v['phase']
    event = v['event']
    s['events'][event] = s['events'].get(event, 0) + 1
    s['last_details'][event] = v.get('detail', '')
for s in sessions.values():
    e = s['events']
    s['server_observed'] = {'client_initialized': bool(e.get('initialized')),
                            'movement_accepted': bool(e.get('movement_accepted')),
                            'block_action_committed': bool(e.get('interaction_committed'))}
print(json.dumps({'scope': 'server-observed only; client build, graphics, UI and official-client identity are NOT verified',
                  'sessions': sessions}, indent=2, ensure_ascii=False))
