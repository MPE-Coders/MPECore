#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
command -v cargo >/dev/null || { echo 'Rust/Cargo >=1.74 required for real E2E.' >&2; exit 2; }
[[ -d node_modules/bedrock-protocol ]] || ./tools/node-install.sh
cargo build --release --locked
exec python3 tools/e2e.py "$@"
