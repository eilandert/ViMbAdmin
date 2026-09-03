#!/usr/bin/env bash
set -euo pipefail

event_name=${1:?event name is required}
pr_base_sha=${2-}
before_sha=${3-}
head_sha=${4:?head SHA is required}

case "$event_name" in
pull_request) baseline_sha=$pr_base_sha ;;
push) baseline_sha=$before_sha ;;
schedule | workflow_dispatch)
  # Scheduled and manually dispatched scans are deliberately full scans.
  exit 0
  ;;
*)
  printf 'Unsupported Semgrep event: %s\n' "$event_name" >&2
  exit 1
  ;;
esac

if [[ -z ${baseline_sha:-} || $baseline_sha == "$head_sha" || $baseline_sha =~ ^0+$ ]]; then
  printf 'A distinct pre-change Semgrep baseline is required for %s.\n' \
    "$event_name" >&2
  exit 1
fi

if ! git cat-file -e "${baseline_sha}^{commit}" 2>/dev/null; then
  printf 'Semgrep baseline commit is unavailable: %s\n' "$baseline_sha" >&2
  exit 1
fi

printf '%s\n' "$baseline_sha"
