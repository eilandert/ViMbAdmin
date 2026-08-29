#!/usr/bin/env bash
#
# Enforce the PHPStan migration policy.
#
# Usage: tests/lint-phpstan-ratchet.sh [BASE_REVISION]
#
# Runs the complete repository at level 3, then applies a level-7 no-net-growth
# baseline. PHP files added since BASE_REVISION are checked at level 10 without
# a baseline. Before a local commit, staged and untracked PHP files are included
# too. CI uses the pull-request base, the push event's before SHA, or HEAD for a
# manual dispatch. Local runs default to HEAD.
#
# Inputs: committed source, PHPStan configs/baselines, optional base revision;
#         PHPSTAN_EVENT_BEFORE is supplied by GitHub Actions for push events.
# Output: PHPStan diagnostics. Side effects: PHPStan cache only.
# Limits: "materially rewritten" legacy files cannot be classified reliably;
# their diagnostics are governed by aggregate per-file baseline counts.
# Extend: adjust policy in the three phpstan*.neon files, not in CI YAML.

set -euo pipefail

repo_root=${PHPSTAN_REPO_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}
cd "$repo_root"

if [ "${1:-}" = "--help" ]; then
  sed -n '3,17s/^# \{0,1\}//p' "$0"
  exit 0
fi

if [ "$#" -gt 1 ]; then
  echo "usage: $0 [BASE_REVISION]" >&2
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

git_bin=${PHPSTAN_GIT_BIN:-git}
if ! command -v "$git_bin" >/dev/null 2>&1; then
  echo "Git is required but was not found: $git_bin" >&2
  exit 127
fi

echo "== PHPStan level 3: repository floor =="
"${phpstan[@]}" analyse -c phpstan.neon --no-progress --error-format=github

echo "== PHPStan level 7: no-new-errors ratchet =="
"${phpstan[@]}" analyse -c phpstan-level7.neon --no-progress --error-format=github

base=${1:-${PHPSTAN_BASE_REF:-}}
if [ -z "$base" ]; then
  if [ "${GITHUB_ACTIONS:-false}" = true ]; then
    case ${GITHUB_EVENT_NAME:-} in
      pull_request | pull_request_target)
        if [ -z "${GITHUB_BASE_REF:-}" ]; then
          echo "GitHub pull request base is unavailable" >&2
          exit 2
        fi
        base="origin/${GITHUB_BASE_REF}"
        ;;
      push)
        if [ -z "${PHPSTAN_EVENT_BEFORE:-}" ]; then
          echo "GitHub push before SHA is unavailable" >&2
          exit 2
        fi
        base=$PHPSTAN_EVENT_BEFORE
        ;;
      workflow_dispatch)
        base=HEAD
        ;;
      *)
        echo "Unsupported GitHub event: ${GITHUB_EVENT_NAME:-unset}" >&2
        exit 2
        ;;
    esac
  else
    base=HEAD
  fi
fi

if [[ $base =~ ^0{40}$ ]]; then
  if ! base=$("$git_bin" hash-object -t tree /dev/null); then
    echo "Failed to resolve Git's empty tree" >&2
    exit 2
  fi
fi

if ! "$git_bin" rev-parse --verify "${base}^{commit}" >/dev/null 2>&1 \
  && ! "$git_bin" rev-parse --verify "${base}^{tree}" >/dev/null 2>&1; then
  echo "PHPStan base revision does not exist: $base" >&2
  exit 2
fi

candidate_file=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-phpstan-files.XXXXXX")
cleanup() {
  rm -f -- "$candidate_file"
}
trap cleanup EXIT

if ! "$git_bin" diff --diff-filter=A --name-only -z "$base" HEAD \
  -- '*.php' >"$candidate_file"; then
  echo "Failed to enumerate committed PHP additions" >&2
  exit 2
fi

if [ "${GITHUB_ACTIONS:-false}" != true ]; then
  if ! "$git_bin" diff --cached --diff-filter=A --name-only -z \
    -- '*.php' >>"$candidate_file"; then
    echo "Failed to enumerate staged PHP additions" >&2
    exit 2
  fi
  if ! "$git_bin" ls-files --others --exclude-standard -z \
    -- '*.php' >>"$candidate_file"; then
    echo "Failed to enumerate untracked PHP additions" >&2
    exit 2
  fi
fi

added_php=()
while IFS= read -r -d '' candidate; do
  added_php+=("$candidate")
done <"$candidate_file"

if [ "${#added_php[@]}" -eq 0 ]; then
  echo "== PHPStan level 10: no newly added PHP files =="
  exit 0
fi

echo "== PHPStan level 10: newly added PHP files =="
printf '  %s\n' "${added_php[@]}"
"${phpstan[@]}" analyse -c phpstan-level10.neon --no-progress \
  --error-format=github -- "${added_php[@]}"
