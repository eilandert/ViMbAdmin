#!/usr/bin/env bash
set -euo pipefail

readonly workflow_dir=${PR_RUNNER_WORKFLOW_DIR:-.github/workflows}
readonly workflows=(
	"$workflow_dir/ci.yml"
	"$workflow_dir/regression.yml"
	"$workflow_dir/security.yml"
	"$workflow_dir/static-analysis.yml"
)

if [[ ${GITHUB_EVENT_NAME:-} == pull_request_target ]] &&
	[[ ${RUNNER_ENVIRONMENT:-} != github-hosted ]]; then
	printf 'Pull-request code must run on a GitHub-hosted runner; got %s.\n' \
		"${RUNNER_ENVIRONMENT:-unset}" >&2
	exit 1
fi

if grep -HnE '^[[:space:]]+pull_request:' "${workflows[@]}"; then
	printf 'Untrusted checks must use base-controlled pull_request_target workflows.\n' >&2
	exit 1
fi

if grep -HnE 'runs-on:.*self-hosted|runs-on:.*builder02' "${workflows[@]}"; then
	printf 'A pull-request workflow targets a persistent self-hosted runner.\n' >&2
	exit 1
fi

awk '
  function finish_job() {
    if (!in_job) return
    jobs++
    if (runner != 1 || guard != 1 || checkout != 1) failed = 1
  }
  FNR == 1 {
    finish_job()
    in_jobs = 0
    in_job = 0
  }
  /^jobs:[[:space:]]*$/ {
    in_jobs = 1
    next
  }
  in_jobs && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ {
    finish_job()
    in_job = 1
    runner = 0
    guard = 0
    checkout = 0
    next
  }
  in_job && /^    runs-on: ubuntu-24\.04[[:space:]]*$/ { runner++ }
  in_job && /ref: \$\{\{ github\.event\.pull_request\.head\.sha \|\| github\.sha \}\}/ {
    checkout++
  }
  in_job && /run: bash \.github\/scripts\/assert-pr-runner-isolation\.sh/ {
    guard++
  }
  END {
    finish_job()
    if (jobs != 8) failed = 1
    exit failed ? 1 : 0
  }
' "${workflows[@]}" || {
	printf 'Every PR-triggered job must use ubuntu-24.04, pin the PR head, and run the isolation guard exactly once.\n' >&2
	exit 1
}

printf 'All pull-request jobs are isolated on GitHub-hosted runners.\n'
