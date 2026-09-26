#!/usr/bin/env bash
# Sourced by start.sh; never changes the system PHP installation.
set -euo pipefail
php_ok() {
  "$1" -r 'exit(PHP_VERSION_ID>=80200 && PHP_INT_SIZE===8 && extension_loaded("encoding") && extension_loaded("openssl") && extension_loaded("sockets") && extension_loaded("zlib") ? 0:1);' >/dev/null 2>&1
}
MPE_PHP_ARGS=()
if [[ -n "${MPE_PHP:-}" ]]; then
  [[ -x "$MPE_PHP" ]] || { echo 'MPE_PHP must be an executable PHP binary path.' >&2; exit 1; }
elif command -v php >/dev/null && php_ok "$(command -v php)"; then
  MPE_PHP="$(command -v php)"
else
  [[ "$(uname -s):$(uname -m)" == 'Linux:x86_64' ]] || {
    echo 'Automatic runtime download supports Linux x86_64. Set MPE_PHP to PHP >=8.2 with ext-encoding 1.x on other systems.' >&2; exit 1;
  }
  BASE="$ROOT/.runtime/php"
  if [[ ! -x "$BASE/bin/php7/bin/php" ]]; then
    command -v python3 >/dev/null || { echo 'Install python3.' >&2; exit 1; }
    python3 "$ROOT/tools/fetch-php-runtime.py"
  fi
  MPE_PHP="$BASE/bin/php7/bin/php"
  [[ -x "$MPE_PHP" ]] || { echo 'Unexpected PMMP archive layout; set MPE_PHP manually.' >&2; exit 1; }
  EXT="$(find "$BASE" -type f -name '*encoding*.so' -printf '%h\n' | head -n1)"
  if [[ -z "$EXT" ]]; then
    EXT="$(find "$BASE/bin/php7/lib/php/extensions" -mindepth 1 -maxdepth 1 -type d | head -n1)"
  fi
  [[ -n "$EXT" ]] || { echo 'PHP extension directory not found.' >&2; exit 1; }
  MPE_PHP_ARGS=(-d "extension_dir=$EXT")
  export LD_LIBRARY_PATH="$BASE/bin/php7/lib:${LD_LIBRARY_PATH:-}"
fi
"$MPE_PHP" "${MPE_PHP_ARGS[@]}" -r '
  $missing=[];foreach(["encoding","openssl","sockets","json","zlib"] as $e){if(!extension_loaded($e)){$missing[]=$e;}}
  if(PHP_VERSION_ID<80200 || PHP_INT_SIZE!==8 || $missing){fwrite(STDERR,"Need 64-bit PHP >=8.2 and extensions: ".implode(",",$missing)."\n");exit(1);}
  if(!function_exists("proc_open")){fwrite(STDERR,"proc_open is required\n");exit(1);}
'
