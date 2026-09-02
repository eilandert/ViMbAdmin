#!/usr/bin/env bash
set -euo pipefail

readonly contract=$PWD/.github/scripts/assert-pr-runner-isolation.sh
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-pr-runner.XXXXXX")
readonly fixture_root

cleanup() {
	rm -rf -- "$fixture_root"
}
trap cleanup EXIT

mkdir "$fixture_root/workflows"
cp -- .github/workflows/*.yml "$fixture_root/workflows/"

run_contract() {
	PR_RUNNER_WORKFLOW_DIR="$fixture_root/workflows" \
		GITHUB_EVENT_NAME=${1:?event required} \
		RUNNER_ENVIRONMENT=${2:?runner environment required} \
		bash "$contract" >"$fixture_root/output" 2>&1
}

run_contract pull_request_target github-hosted
run_contract push self-hosted

if run_contract pull_request_target self-hosted; then
	printf 'Isolation guard accepted a self-hosted pull-request runner.\n' >&2
	exit 1
fi
grep -qF 'Pull-request code must run on a GitHub-hosted runner' \
	"$fixture_root/output"

sed -i '0,/runs-on: ubuntu-24.04/s//runs-on: [self-hosted, builder02, docker]/' \
	"$fixture_root/workflows/ci.yml"
if run_contract pull_request_target github-hosted; then
	printf 'Isolation guard accepted a self-hosted workflow target.\n' >&2
	exit 1
fi
grep -qF 'persistent self-hosted runner' "$fixture_root/output"

cp -- .github/workflows/ci.yml "$fixture_root/workflows/ci.yml"
sed -i '0,/run: bash \.github\/scripts\/assert-pr-runner-isolation\.sh/{s@run: bash \.github/scripts/assert-pr-runner-isolation\.sh@run: true@}' \
	"$fixture_root/workflows/ci.yml"
if run_contract pull_request_target github-hosted; then
	printf 'Isolation guard accepted a PR job without its runtime guard.\n' >&2
	exit 1
fi
grep -qF 'Every PR-triggered job must use ubuntu-24.04' "$fixture_root/output"

cp -- .github/workflows/ci.yml "$fixture_root/workflows/ci.yml"
sed -i '0,/ref: \${{ github.event.pull_request.head.sha || github.sha }}/s//ref: master/' \
	"$fixture_root/workflows/ci.yml"
if run_contract pull_request_target github-hosted; then
	printf 'Isolation guard accepted a PR job that checked out the base branch.\n' >&2
	exit 1
fi
grep -qF 'pin the PR head' "$fixture_root/output"

cp -- .github/workflows/ci.yml "$fixture_root/workflows/ci.yml"
sed -i '0,/pull_request_target:/s//pull_request:/' \
	"$fixture_root/workflows/ci.yml"
if run_contract pull_request_target github-hosted; then
	printf 'Isolation guard accepted a PR-controlled workflow definition.\n' >&2
	exit 1
fi
grep -qF 'base-controlled pull_request_target' "$fixture_root/output"

printf 'PR runner isolation negative controls reject unsafe runtime and workflow forms.\n'
