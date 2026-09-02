#!/usr/bin/env bash
#
# Self-test for tests/regenerate-phpstan-baseline.sh.
#
# Usage: tests/test-regenerate-phpstan-baseline.sh
#
# Uses a PHPStan stub to verify atomic writes, portable candidate paths,
# drift detection, dry-run behaviour, diagnostic counts, and failure handling.

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

generator=$PWD/tests/regenerate-phpstan-baseline.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-baseline-test.XXXXXX")
cleanup() {
	rm -rf -- "$test_root"
}
trap cleanup EXIT

phpstan_stub=$test_root/phpstan
source_baseline=$test_root/source.neon
candidate_log=$test_root/candidate.log
output=$test_root/output.log
target=$test_root/phpstan-baseline.neon

cat >"$phpstan_stub" <<'STUB'
#!/usr/bin/env bash
if [ "${PHPSTAN_STUB_STATUS:-0}" -ne 0 ]; then
  exit "$PHPSTAN_STUB_STATUS"
fi
candidate=
for argument in "$@"; do
  case $argument in
    --generate-baseline=*) candidate=${argument#*=} ;;
  esac
done
if [ -z "$candidate" ]; then
  exit 64
fi
printf '%s\n' "$candidate" >"$PHPSTAN_CANDIDATE_LOG"
cp -- "$PHPSTAN_STUB_SOURCE" "$candidate"
STUB
chmod +x "$phpstan_stub"

write_fixture() {
	count_one=$1 count_two=$2 message_one=${3:-one}
	cat >"$source_baseline" <<EOF
parameters:
    ignoreErrors:
        -
            message: '#$message_one#'
            identifier: test.one
            count: $count_one
            path: example.php
        -
            message: '#two#'
            identifier: test.two
            count: $count_two
            path: example.php
EOF
}
run_generator() {
	if GITHUB_ACTIONS=false GITHUB_EVENT_NAME='' PHPSTAN_BASE_REF='' \
		PHPSTAN_BASE_SHA='' PHPSTAN_EVENT_BEFORE='' \
		PHPSTAN_REPO_ROOT=$test_root PHPSTAN_BIN=$phpstan_stub \
		PHPSTAN_STUB_SOURCE=$source_baseline \
		PHPSTAN_CANDIDATE_LOG=$candidate_log "$@" >"$output" 2>&1; then
		generator_status=0
	else
		generator_status=$?
	fi
}

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
	if [ "$actual" -eq "$expected" ]; then ok "$label"; else
		not_ok "$label (expected $expected, got $actual)"
	fi
}

write_fixture 2 3
cat >"$test_root/phpstan.neon" <<'EOF'
parameters:
    level: 3
EOF
printf 'parameters:\n\tignoreErrors: []\n' >"$target"
git -C "$test_root" init -q --initial-branch=master
git -C "$test_root" config user.name 'PHPStan baseline test'
git -C "$test_root" config user.email 'phpstan@example.invalid'
git -C "$test_root" add phpstan.neon phpstan-baseline.neon
git -C "$test_root" commit -q --no-gpg-sign -m legacy-baseline
migration_base=$(git -C "$test_root" rev-parse HEAD)
cat >"$test_root/phpstan.neon" <<'EOF'
parameters:
    level: 10
EOF
cp -- "$source_baseline" "$target"
git -C "$test_root" add phpstan.neon phpstan-baseline.neon
git -C "$test_root" commit -q --no-gpg-sign -m level-10-baseline
run_generator bash "$generator"
expect_status 0 "$generator_status" "write mode succeeds"
if cmp -s -- "$source_baseline" "$target"; then
	ok "write mode replaces the tracked baseline"
else
	not_ok "write mode replaces the tracked baseline"
fi
candidate=$(cat "$candidate_log")
if [ "$(dirname "$candidate")" = "$test_root" ] &&
	[[ $(basename "$candidate") == *.neon ]]; then
	ok "candidate is a same-directory NEON file"
else
	not_ok "candidate is a same-directory NEON file ($candidate)"
fi
if grep -Fq 'diagnostics: 5' "$output"; then
	ok "diagnostic count sums baseline entries"
else
	not_ok "diagnostic count sums baseline entries"
fi

