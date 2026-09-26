"""Independent, bounded Bedrock data inspection. No PHP, npm or network dependency.
Network NBT uses LE shorts/floats, signed zigzag varints for ints/longs,
and unsigned varints for string lengths. Tag types remain part of state identity.
"""
from __future__ import annotations
import hashlib
import json
import re
import struct
import subprocess
from pathlib import Path
from typing import Iterator

MAX_BYTES = 64 * 1024 * 1024

class DataError(ValueError):
    pass

class NetworkNBT:
    def __init__(self, data: bytes):
        if len(data) > MAX_BYTES:
            raise DataError('NBT byte limit')
        self.data, self.offset, self.nodes = data, 0, 0

    def take(self, n: int) -> bytes:
        if n < 0 or self.offset + n > len(self.data):
            raise DataError(f'Truncated NBT at byte {self.offset}')
        start = self.offset
        self.offset += n
        return self.data[start:self.offset]

    def uint(self, bits: int = 32) -> int:
        result = 0
        for shift in range(0, bits, 7):
            byte = self.take(1)[0]
            result |= (byte & 127) << shift
            if not byte & 128:
                if result >= (1 << bits):
                    raise DataError('Varint overflow')
                return result
        raise DataError('Overlong varint')

    def sint(self, bits: int = 32) -> int:
        u = self.uint(bits)
        return (u >> 1) ^ -(u & 1)

    def string(self) -> str:
        size = self.uint()
        if size > 1024 * 1024:
            raise DataError('NBT string length limit')
        try:
            return self.take(size).decode('utf-8', errors='strict')
        except UnicodeDecodeError as exc:
            raise DataError('Invalid NBT UTF-8') from exc

    def count(self) -> int:
        n = self.sint()
        if not 0 <= n <= 1_000_000:
            raise DataError('NBT collection length limit')
        return n

    def payload(self, tag: int, depth: int = 0):
        self.nodes += 1
        if depth > 64 or self.nodes > 5_000_000:
            raise DataError('NBT complexity limit')
        if tag == 1:
            return struct.unpack('<b', self.take(1))[0]
        if tag == 2:
            return struct.unpack('<h', self.take(2))[0]
        if tag == 3:
            return self.sint()
        if tag == 4:
            return self.sint(64)
        if tag in (5, 6):
            return struct.unpack('<f' if tag == 5 else '<d', self.take(4 if tag == 5 else 8))[0]
        if tag == 7:
            return self.take(self.count()).hex()
        if tag == 8:
            return self.string()
        if tag == 9:
            element = self.take(1)[0]
            n = self.count()
            if element == 0 and n:
                raise DataError('Nonempty TAG_End list')
            if element > 12:
                raise DataError('Unknown list element type')
            return [element, [self.payload(element, depth + 1) for _ in range(n)]]
        if tag == 10:
            children = {}
            while True:
                child = self.take(1)[0]
                if child == 0:
                    return children
                name = self.string()
                if name in children:
                    raise DataError('Duplicate NBT compound key: ' + name)
                children[name] = [child, self.payload(child, depth + 1)]
        if tag in (11, 12):
            return [self.sint(32 if tag == 11 else 64) for _ in range(self.count())]
        raise DataError(f'Unsupported NBT tag {tag}')

    def roots(self) -> Iterator[dict]:
        count = 0
        while self.offset < len(self.data):
            if self.take(1)[0] != 10:
                raise DataError('Palette root must be TAG_Compound, not a wrapping TAG_List')
            self.string()  # Root name is not part of block state identity.
            yield self.payload(10)
            count += 1
            if count > 200_000:
                raise DataError('Palette root count limit')


def state_key(name: str, states: dict) -> str:
    # JSON [] for empty properties matches the PHP boundary, without losing types.
    return name + '|' + json.dumps(states if states else [], sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)


