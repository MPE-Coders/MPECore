#!/usr/bin/env python3
"""Print the selected Node runtime (or --bin directory); prepare it locally if needed."""
import argparse
from pathlib import Path
import subprocess
import sys
from lib.node_runtime import ensure_node

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bin', action='store_true')
    parser.add_argument('--no-download', action='store_true')
    args = parser.parse_args()
    try:
        node = ensure_node(Path(__file__).resolve().parents[1], allow_download=not args.no_download)
        print(node.parent if args.bin else node)
        return 0
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        print('NODE RUNTIME FAILED: ' + str(error), file=sys.stderr)
        return 1

if __name__ == '__main__': sys.exit(main())
