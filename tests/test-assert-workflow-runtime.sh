#!/usr/bin/env bash
set -euo pipefail

readonly contract=$PWD/.github/scripts/assert-workflow-runtime.sh
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-workflow-runtime.XXXXXX")
readonly fixture_root
readonly fixture=$fixture_root/security.yml

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

run_contract() {
  WORKFLOW_RUNTIME_SECURITY_WORKFLOW="$fixture" bash "$contract" \
    >"$fixture_root/output" 2>&1
}

expect_rejected() {
  local label=$1 message=$2

  if run_contract; then
    printf 'Workflow runtime contract accepted %s.\n' "$label" >&2
    exit 1
  fi
  grep -qF "$message" \
    "$fixture_root/output" || {
    cat "$fixture_root/output" >&2
    exit 1
  }
}

cp -- .github/workflows/security.yml "$fixture"
run_contract

sed -i '/          persist-credentials: false/c\        env:\n          persist-credentials: false' "$fixture"
expect_rejected 'persist-credentials under env' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/          persist-credentials: false/a\          persist-credentials: false' "$fixture"
expect_rejected 'duplicate persist-credentials input' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/          persist-credentials: false/d' "$fixture"
expect_rejected 'missing persist-credentials input' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/        shell: bash/d' "$fixture"
expect_rejected 'missing Semgrep Bash shell default' \
  'Security Semgrep workflow must run every inline command with Bash.'

printf 'Workflow runtime contract rejects misplaced checkout credentials.\n'
