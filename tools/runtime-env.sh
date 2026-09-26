#!/usr/bin/env bash
# Sourced before launching system tools. A private PHP SDK must not shadow
# libsqlite3/libcurl/OpenSSL used by Node, curl, Cargo or Python.
mpe_clean_runtime_env() {
  local part cleaned='' had=0
  if [[ ${LD_LIBRARY_PATH+x} ]]; then
    IFS=: read -r -a mpe_library_parts <<< "$LD_LIBRARY_PATH"
    for part in "${mpe_library_parts[@]}"; do
      case "$part" in
        */.runtime/php/bin/php7/lib|*/.runtime/php/bin/php7/lib/) had=1 ;;
        '') ;;
        *) cleaned="${cleaned:+$cleaned:}$part" ;;
      esac
    done
    if (( had )); then
      if [[ -n $cleaned ]]; then export LD_LIBRARY_PATH="$cleaned"; else unset LD_LIBRARY_PATH; fi
    fi
    unset mpe_library_parts
  fi
}
mpe_clean_runtime_env
