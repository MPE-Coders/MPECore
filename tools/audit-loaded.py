#!/usr/bin/env python3
"""Independently audit all PHP-loaded profiles, including the separate 2193 bundle."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from lib.bedrock_data import NetworkNBT, state_key, DataError
ROOT=Path(__file__).resolve().parents[1]

def modern(loaded):
    root=ROOT/'.runtime/bedrock/2193'
    profile=json.loads((ROOT/'resources/protocols/2193.json').read_text())
    bundle=json.loads((root/'bundle.json').read_text())
    spec=ROOT/'resources/modern/2193.sources.json'
    if bundle.get('protocol')!=2193 or bundle.get('source_manifest_sha256')!=hashlib.sha256(spec.read_bytes()).hexdigest():
        raise DataError('2193 source receipt mismatch')
    for name,entry in json.loads(spec.read_text())['inputs'].items():
        data=(root/'source'/name).read_bytes()
        digest=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
        if len(data)!=entry['bytes'] or digest!=entry['git_blob_sha1']:raise DataError('2193 input mismatch: '+name)
    assets={}
    for key in ['block_palette','block_meta','items','entity_identifiers','biomes']:
        name=profile[key];data=(root/name).read_bytes()
        actual={'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}
        if actual!=bundle['outputs'].get(name):raise DataError('2193 output mismatch: '+name)
        assets[key]={'file':name,**actual}
    definitions=json.loads((ROOT/'resources/blocks.json').read_text())
    wanted={}
    for d in definitions:
        for v in d['variants']:wanted.setdefault(state_key(v['name'],v['states']),[]).append(d['id'])
    matches={};seen=set();count=0
    for index,root_tag in enumerate(NetworkNBT((root/profile['block_palette']).read_bytes()).roots()):
        name=root_tag.get('name');states=root_tag.get('states')
        if not name or name[0]!=8 or not states or states[0]!=10:raise DataError('Invalid typed palette root')
        key=state_key(name[1],states[1])
        if key in seen:raise DataError('Duplicate 2193 state')
        seen.add(key);count+=1
        for canonical in wanted.get(key,[]):
            if canonical in matches:raise DataError('Ambiguous canonical state')
            matches[canonical]=index
    if set(matches)!={d['id'] for d in definitions}:raise DataError('Missing canonical states')
    meta=json.loads((root/profile['block_meta']).read_text())
    digest=assets['block_palette']['sha256']
    if meta!={'kind':'ordered-states-without-legacy-meta','states':count,'palette_sha256':digest}:raise DataError('2193 ordered-state manifest mismatch')
    mapping=loaded.get('canonical_runtime_map')
    mapping=dict(enumerate(mapping)) if isinstance(mapping,list) else {int(k):v for k,v in mapping.items()}
    if (loaded.get('sha256')!=digest or loaded.get('count')!=count or mapping!=matches or loaded.get('profile_assets')!=assets):
        raise DataError('Independent 2193 parser disagrees with PHP')
    items=json.loads((root/profile['items']).read_text());ids=set()
    for name,item in items.items():
        value=item.get('runtime_id')
        if not name.startswith('minecraft:') or type(value) is not int or not -32768<=value<=32767 or value in ids or type(item.get('component_based')) is not bool:raise DataError('Invalid 2193 item registry')
        ids.add(value)
    return {'protocol':2193,'states':count,'items':len(items),'canonical_runtime_map':matches,'sha256':digest,'php_comparison':True,'legacy_metadata':None,'biomes':'plains-only'}

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--compare-loaded',type=Path,required=True)
    p.add_argument('--output',type=Path,required=True)
    a=p.parse_args();report={'success':False,'official_client_tested':False,'profiles':{}}
    try:
        loaded=json.loads(a.compare_loaded.read_text())
        natives=[k for k in loaded if k!='2193']
        if natives:
            with tempfile.TemporaryDirectory() as t:
                out=Path(t)/'native.json'
                cmd=[sys.executable,str(ROOT/'tools/upstream-audit.py'),'--compare-loaded',str(a.compare_loaded),'--output',str(out)]
                for key in natives:cmd+=['--protocol',key]
                result=subprocess.run(cmd,check=False)
                native=json.loads(out.read_text())
                if result.returncode or not native.get('success'):raise DataError(native.get('error','Native palette audit failed'))
                report['profiles'].update(native['profiles'])
        if '2193' in loaded:report['profiles']['2193']=modern(loaded['2193'])
        if not report['profiles']:raise DataError('No loaded profiles')
        report['success']=True
    except (OSError,ValueError,TypeError,KeyError) as e:report['error']=str(e)
    a.output.parent.mkdir(parents=True,exist_ok=True)
    a.output.write_text(json.dumps(report,indent=2)+'\n')
    if not report['success']:print('DATA AUDIT FAILED:',report.get('error'),file=sys.stderr)
    return 0 if report['success'] else 1
if __name__=='__main__':sys.exit(main())