printf 'parameters:\n\tignoreErrors: []\n' >"$source_baseline"
run_generator bash "$generator"
expect_status 0 "$generator_status" "write mode accepts an empty baseline"
if cmp -s -- "$source_baseline" "$target" && grep -Fq 'diagnostics: 0' "$output"; then
	ok "empty baseline is installed and counted"
else
	not_ok "empty baseline is installed and counted"
fi

write_fixture 2 3
cp -- "$source_baseline" "$target"

run_generator bash "$generator" --check
expect_status 0 "$generator_status" "check accepts an exact baseline"

write_fixture 4 3
cp -- "$target" "$test_root/before.neon"
run_generator bash "$generator" --check
expect_status 1 "$generator_status" "check rejects baseline drift"
if cmp -s -- "$test_root/before.neon" "$target"; then
	ok "failed check does not rewrite the baseline"
else
	not_ok "failed check does not rewrite the baseline"
fi

run_generator bash "$generator" --dry-run
expect_status 0 "$generator_status" "dry-run reports drift without failing"
if cmp -s -- "$test_root/before.neon" "$target"; then
	ok "dry-run does not rewrite the baseline"
else
	not_ok "dry-run does not rewrite the baseline"
fi

write_fixture 2 3 replacement
cp -- "$source_baseline" "$target"
run_generator bash "$generator" --check
expect_status 1 "$generator_status" \
	"equal-size replacement diagnostic is rejected against the base"
if grep -Fq 'gained or increased diagnostics' "$output"; then
	ok "base-relative rejection names diagnostic growth"
else
	not_ok "base-relative rejection names diagnostic growth"
fi

write_fixture 2 3
cp -- "$source_baseline" "$target"
run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=pull_request \
	PHPSTAN_BASE_SHA="$migration_base" bash "$generator" --check
expect_status 0 "$generator_status" \
	"pull-request event accepts its immutable base SHA"

run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=pull_request \
	PHPSTAN_BASE_SHA='' bash "$generator" --check
expect_status 2 "$generator_status" \
	"pull-request event rejects a missing immutable base SHA"
if grep -Fq 'PHPStan immutable base revision is unavailable' "$output"; then
	ok "missing pull-request base reports an actionable diagnostic"
else
	not_ok "missing pull-request base reports an actionable diagnostic"
fi

run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=push \
	PHPSTAN_EVENT_BEFORE="$migration_base" bash "$generator" --check
expect_status 0 "$generator_status" \
	"push event accepts its immutable before SHA"

zero_sha=$(printf '0%.0s' {1..40})
run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=push \
	PHPSTAN_EVENT_BEFORE="$zero_sha" bash "$generator" --check
expect_status 0 "$generator_status" \
	"first push accepts the empty-tree immutable base"

run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=push \
	PHPSTAN_EVENT_BEFORE='' bash "$generator" --check
expect_status 2 "$generator_status" \
	"push event rejects a missing immutable before SHA"
if grep -Fq 'PHPStan immutable base revision is unavailable' "$output"; then
	ok "missing push base reports an actionable diagnostic"
else
	not_ok "missing push base reports an actionable diagnostic"
fi

run_generator env GITHUB_ACTIONS=true GITHUB_EVENT_NAME=workflow_dispatch \
	PHPSTAN_BASE_SHA='' bash "$generator" --check
expect_status 0 "$generator_status" \
	"manual event uses its immutable workflow SHA"

write_fixture 1160 0
cp -- "$source_baseline" "$target"
run_generator env PHPSTAN_BASE_REF="$migration_base" bash "$generator" --check
expect_status 1 "$generator_status" "migration ceiling rejects debt above 1159"
if grep -Fq '1160 > 1159' "$output"; then
	ok "migration ceiling reports the measured debt"
else
	not_ok "migration ceiling reports the measured debt"
fi

run_generator env PHPSTAN_STUB_STATUS=23 bash "$generator"
expect_status 23 "$generator_status" "PHPStan failure propagates"

run_generator bash "$generator" unexpected
expect_status 2 "$generator_status" "unexpected argument fails closed"

printf '1..%d\n' "$test_number"
if [ "$failures" -ne 0 ]; then
	printf '# %d assertion(s) failed\n' "$failures" >&2
	exit 1
fi
