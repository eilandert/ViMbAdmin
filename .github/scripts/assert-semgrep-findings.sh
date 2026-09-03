#!/usr/bin/env bash
set -euo pipefail

fixture_seed=
fixture=
result=
cleanup() {
  rm -f -- "${fixture_seed:-}" "${fixture:-}" "${result:-}"
}
trap cleanup EXIT

fixture_seed=$(mktemp "${TMPDIR:-/tmp}/semgrep-negative.XXXXXX")
fixture="${fixture_seed}.php"
mv -- "$fixture_seed" "$fixture"
fixture_seed=
result=$(mktemp "${TMPDIR:-/tmp}/semgrep-negative.XXXXXX")
# shellcheck disable=SC2016 # This is the literal PHP fixture source.
printf '%s\n' '<?php system($_GET["code"]);' >"$fixture"

set +e
semgrep scan \
  "$@" \
  --json --output "$result" --error "$fixture"
status=$?
set -e

if [[ $status -ne 1 ]]; then
  printf '::error::Semgrep negative control returned %d.\n' "$status"
  printf '%s\n' '::error::Expected finding status 1.'
  exit 1
fi
if ! grep -q '"check_id"' "$result"; then
  printf '%s\n' '::error::Semgrep negative control produced no finding.'
  exit 1
fi
