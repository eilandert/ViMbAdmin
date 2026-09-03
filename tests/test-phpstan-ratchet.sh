#!/usr/bin/env bash
#
# Self-test for tests/lint-phpstan-ratchet.sh.
#
# Usage: tests/test-phpstan-ratchet.sh
#
# Stubs PHPStan and baseline regeneration so the exact level-10 config,
# fail-closed propagation, and command-line contract are tested cheaply.

set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" = --help ]; then
  sed -n '3,8s/^# \{0,1\}//p' "$0"
  exit 0
fi
if [ "$#" -ne 0 ]; then
  echo "usage: $0" >&2
  exit 2
fi

gate=$PWD/tests/lint-phpstan-ratchet.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-phpstan-test.XXXXXX")
cleanup() {
  rm -rf -- "$test_root"
}
trap cleanup EXIT

phpstan_stub=$test_root/phpstan
baseline_stub=$test_root/baseline
phpstan_log=$test_root/phpstan.log
baseline_log=$test_root/baseline.log
output=$test_root/output.log

cat >"$phpstan_stub" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" >"$PHPSTAN_STUB_LOG"
exit "${PHPSTAN_STUB_STATUS:-0}"
STUB
chmod +x "$phpstan_stub"

cat >"$baseline_stub" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" >"$BASELINE_STUB_LOG"
exit "${BASELINE_STUB_STATUS:-0}"
STUB
chmod +x "$baseline_stub"

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
  expected=$1 actual=$2 label=$3
  if [ "$actual" -eq "$expected" ]; then
    ok "$label"
  else
    not_ok "$label (expected $expected, got $actual)"
  fi
}
expect_line() {
  needle=$1 file=$2 label=$3
  if grep -Fqx -- "$needle" "$file"; then
    ok "$label"
  else
    not_ok "$label (missing $needle)"
  fi
}
run_gate() {
  : >"$phpstan_log"
  : >"$baseline_log"
  if PHPSTAN_REPO_ROOT=$test_root PHPSTAN_BIN=$phpstan_stub \
    PHPSTAN_BASELINE_SCRIPT=$baseline_stub PHPSTAN_STUB_LOG=$phpstan_log \
    BASELINE_STUB_LOG=$baseline_log "$@" >"$output" 2>&1; then
    gate_status=0
  else
    gate_status=$?
  fi
}

run_gate bash "$gate"
expect_status 0 "$gate_status" "level-10 gate succeeds"
expect_line phpstan.neon "$phpstan_log" "repository config is phpstan.neon"
expect_line --check "$baseline_log" "baseline integrity runs in check mode"

run_gate env PHPSTAN_STUB_STATUS=23 bash "$gate"
expect_status 23 "$gate_status" "PHPStan failure propagates"
if [ ! -s "$baseline_log" ]; then
  ok "PHPStan failure stops baseline verification"
else
  not_ok "PHPStan failure stops baseline verification"
fi

run_gate env BASELINE_STUB_STATUS=24 bash "$gate"
expect_status 24 "$gate_status" "baseline verification failure propagates"

run_gate bash "$gate" unexpected
expect_status 2 "$gate_status" "unexpected argument fails closed"

printf '1..%d\n' "$test_number"
if [ "$failures" -ne 0 ]; then
  printf '# %d assertion(s) failed\n' "$failures" >&2
  exit 1
fi
