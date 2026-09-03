#!/usr/bin/env bash
#
# ZF1 has been removed. Runtime PHP must not reference a Zend Framework class.
#
set -euo pipefail

cd "${VIMBADMIN_LINT_ROOT:-$(dirname "$0")/..}"

echo "== runtime PHP contains no Zend Framework symbols =="

manifest=$(mktemp)
cleanup() {
  rm -f "$manifest"
}
trap cleanup EXIT

if ! "${VIMBADMIN_FIND:-find}" application library public bin src -type f -name '*.php' -print0 >"$manifest"; then
  echo "  -> Could not enumerate runtime PHP files." >&2
  exit 1
fi

mapfile -d '' -t files <"$manifest"
if [[ ${#files[@]} -eq 0 ]]; then
  echo "  -> Runtime PHP inventory is empty." >&2
  exit 1
fi

if grep -nE 'Zend_[A-Za-z0-9_]+' "${files[@]}"; then
  echo "  -> Zend Framework references are forbidden; ZF1 is no longer installed."
  exit 1
fi

echo "  OK: runtime PHP is Zend-free"
