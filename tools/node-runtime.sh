#!/usr/bin/env bash
# Source only: activation is local to the launcher and its children, never ~/.bashrc.
mpe_node_setup() {
  local root="${ROOT:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)}" selected
  selected="$(python3 "$root/tools/node-runtime.py")" || return
  export MPE_NODE="$selected"
  export PATH="$(dirname -- "$selected"):$PATH"
  hash -r
}
