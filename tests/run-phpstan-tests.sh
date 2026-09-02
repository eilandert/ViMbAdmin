#!/usr/bin/env bash
set -euo pipefail

manifest=$(mktemp)
cleanup() {
  rm -f "$manifest"
}
trap cleanup EXIT

git ls-files 'tests/test-phpstan-*.php' | sort >"$manifest"
mapfile -t tests <"$manifest"
for test in "${tests[@]}"; do
  php "$test"
done

bash tests/test-phpstan-ratchet.sh
bash tests/test-regenerate-phpstan-baseline.sh
