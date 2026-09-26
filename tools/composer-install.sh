#!/usr/bin/env bash
set -euo pipefail
mkdir -p "$ROOT/.runtime"
COMPOSER="$ROOT/.runtime/composer.phar"
if [[ ! -f "$COMPOSER" ]]; then
  command -v curl >/dev/null || { echo 'curl is required' >&2; exit 1; }
  EXPECTED="$(env -u LD_LIBRARY_PATH -u LD_PRELOAD curl --fail --silent --show-error --proto '=https' --tlsv1.2 https://composer.github.io/installer.sig)"
  INSTALLER="$ROOT/.runtime/composer-setup.php"
  env -u LD_LIBRARY_PATH -u LD_PRELOAD curl --fail --silent --show-error --proto '=https' --tlsv1.2 https://getcomposer.org/installer -o "$INSTALLER"
  ACTUAL="$("${MPE_PHP_CMD[@]}" -r 'echo hash_file("sha384",$argv[1]);' "$INSTALLER")"
  [[ "$EXPECTED" == "$ACTUAL" ]] || { echo 'Composer installer signature mismatch' >&2; rm -f "$INSTALLER"; exit 1; }
  "${MPE_PHP_CMD[@]}" "$INSTALLER" --install-dir="$ROOT/.runtime" --filename=composer.phar
  rm -f "$INSTALLER"
fi
# Install the checked-in transitive dependency lock; never silently update dependencies.
[[ -f "$ROOT/composer.lock" ]] || { echo 'Incomplete source checkout: composer.lock is missing.' >&2; exit 1; }
"${MPE_PHP_CMD[@]}" "$COMPOSER" install --working-dir="$ROOT" --prefer-dist --no-dev --no-interaction --no-plugins --no-scripts
"${MPE_PHP_CMD[@]}" "$COMPOSER" check-platform-reqs --working-dir="$ROOT" --no-dev
