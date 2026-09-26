#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
source "$ROOT/tools/runtime-env.sh"
MODE="${1:-run}"
case "$MODE" in
  --audit-data) shift; exec python3 "$ROOT/tools/upstream-audit.py" "$@";;
  --verify) shift; exec python3 "$ROOT/tools/verify.py" "$@";;
  --playtest) shift; exec "$ROOT/tools/playtest.sh" "$@";;
  --check-source) exec python3 "$ROOT/tools/check-source.py";;
  --help|-h)
    printf '%s\n' './start.sh              Build missing/outdated Rust binary and start' \
      './start.sh --playtest --version 26.51  Online LAN test world (protocol 2193)' \
      './start.sh --check-source Check that all tracked source files are present' \
      './start.sh --unit       PHP + JS + Python standalone tests (not a login test)' \
      './start.sh --cross-codec Real Prismarine -> NetherGames cross-codec checks' \
      './tools/e2e.sh --version 1.26.51  Real isolated server + client test' \
      './start.sh --audit-data Audit exact installed native NBT/data profiles independently' \
      './start.sh --verify --version 1.26.51  Full gate, report unrun stages honestly' \
      './start.sh --doctor     Install dependencies, verify profiles/packet encoders' \
      './start.sh --test       PHP + Rust + IPC + installed codec tests' \
      './start.sh --refresh-auth Refresh trusted Minecraft public keys' \
      './start.sh --no-build   Run existing release binary; still check dependencies'
    exit 0;;
  --unit)
    python3 "$ROOT/tools/check-source.py"
    "${MPE_PHP:-php}" "$ROOT/tests/unit.php"
    "${MPE_PHP:-php}" "$ROOT/tests/packet_factory_unit.php"
    node --test "$ROOT"/test-client/test/*.test.cjs
    python3 -m unittest discover -s "$ROOT/tests" -p '*_test.py' -v
    exit 0;;
  run|--doctor|--cross-codec|--test|--refresh-auth|--no-build) ;;
  *) echo "Unknown option: $MODE (./start.sh --help)" >&2; exit 2;;
esac
python3 "$ROOT/tools/check-source.py"
if [[ "$MODE" == run || "$MODE" == --test ]]; then
  command -v cargo >/dev/null || { echo 'Rust/Cargo >=1.74 required. On Ubuntu: sudo apt install build-essential cargo' >&2; exit 1; }
fi
source "$ROOT/tools/php-runtime.sh"
if [[ ! -f "$ROOT/vendor/autoload.php" ]]; then source "$ROOT/tools/composer-install.sh"; fi
[[ -n "${MPE_CONFIG:-}" || -f "$ROOT/server.json" ]] || cp "$ROOT/server.example.json" "$ROOT/server.json"
python3 "$ROOT/tools/prepare-native-metadata.py" --if-config
python3 "$ROOT/tools/prepare-modern-data.py" --if-config
if [[ "$MODE" == --refresh-auth ]]; then exec "${MPE_PHP_CMD[@]}" "$ROOT/gateway/bootstrap.php" --refresh-auth; fi
if [[ "$MODE" == --cross-codec ]]; then
  node "$ROOT/tools/codec-doctor.cjs" --out="$ROOT/.runtime/client-packets.json"
  exec "${MPE_PHP_CMD[@]}" "$ROOT/tests/cross_codec.php" "$ROOT/.runtime/client-packets.json"
fi
audit_loaded() {
  python3 "$ROOT/tools/audit-loaded.py" --compare-loaded "$ROOT/data/palettes.loaded.json" --output "$ROOT/data/upstream-audit.json"
}
if [[ "$MODE" == --doctor ]]; then
  "${MPE_PHP_CMD[@]}" "$ROOT/tests/integration.php"
  audit_loaded
  exit 0
fi
if [[ "$MODE" == --test ]]; then
  "${MPE_PHP_CMD[@]}" "$ROOT/tests/unit.php"
  "${MPE_PHP_CMD[@]}" "$ROOT/tests/packet_factory_unit.php"
  node --test "$ROOT"/test-client/test/*.test.cjs
  python3 -m unittest discover -s "$ROOT/tests" -p '*_test.py' -v
  cargo test --locked
  cargo build --release --locked
  python3 "$ROOT/tests/engine_ipc.py" "$ROOT/target/release/mpe-core"
  "${MPE_PHP_CMD[@]}" "$ROOT/tests/integration.php"
  audit_loaded
  exit 0
fi
if [[ "$MODE" != --no-build ]]; then cargo build --release --locked; fi
[[ -x "$ROOT/target/release/mpe-core" ]] || { echo 'No Rust binary. Run ./start.sh without --no-build.' >&2; exit 1; }
# Fail before opening UDP if an upstream API/asset cannot be encoded.
"${MPE_PHP_CMD[@]}" "$ROOT/tests/integration.php"
audit_loaded
exec "${MPE_PHP_CMD[@]}" -d memory_limit=512M "$ROOT/gateway/bootstrap.php"
