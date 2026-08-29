#!/usr/bin/env bash
#
# Self-test for tests/lint-phpstan-ratchet.sh.
#
# Usage: tests/test-phpstan-ratchet.sh
#
# Builds a temporary Git history and stubs PHPStan so event-range selection and
# failure propagation are tested without analysing the application repeatedly.
# Output: one TAP result per assertion. Side effects: a temporary directory.
# Extend: add a case whenever the production script gains another event mode or
# external command.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" = "--help" ]; then
  sed -n '3,10s/^# \{0,1\}//p' "$0"
  exit 0
fi
if [ "$#" -ne 0 ]; then
  echo "usage: $0" >&2
  exit 2
fi

ratchet_script=${PHPSTAN_RATCHET_SCRIPT:-$PWD/tests/lint-phpstan-ratchet.sh}
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-phpstan-test.XXXXXX")
cleanup() {
  rm -rf -- "$test_root"
}
trap cleanup EXIT

repo=$test_root/repo
log=$test_root/phpstan.log
output=$test_root/output.log
phpstan_stub=$test_root/phpstan
git_stub=$test_root/git-fail-diff
real_git=$(command -v git)
export PHPSTAN_STUB_LOG=$log REAL_GIT_BIN=$real_git

cat >"$phpstan_stub" <<'STUB'
#!/usr/bin/env bash
printf 'CALL\n' >>"$PHPSTAN_STUB_LOG"
printf 'ARG=%s\n' "$@" >>"$PHPSTAN_STUB_LOG"
previous=
for argument in "$@"; do
    if [ "$previous" = -c ] \
        && [ "$argument" = "${PHPSTAN_STUB_FAIL_CONFIG:-}" ]; then
        exit 23
    fi
    previous=$argument
done
STUB
chmod +x "$phpstan_stub"

cat >"$git_stub" <<'STUB'
#!/usr/bin/env bash
if [ "${1:-}" = diff ]; then
	if [ "${GIT_STUB_SIGNAL_PARENT:-}" = true ]; then
		kill -TERM "$PPID"
		exit 0
	fi
	if [ "${GIT_STUB_FAIL_DIFF:-}" = true ]; then
		exit 42
	fi
fi
exec "$REAL_GIT_BIN" "$@"
STUB
chmod +x "$git_stub"

git init -q --initial-branch=master "$repo"
git -C "$repo" config user.name "PHPStan ratchet test"
git -C "$repo" config user.email "phpstan-ratchet@example.invalid"
printf 'base\n' >"$repo/README.md"
git -C "$repo" add README.md
git -C "$repo" commit -q -m base
base_sha=$(git -C "$repo" rev-parse HEAD)
git -C "$repo" update-ref refs/remotes/origin/master "$base_sha"

mkdir -p "$repo/src"
printf '<?php\n' >"$repo/src/push-added.php"
git -C "$repo" add src/push-added.php
git -C "$repo" commit -q -m add-php
printf 'second commit\n' >>"$repo/README.md"
git -C "$repo" add README.md
git -C "$repo" commit -q -m second-commit

test_number=0
failures=0
ok() {
  test_number=$((test_number + 1))
  printf 'ok %d - %s\n' "$test_number" "$1"
}
not_ok() {
  test_number=$((test_number + 1))
  failures=$((failures + 1))
  printf 'not ok %d - %s\n' "$test_number" "$1"
}
expect_status() {
  expected=$1
  actual=$2
  label=$3
  if [ "$actual" -eq "$expected" ]; then
    ok "$label"
  else
    not_ok "$label (expected $expected, got $actual)"
  fi
}
expect_log() {
  needle=$1
  label=$2
  if grep -Fqx -- "$needle" "$log"; then
    ok "$label"
  else
    not_ok "$label (missing $needle)"
  fi
}
expect_no_log() {
  needle=$1
  label=$2
  if grep -Fqx -- "$needle" "$log"; then
    not_ok "$label (unexpected $needle)"
  else
    ok "$label"
  fi
}
run_gate() {
  : >"$log"
  "$@" >"$output" 2>&1 &
  gate_pid=$!
  if wait "$gate_pid" 2>/dev/null; then
    gate_status=0
  else
    gate_status=$?
  fi
}

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  GITHUB_ACTIONS=true GITHUB_EVENT_NAME=push \
  PHPSTAN_EVENT_BEFORE="$base_sha" bash "$ratchet_script"
expect_status 0 "$gate_status" "multi-commit push succeeds"
expect_log 'ARG=src/push-added.php' \
  "multi-commit push checks additions from the before SHA"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  GITHUB_ACTIONS=true GITHUB_EVENT_NAME=pull_request \
  GITHUB_BASE_REF=master bash "$ratchet_script"
expect_status 0 "$gate_status" "pull request succeeds"
expect_log 'ARG=src/push-added.php' "pull request checks additions from its base"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  GITHUB_ACTIONS=true GITHUB_EVENT_NAME=workflow_dispatch \
  bash "$ratchet_script"
expect_status 0 "$gate_status" "manual dispatch succeeds"
expect_no_log 'ARG=src/push-added.php' \
  "manual dispatch does not choose an arbitrary prior commit"

printf '<?php\n' >"$repo/src/staged.php"
git -C "$repo" add src/staged.php
printf '<?php\n' >"$repo/src/untracked.php"
run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  bash "$ratchet_script"
expect_status 0 "$gate_status" "local pre-commit run succeeds"
expect_log 'ARG=src/staged.php' "local run checks staged additions"
expect_log 'ARG=src/untracked.php' "local run checks untracked additions"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  PHPSTAN_BASE_REF=missing-revision bash "$ratchet_script"
expect_status 2 "$gate_status" "missing base fails closed"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  PHPSTAN_GIT_BIN="$git_stub" PHPSTAN_BASE_REF="$base_sha" \
  GIT_STUB_FAIL_DIFF=true \
  GITHUB_ACTIONS=true GITHUB_EVENT_NAME=workflow_dispatch \
  bash "$ratchet_script"
expect_status 2 "$gate_status" "failed Git producer fails closed"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  PHPSTAN_STUB_FAIL_CONFIG=phpstan-level7.neon bash "$ratchet_script"
expect_status 23 "$gate_status" "PHPStan failure propagates"

run_gate env PHPSTAN_REPO_ROOT="$repo" PHPSTAN_BIN="$phpstan_stub" \
  PHPSTAN_GIT_BIN="$git_stub" PHPSTAN_BASE_REF="$base_sha" \
  GIT_STUB_SIGNAL_PARENT=true GITHUB_ACTIONS=true \
  GITHUB_EVENT_NAME=workflow_dispatch bash "$ratchet_script"
expect_status 143 "$gate_status" "termination signal stops the gate"

printf '1..%d\n' "$test_number"
if [ "$failures" -ne 0 ]; then
  printf '# %d assertion(s) failed\n' "$failures" >&2
  exit 1
fi
