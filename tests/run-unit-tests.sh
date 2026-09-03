#!/usr/bin/env bash
set -euo pipefail

# The unit container has Composer dependencies but no database service. Keep
# cache and schema tests in their dedicated jobs and PHPStan harness tests in
# static-analysis, while every other tracked PHP test runs here automatically.
readonly excluded_tests=(
  tests/test-cache-bootstrap.php
  tests/test-kernel-em-factory.php
  tests/test-kernel-smarty-view.php
  tests/test-mailbox-queue-atomic-mariadb.php
  tests/test-oss-message.php
  tests/test-schema-no-pending.php
)

if [[ ${1:-} == --print-excluded-tests ]]; then
  [[ $# -eq 1 ]] || {
    printf 'usage: %s [--print-excluded-tests|tests/test-*.php ...]\n' "$0" >&2
    exit 2
  }
  printf '%s\n' "${excluded_tests[@]}"
  exit 0
fi

is_excluded() {
  local candidate=$1 excluded
  for excluded in "${excluded_tests[@]}"; do
    [[ $candidate == "$excluded" ]] && return 0
  done
  return 1
}

manifest=$(mktemp)
cleanup() {
  rm -f "$manifest"
}
trap cleanup EXIT

if ! git ls-files 'tests/test-*.php' | sort >"$manifest"; then
  printf 'Could not enumerate tracked PHP tests.\n' >&2
  exit 1
fi

mapfile -t tracked_tests <"$manifest"
selected_tests=("${tracked_tests[@]}")
if [[ $# -gt 0 ]]; then
  selected_tests=("$@")
  for test in "${selected_tests[@]}"; do
    if [[ $test != tests/test-*.php ]] || ! grep -qxF -- "$test" "$manifest"; then
      printf 'Requested test is not a tracked PHP unit test: %s\n' "$test" >&2
      exit 2
    fi
    if [[ $test == tests/test-phpstan-*.php ]] || is_excluded "$test"; then
      printf 'Requested test belongs to another CI job: %s\n' "$test" >&2
      exit 2
    fi
  done
fi

for test in "${selected_tests[@]}"; do
  [[ $test == tests/test-phpstan-*.php ]] && continue
  is_excluded "$test" && continue
  echo "== $test =="
  php "$test"
done
