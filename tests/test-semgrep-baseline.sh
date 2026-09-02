#!/usr/bin/env bash
set -euo pipefail

readonly selector=$PWD/.github/scripts/select-semgrep-baseline.sh
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-semgrep-baseline.XXXXXX")
readonly fixture_root

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

git -C "$fixture_root" init -q
git -C "$fixture_root" config user.email 'test@example.invalid'
git -C "$fixture_root" config user.name 'Semgrep baseline test'
printf 'before\n' >"$fixture_root/fixture"
git -C "$fixture_root" add fixture
git -C "$fixture_root" commit -q --no-gpg-sign -m before
parent_sha=$(git -C "$fixture_root" rev-parse HEAD)
printf 'after\n' >"$fixture_root/fixture"
git -C "$fixture_root" commit -q --no-gpg-sign -am after
head_sha=$(git -C "$fixture_root" rev-parse HEAD)

check_output() {
  local label=$1 expected=$2 actual
  actual=$(cd "$fixture_root" && bash "$selector" "$3" "$4" "$5" "$head_sha")
  if [[ $actual != "$expected" ]]; then
    printf 'FAIL %s: expected %s, got %s\n' "$label" "$expected" "$actual" >&2
    exit 1
  fi
  printf 'ok   %s\n' "$label"
}

check_empty() {
  local label=$1 actual
  actual=$(cd "$fixture_root" && bash "$selector" "$2" '' '' "$head_sha")
  if [[ -n $actual ]]; then
    printf 'FAIL %s: expected a full scan\n' "$label" >&2
    exit 1
  fi
  printf 'ok   %s\n' "$label"
}

expect_failure() {
  local label=$1
  if (cd "$fixture_root" && bash "$selector" "$2" "$3" "$4" "$head_sha") >/dev/null 2>&1; then
    printf 'FAIL %s: invalid baseline was accepted\n' "$label" >&2
    exit 1
  fi
  printf 'ok   %s\n' "$label"
}

check_output 'push selects the pre-change commit' "$parent_sha" push '' "$parent_sha"
check_output 'pull requests select the immutable base commit' "$parent_sha" pull_request "$parent_sha" ''
check_empty 'scheduled scans remain full scans' schedule
check_empty 'manual scans remain full scans' workflow_dispatch
expect_failure 'push requires a pre-change commit' push '' ''
expect_failure 'push rejects HEAD as its own baseline' push '' "$head_sha"
expect_failure 'pull requests reject an unavailable base' pull_request deadbeef ''
expect_failure 'unknown events fail closed' repository_dispatch "$parent_sha" ''

printf 'ALL PASSED\n'
