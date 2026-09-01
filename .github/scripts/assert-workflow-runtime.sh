#!/usr/bin/env bash
set -euo pipefail

readonly workflows=(
  .github/workflows/ci.yml
  .github/workflows/regression.yml
  .github/workflows/static-analysis.yml
  .github/workflows/security.yml
)

readonly php_workflows=(
  .github/workflows/ci.yml
  .github/workflows/regression.yml
  .github/workflows/static-analysis.yml
)

if grep -HnE 'shivammathur/setup-php|runs-on:.*lxc' "${workflows[@]}"; then
  printf 'Disallowed PHP setup action or unavailable runner target found.\n' >&2
  exit 1
fi

if grep -HnE '^[[:space:]]+(php|mariadb)@sha256:' "${workflows[@]}"; then
  printf 'Workflow images must use the approved registry mirror.\n' >&2
  exit 1
fi

readonly expected_mirrored_images=10
actual_mirrored_images=$(grep -hEc \
  'mirror\.gcr\.io/library/(php|mariadb)@sha256:' "${php_workflows[@]}" \
  | awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_mirrored_images -ne $expected_mirrored_images ]]; then
  printf 'Expected %d mirrored runtime images, found %d.\n' \
    "$expected_mirrored_images" "$actual_mirrored_images" >&2
  exit 1
fi

if ! grep -qF "git fetch --no-tags --depth=1 origin \"\$PHPSTAN_BASE_SHA\"" \
  .github/workflows/static-analysis.yml; then
  printf 'PHPStan must explicitly fetch its immutable pull-request base.\n' >&2
  exit 1
fi

readonly expected_php_containers=6
actual_php_containers=$(grep -hEc '^[[:space:]]+container:' "${php_workflows[@]}" \
  | awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_php_containers -ne $expected_php_containers ]]; then
  printf 'Expected %d PHP container jobs, found %d.\n' \
    "$expected_php_containers" "$actual_php_containers" >&2
  exit 1
fi

actual_checkout_preparations=$(grep -hEc \
  'name: Prepare PHP container for checkout' "${php_workflows[@]}" \
  | awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_checkout_preparations -ne $expected_php_containers ]]; then
  printf 'Expected %d container checkout preparations, found %d.\n' \
    "$expected_php_containers" "$actual_checkout_preparations" >&2
  exit 1
fi

printf 'Workflow runtime contract holds for %d PHP container jobs.\n' \
  "$expected_php_containers"

readonly security_workflow=${WORKFLOW_RUNTIME_SECURITY_WORKFLOW:-.github/workflows/security.yml}
if grep -HnE '\|\|[[:space:]]*true' "$security_workflow"; then
  printf 'Security Semgrep workflow must not mask command failures.\n' >&2
  exit 1
fi

for required in \
  'fetch-depth: 0' \
  'select-semgrep-baseline.sh' \
  'PR_BASE_SHA' \
  'BEFORE_SHA' \
  'HEAD_SHA' \
  '--baseline-commit' \
  'tests/test-semgrep-baseline.sh'; do
  if ! grep -qF -- "$required" "$security_workflow"; then
    printf 'Security workflow is missing baseline policy component: %s\n' \
      "$required" >&2
    exit 1
  fi
done

if grep -HnE '^[[:space:]]*(- )?uses:' "$security_workflow" \
  | grep -vE '@[0-9a-f]{40}([[:space:]]+#.*)?$'; then
  printf 'Security workflow actions and images must use immutable refs.\n' >&2
  exit 1
fi

if ! grep -qE 'semgrep/semgrep@sha256:[0-9a-f]{64}' "$security_workflow" || \
  grep -HnE 'semgrep/semgrep[^[:space:]#]*' "$security_workflow" \
    | grep -vE '@sha256:[0-9a-f]{64}([[:space:]]+#.*)?$'; then
  printf 'Security workflow actions and images must use immutable refs.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  function finish_checkout() {
    if (!in_checkout) return
    if (with_count != 1 || credentials_count != 1) failed = 1
    in_checkout = 0
  }
  {
    current_indent = indent($0)
    if ($0 ~ /^[[:space:]]+-[[:space:]]+uses:[[:space:]]+actions\/checkout@/) {
      finish_checkout()
      in_checkout = 1
      seen_checkout = 1
      checkout_indent = current_indent
      with_count = 0
      credentials_count = 0
      in_with = 0
      next
    }
    if (in_checkout && $0 !~ /^[[:space:]]*$/ && current_indent <= checkout_indent) {
      finish_checkout()
    }
    if (!in_checkout) next
    if ($0 ~ /^[[:space:]]+with:[[:space:]]*$/) {
      if (current_indent != checkout_indent + 2) failed = 1
      with_count++
      in_with = current_indent == checkout_indent + 2
      with_indent = current_indent
      next
    }
    if (in_with && $0 !~ /^[[:space:]]*$/ && current_indent <= with_indent) in_with = 0
    if ($0 ~ /^[[:space:]]+persist-credentials:/) {
      if (!in_with || current_indent != with_indent + 2 ||
          $0 !~ /^[[:space:]]+persist-credentials:[[:space:]]*false[[:space:]]*$/) {
        failed = 1
      }
      credentials_count++
    }
  }
  END {
    finish_checkout()
    exit seen_checkout && !failed ? 0 : 1
  }
' "$security_workflow"; then
  printf 'Security workflow checkout must disable persisted credentials.\n' >&2
  exit 1
fi
