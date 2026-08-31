#!/usr/bin/env bash
#
# Enforce ViMbAdmin's repository-wide PHPStan level-10 target.
#
# Usage: tests/lint-phpstan-ratchet.sh
#
# The complete repository is analysed at level 10 with its generated baseline,
# then the baseline is independently regenerated and compared byte-for-byte.
# New diagnostics, stale suppressions, and baseline drift therefore fail CI.
# PHPSTAN_BIN and PHPSTAN_BASELINE_SCRIPT are test seams.

set -euo pipefail

repo_root=${PHPSTAN_REPO_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$repo_root"

if [ "${1:-}" = --help ]; then
	sed -n '3,9s/^# \{0,1\}//p' "$0"
	exit 0
fi
if [ "$#" -ne 0 ]; then
	echo "usage: $0" >&2
	exit 2
fi

if [ -n "${PHPSTAN_BIN:-}" ]; then
	phpstan=("$PHPSTAN_BIN")
elif [ -x vendor/bin/phpstan ]; then
	phpstan=(vendor/bin/phpstan)
elif command -v phpstan >/dev/null 2>&1; then
	phpstan=(phpstan)
else
	echo "PHPStan is required but was not found" >&2
	exit 127
fi

echo "== PHPStan level 10: repository target =="
"${phpstan[@]}" analyse -c phpstan.neon --no-progress --error-format=github

echo "== PHPStan level 10: generated baseline integrity =="
baseline_script=${PHPSTAN_BASELINE_SCRIPT:-$repo_root/tests/regenerate-phpstan-baseline.sh}
PHPSTAN_BIN=${phpstan[0]} PHPSTAN_REPO_ROOT=$repo_root \
	bash "$baseline_script" --check
