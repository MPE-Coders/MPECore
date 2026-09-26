#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
MODE="${1:-run}"
case "$MODE" in
  --audit-data) shift; exec python3 "$ROOT/tools/upstream-audit.py" "$@";;
  --verify) shift; exec python3 "$ROOT/tools/verify.py" "$@";;
  --playtest) shift; exec "$ROOT/tools/playtest.sh" "$@";;
  --help|-h)
    printf '%s\n' './start.sh              Build missing/outdated Rust binary and start' \
      './start.sh --playtest --version 1.26.30  Online LAN test world' \
      './start.sh --unit       Dependency-free PHP + JS tests (system php/node)' \
      './start.sh --cross-codec Real Prismarine -> NetherGames cross-codec checks' \
      './tools/e2e.sh --version 1.26.30  Real isolated server + client test' \
      './start.sh --audit-data Audit exact installed NBT/data profiles independently' \
      './start.sh --verify --version 1.26.30  Full gate, report unrun stages honestly' \
      './start.sh --doctor     Install dependencies, verify profiles/packet encoders' \
      './start.sh --test       PHP + Rust + IPC + installed codec tests' \
      './start.sh --refresh-auth Refresh trusted Minecraft public keys' \
      './start.sh --no-build   Run existing release binary; still check dependencies'
    exit 0;;
  --unit) "${MPE_PHP:-php}" "$ROOT/tests/unit.php"; node --test "$ROOT"/test-client/test/*.test.cjs; python3 -m unittest discover -s "$ROOT/tests" -p '*_test.py' -v; exit 0;;
  run|--doctor|--cross-codec|--test|--refresh-auth|--no-build) ;;
  *) echo "Unknown option: $MODE (./start.sh --help)" >&2; exit 2;;
esac
if [[ "$MODE" == run || "$MODE" == --test ]]; then
  command -v cargo >/dev/null || { echo 'Rust/Cargo >=1.74 required. On Ubuntu: sudo apt install build-essential cargo' >&2; exit 1; }
fi
source "$ROOT/tools/php-runtime.sh"
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then source "$ROOT/tools/composer-install.sh"; fi
[[ -n "${MPE_CONFIG:-}" || -f "$ROOT/server.json" ]] || cp "$ROOT/server.example.json" "$ROOT/server.json"
if [[ "$MODE" == --refresh-auth ]]; then exec "$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/gateway/bootstrap.php" --refresh-auth; fi
if [[ "$MODE" == --cross-codec ]]; then
  node "$ROOT/tools/codec-doctor.cjs" --out="$ROOT/.runtime/client-packets.json"
  exec "$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/tests/cross_codec.php" "$ROOT/.runtime/client-packets.json"
fi
audit_loaded() {
  python3 "$ROOT/tools/upstream-audit.py" --compare-loaded "$ROOT/data/palettes.loaded.json" --output "$ROOT/data/upstream-audit.json"
}
if [[ "$MODE" == --doctor ]]; then
  "$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/tests/integration.php"
  audit_loaded
  exit 0
fi
if [[ "$MODE" == --test ]]; then
  "$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/tests/unit.php"
  node --test "$ROOT"/test-client/test/*.test.cjs
  python3 -m unittest discover -s "$ROOT/tests" -p '*_test.py' -v
  cargo test --locked
  cargo build --release --locked
  python3 "$ROOT/tests/engine_ipc.py" "$ROOT/target/release/mpe-core"
  "$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/tests/integration.php"
  audit_loaded
  exit 0
fi
if [[ "$MODE" != --no-build ]]; then cargo build --release --locked; fi
[[ -x "$ROOT/target/release/mpe-core" ]] || { echo 'No Rust binary. Run ./start.sh without --no-build.' >&2; exit 1; }
# Fail before opening UDP if an upstream API/asset cannot be encoded.
"$MPE_PHP" "${MPE_PHP_ARGS[@]}" "$ROOT/tests/integration.php"
audit_loaded
exec "$MPE_PHP" "${MPE_PHP_ARGS[@]}" -d memory_limit=512M "$ROOT/gateway/bootstrap.php"
