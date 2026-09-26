#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)/tools/runtime-env.sh"
command -v npm >/dev/null || { echo 'Node.js >=24 and npm are required.' >&2; exit 2; }
node -e 'if(Number(process.versions.node.split(".")[0])<24){console.error("bedrock-protocol 3.60.1 requires Node >=24");process.exit(2)}'
[[ -f package-lock.json ]] || { echo 'Incomplete source checkout: package-lock.json is missing.' >&2; exit 2; }
npm ci --ignore-scripts --omit=optional
node -e 'for(const name of ["bedrock-protocol","minecraft-data"]) console.log(name,require(name+"/package.json").version)'
