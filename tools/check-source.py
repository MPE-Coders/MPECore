#!/usr/bin/env python3
"""Check tracked source completeness; not dependency, build, or gameplay validation."""
import argparse
import json
from pathlib import Path, PurePosixPath
import sys


def check(root: Path, manifest: Path) -> list[str]:
    data = json.loads(manifest.read_text(encoding='utf-8'))
    if not isinstance(data, dict):
        raise ValueError('Source manifest must be an object')
    paths = data.get('files')
    if data.get('format') != 1 or not isinstance(paths, list) or not paths:
        raise ValueError('Invalid or empty source manifest')
    if any(not isinstance(name, str) for name in paths) or len(set(paths)) != len(paths):
        raise ValueError('Manifest paths must be unique strings')
    missing = []
    root = root.resolve()
    for name in paths:
        path = PurePosixPath(name)
        if (not name or not path.parts or path.is_absolute() or '..' in path.parts or
                path.as_posix() != name or '\\' in name or path.parts[0] == '.git'):
            raise ValueError(f'Unsafe manifest path: {name!r}')
        target = root.joinpath(*path.parts)
        if not target.resolve().is_relative_to(root):
            raise ValueError(f'Source path escapes project root: {name}')
        if not target.is_file():
            missing.append(name)
    return sorted(missing)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    options = parser.parse_args()
    root = options.root.resolve()
    manifest = root / 'resources/source-files.json'
    try:
        missing = check(root, manifest)
        if missing:
            print('INCOMPLETE SOURCE CHECKOUT:', file=sys.stderr)
            for name in missing:
                print('  missing: ' + name, file=sys.stderr)
            print('Fetch the complete MPE-Coders/MPECore main branch with git pull --ff-only, '
                  'or extract a fresh source archive. Local edits are not overwritten by this check.', file=sys.stderr)
            return 1
        count = len(json.loads(manifest.read_text(encoding='utf-8'))['files'])
        print(f'PASS source completeness: {count} required files present (not a build or login test)')
        return 0
    except (OSError, ValueError, TypeError, KeyError) as error:
        print(f'SOURCE CHECK FAILED: {error}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
