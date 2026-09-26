#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$(python3 "$ROOT/tools/playtest-config.py" "$@")"
export MPE_CONFIG="$CONFIG"
exec "$ROOT/start.sh"