def inspect_palette(data: bytes, meta: list, definitions: list, export: bool = False) -> dict:
    if not isinstance(meta, list) or any(type(x) is not int for x in meta):
        raise DataError('Metadata must be an integer list')
    seen, names, matches, entries = set(), set(), {}, []
    wanted = {}
    for definition in definitions:
        for variant in definition['variants']:
            wanted.setdefault(state_key(variant['name'], variant['states']), []).append(definition['id'])
    count = 0
    for runtime_id, root in enumerate(NetworkNBT(data).roots()):
        name = root.get('name')
        states = root.get('states')
        if not name or name[0] != 8 or not states or states[0] != 10:
            raise DataError('Palette entry lacks typed name/states')
        key = state_key(name[1], states[1])
        if key in seen:
            raise DataError('Duplicate typed state: ' + key)
        seen.add(key); names.add(name[1])
        for canonical in wanted.get(key, []):
            if canonical in matches:
                raise DataError('Ambiguous canonical block mapping')
            matches[canonical] = runtime_id
        if export:
            entries.append({'runtime_id':runtime_id, 'name':name[1], 'states':states[1]})
        count += 1
    if not count or count != len(meta):
        raise DataError(f'Palette/meta count mismatch: {count} / {len(meta)}')
    if set(matches) != {d['id'] for d in definitions}:
        raise DataError('Missing exact canonical states: ' + str(sorted({d['id'] for d in definitions} - set(matches))))
    report = {'sha256':hashlib.sha256(data).hexdigest(), 'states':count, 'block_names':len(names),
              'canonical_runtime_map':dict(sorted(matches.items())),
              'canonical_metadata':{k:meta[v] for k,v in sorted(matches.items())}}
    if export:
        for e in entries:
            e['metadata'] = meta[e['runtime_id']]
        report['entries'] = entries
    return report


def protocol_constants(source: str) -> dict:
    # Parse only literal constants/constant references. Never execute upstream PHP.
    source = re.sub(r'/\*.*?\*/|//[^\n]*', '', source, flags=re.S)
    values = dict(re.findall(r'\bconst\s+(\w+)\s*=\s*(\d+|self::\w+)\s*;', source))
    resolved = {}
    def value(name, active=()):
        if name in resolved:
            return resolved[name]
        if name in active or name not in values:
            raise DataError('Unresolved/cyclic protocol constant: ' + name)
        literal = values[name]
        v = value(literal[6:], active + (name,)) if literal.startswith('self::') else int(literal)
        resolved[name] = v
        return v
    match = re.search(r'\bconst\s+ACCEPTED_PROTOCOLS?\s*=\s*\[(.*?)\]\s*;', source, re.S)
    if not match:
        raise DataError('No explicit ACCEPTED_PROTOCOL list in this snapshot')
    accepted = []
    for token in match.group(1).split(','):
        token = token.strip()
        if not token:
            continue
        if re.fullmatch(r'self::\w+', token):
            n = value(token[6:])
        elif token.isdecimal():
            n = int(token)
        else:
            raise DataError('Unsupported accepted-protocol expression: ' + token)
        if n not in accepted:
            accepted.append(n)
    return {'current_protocol':value('CURRENT_PROTOCOL'), 'accepted_protocols':accepted,
            'versions':{value(k):k.removeprefix('PROTOCOL_').replace('_','.') for k in values if k.startswith('PROTOCOL_')}}


class Source:
    """Read a checked-out tree, or a read-only immutable historical Git commit."""
    def __init__(self, root: Path, reference: str | None = None):
        self.root = root.resolve()
        self.reference = None
        if not self.root.is_dir():
            raise DataError('Missing source directory: ' + str(root))
        if reference is not None:
            self.reference = self.git('rev-parse', '--verify', '--end-of-options', reference + '^{commit}').decode().strip()
            if not re.fullmatch('[a-f0-9]{40}', self.reference):
                raise DataError('Not an immutable Git commit')

    def git(self, *args) -> bytes:
        try:
            return subprocess.run(['git','-C',str(self.root),*args],check=True,capture_output=True,timeout=60).stdout
        except (OSError, subprocess.SubprocessError) as exc:
            raise DataError('Cannot read Git snapshot: ' + str(exc)) from exc

    def read(self, name: str) -> bytes:
        path = Path(name)
        if path.is_absolute() or '..' in path.parts or '\\' in name or '\0' in name:
            raise DataError('Unsafe source path')
        if self.reference:
            data = self.git('show', self.reference + ':' + name)
        else:
            resolved = (self.root/path).resolve()
            if not resolved.is_relative_to(self.root) or not resolved.is_file():
                raise DataError('Missing/outside-source asset: ' + name)
            if resolved.stat().st_size > MAX_BYTES:
                raise DataError('Asset byte limit')
            data = resolved.read_bytes()
        if len(data) > MAX_BYTES:
            raise DataError('Asset byte limit')
        return data

    def files(self) -> list[str]:
        if self.reference:
            return self.git('ls-tree','-r','--name-only',self.reference).decode().splitlines()
        return sorted(str(p.relative_to(self.root)) for p in self.root.rglob('*') if p.is_file() and '.git' not in p.relative_to(self.root).parts)
