#!/usr/bin/env python3
"""Audit versioned NetherGames sources; never downloads, executes PHP or enables a protocol."""
import argparse
import hashlib
import json
import sys
from pathlib import Path
from lib.bedrock_data import Source, DataError, inspect_palette, protocol_constants
ROOT = Path(__file__).resolve().parent.parent

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--data-root',type=Path,default=ROOT/'vendor/nethergamesmc/bedrock-data')
    p.add_argument('--codec-root',type=Path,default=ROOT/'vendor/nethergamesmc/bedrock-protocol')
    p.add_argument('--data-ref',help='Read an exact historic commit from a local Git clone')
    p.add_argument('--codec-ref',help='Read an exact historic commit from a local Git clone')
    p.add_argument('--protocol',type=int,action='append',help='May be repeated; default 1001')
    p.add_argument('--inventory-only',action='store_true',help='Discover history/assets only; no runtime support claim')
    p.add_argument('--export-catalog',action='store_true',help='Include all typed blockstates; does not implement their gameplay')
    p.add_argument('--output',type=Path)
    p.add_argument('--compare-loaded',type=Path,help='Require agreement with the actual PHP-loaded palette report')
    a=p.parse_args()
    try:
        loaded=json.loads(a.compare_loaded.read_text()) if a.compare_loaded else None
        data=Source(a.data_root,a.data_ref); codec=Source(a.codec_root,a.codec_ref)
        info=protocol_constants(codec.read('src/ProtocolInfo.php').decode())
        files=data.files()
        report={'schema':1,'success':False,'official_client_tested':False,
                'data_reference':data.reference, 'codec_reference':codec.reference,
                'codec':info, 'available_assets':[f for f in files if f.endswith(('.nbt','.json'))],
                'recipe_files':[f for f in files if f.startswith('recipes/') and f.endswith('.json')],
                'creative_files':[f for f in files if f.startswith('creative/') and f.endswith('.json')],
                'profiles':{}}
        if not a.inventory_only:
            if a.data_ref or a.codec_ref:
                raise DataError('Historical snapshots are discovery-only here. Create a separately reviewed legacy adapter/profile; do not apply current aliases to old data.')
            definitions=json.loads((ROOT/'resources/blocks.json').read_text())
            for protocol in a.protocol or ([int(k) for k in loaded] if loaded else [1001]):
                profile=json.loads((ROOT/f'resources/protocols/{protocol}.json').read_text())
                if protocol not in info['accepted_protocols'] or profile.get('data_status')!='explicit-nethergames-aliases':
                    raise DataError(f'No supported native codec/data pair for {protocol}')
                metadata=json.loads(data.read(profile['block_meta']))
                summary=inspect_palette(data.read(profile['block_palette']),metadata,definitions,a.export_catalog)
                items=json.loads(data.read(profile['items']))
                if not isinstance(items,dict) or not items:
                    raise DataError('Item registry must be a name map')
                ids=set()
                for name,entry in items.items():
                    if not isinstance(entry,dict) or type(entry.get('runtime_id')) is not int or type(entry.get('component_based')) is not bool:
                        raise DataError('Invalid item entry: '+name)
                    if entry['runtime_id'] in ids:
                        raise DataError('Duplicate item runtime ID')
                    ids.add(entry['runtime_id'])
                assets={}
                for key in ['block_palette','block_meta','items','entity_identifiers','biomes']:
                    payload=data.read(profile[key])
                    assets[key]={'file':profile[key],'bytes':len(payload),'sha256':hashlib.sha256(payload).hexdigest()}
                if loaded is not None:
                    entry=loaded.get(str(protocol))
                    if not isinstance(entry,dict):raise DataError('PHP has not loaded this profile')
                    mapping=entry.get('canonical_runtime_map')
                    if isinstance(mapping,list):mapping=dict(enumerate(mapping))
                    elif isinstance(mapping,dict):mapping={int(k):v for k,v in mapping.items()}
                    if (entry.get('sha256')!=summary['sha256'] or entry.get('count')!=summary['states']
                        or mapping!=summary['canonical_runtime_map'] or entry.get('profile_assets')!=assets):
                        raise DataError('Independent parser disagrees with actual PHP registry: '+str(protocol))
                report['profiles'][str(protocol)]={'version':profile['game_version'],'palette':summary,'items':len(items),'assets':assets,'php_comparison':loaded is not None}
        report['success']=True
    except (OSError, ValueError, KeyError) as exc:
        report={'schema':1,'success':False,'official_client_tested':False,'error':str(exc)}
    text=json.dumps(report,ensure_ascii=False,indent=2)+'\n'
    if a.output:
        a.output.parent.mkdir(parents=True,exist_ok=True);a.output.write_text(text)
    else:
        print(text,end='')
    return 0 if report['success'] else 1

if __name__=='__main__':
    sys.exit(main())
