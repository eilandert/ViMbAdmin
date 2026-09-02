#!/usr/bin/env bash
#
# Generate or verify ViMbAdmin's repository-wide PHPStan level-10 baseline.
#
# Usage: tests/regenerate-phpstan-baseline.sh [--check|--dry-run]
#
# The baseline-free phpstan-level10.neon is the source of truth. By default the
# script atomically replaces phpstan-baseline.neon. --check generates into a
# temporary file and fails on drift; --dry-run prints that diff without writing.
# Check/write modes also reject diagnostic growth against the immutable event
# base. PHPSTAN_BIN may select an alternate binary for tests. The script writes
# only phpstan-baseline.neon and PHPStan's cache; it performs no network I/O.

set -euo pipefail

repo_root=${PHPSTAN_REPO_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$repo_root"

mode='write'
case ${1:-} in
'') ;;
--check) mode='check' ;;
--dry-run) mode='dry-run' ;;
--help)
	sed -n '3,11s/^# \{0,1\}//p' "$0"
	exit 0
	;;
*)
	echo "usage: $0 [--check|--dry-run]" >&2
	exit 2
	;;
esac
if [ "$#" -gt 1 ]; then
	echo "usage: $0 [--check|--dry-run]" >&2
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

target='phpstan-baseline.neon'
candidate_seed=$(mktemp "$repo_root/.phpstan-baseline.XXXXXX")
candidate="${candidate_seed}.neon"
mv -- "$candidate_seed" "$candidate"
cleanup_files=("$candidate_seed" "$candidate")
cleanup() {
	rm -f -- "${cleanup_files[@]}"
}
trap cleanup EXIT

"${phpstan[@]}" analyse -c phpstan-level10.neon --no-progress \
	--generate-baseline="$candidate" --allow-empty-baseline

if [ ! -s "$candidate" ]; then
	echo "PHPStan generated an empty baseline artifact" >&2
	exit 1
fi

baseline_count() {
	awk '
      $1 == "count:" {
        if ($2 !~ /^[0-9]+$/) exit 2
        diagnostics += $2
      }
      END { printf "%d\n", diagnostics }
    ' "$1"
}

baseline_entries() {
	awk '
      /^[[:space:]]+message:/ {
        message = $0
        sub(/^[[:space:]]+message:[[:space:]]*/, "", message)
      }
      /^[[:space:]]+identifier:/ {
        identifier = $0
        sub(/^[[:space:]]+identifier:[[:space:]]*/, "", identifier)
      }
      /^[[:space:]]+count:/ {
        count = $0
        sub(/^[[:space:]]+count:[[:space:]]*/, "", count)
      }
      /^[[:space:]]+path:/ {
        path = $0
        sub(/^[[:space:]]+path:[[:space:]]*/, "", path)
        print path "\t" identifier "\t" message "\t" count
        message = identifier = count = path = ""
      }
    ' "$1" | LC_ALL=C sort
}

candidate_count=$(baseline_count "$candidate") || {
	echo "Invalid count in generated PHPStan baseline" >&2
	exit 1
}

if [ "$mode" != dry-run ]; then
	git_bin=${PHPSTAN_GIT_BIN:-}
	[ -n "$git_bin" ] || git_bin='git'
	if ! command -v "$git_bin" >/dev/null 2>&1; then
		echo "Git is required but was not found: $git_bin" >&2
		exit 127
	fi

	base=${PHPSTAN_BASE_REF:-${PHPSTAN_BASE_SHA:-}}
	if [ -z "$base" ]; then
		if [ "${GITHUB_ACTIONS:-false}" = true ]; then
			case ${GITHUB_EVENT_NAME:-} in
			push) base=${PHPSTAN_EVENT_BEFORE:-} ;;
			workflow_dispatch) base='HEAD' ;;
			*)
				echo "PHPStan immutable base revision is unavailable" >&2
				exit 2
				;;
			esac
		else
			base='HEAD'
		fi
	fi
	if [ -z "$base" ]; then
		echo "PHPStan immutable base revision is unavailable" >&2
		exit 2
	fi

	if [[ $base =~ ^0{40}$ ]]; then
		base=$($git_bin hash-object -t tree /dev/null)
	fi
	if ! "$git_bin" rev-parse --verify "${base}^{commit}" >/dev/null 2>&1 &&
		! "$git_bin" rev-parse --verify "${base}^{tree}" >/dev/null 2>&1; then
		echo "PHPStan base revision does not exist: $base" >&2
		exit 2
	fi

	base_config=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-phpstan-config.XXXXXX")
	base_baseline=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-phpstan-baseline.XXXXXX")
	cleanup_files+=("$base_config" "$base_baseline")
	if "$git_bin" show "$base:phpstan.neon" >"$base_config" 2>/dev/null &&
		grep -Eq '^[[:space:]]*level:[[:space:]]*10([[:space:]]|$)' \
			"$base_config" &&
		"$git_bin" show "$base:$target" >"$base_baseline" 2>/dev/null; then
		ceiling=$(baseline_count "$base_baseline") || {
			echo "Invalid count in base PHPStan baseline" >&2
			exit 1
		}
		base_entries=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-phpstan-base-entries.XXXXXX")
		candidate_entries=$(mktemp \
			"${TMPDIR:-/tmp}/vimbadmin-phpstan-candidate-entries.XXXXXX")
		cleanup_files+=("$base_entries" "$candidate_entries")
		baseline_entries "$base_baseline" >"$base_entries"
		baseline_entries "$candidate" >"$candidate_entries"
		growth=$(awk -F '\t' '
          NR == FNR {
            key = $1 FS $2 FS $3
            base[key] = $4
            next
          }
          {
            key = $1 FS $2 FS $3
            if ($4 > base[key]) print $1 ": " $2 " (" base[key] " -> " $4 ")"
          }
        ' "$base_entries" "$candidate_entries")
		if [ -n "$growth" ]; then
			echo "PHPStan level-10 baseline gained or increased diagnostics:" >&2
			printf '%s\n' "$growth" | sed -n '1,20p' >&2
			exit 1
		fi
		ceiling_source="base $base"
	else
		# One-time migration ceiling for branches whose base predates the
		# repository-wide level-10 policy. Once merged, every PR uses its base.
		ceiling=1159
		ceiling_source="level-10 migration ceiling"
	fi
	if [ "$candidate_count" -gt "$ceiling" ]; then
		printf 'PHPStan level-10 debt grew: %d > %d (%s)\n' \
			"$candidate_count" "$ceiling" "$ceiling_source" >&2
		exit 1
	fi
fi

case $mode in
check)
	if ! cmp -s -- "$target" "$candidate"; then
		echo "PHPStan level-10 baseline drift; regenerate it with $0" >&2
		diff -u -- "$target" "$candidate" || true
		exit 1
	fi
	;;
dry-run)
	diff -u -- "$target" "$candidate" || true
	;;
write)
	chmod 0644 "$candidate"
	mv -- "$candidate" "$target"
	;;
esac

printf 'PHPStan level-10 baseline diagnostics: %d\n' "$candidate_count"
