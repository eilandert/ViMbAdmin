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
readonly semgrep_finding_contract=.github/scripts/assert-semgrep-findings.sh
if grep -HnE '\|\|[[:space:]]*true' "$security_workflow"; then
  printf 'Security Semgrep workflow must not mask command failures.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  $0 ~ /^  semgrep:[[:space:]]*$/ {
    in_semgrep = 1
    saw_semgrep = 1
    next
  }
  in_semgrep && $0 !~ /^[[:space:]]*$/ && indent($0) <= 2 {
    in_semgrep = 0
  }
  !in_semgrep { next }
  $0 ~ /^    defaults:[[:space:]]*$/ {
    in_defaults = 1
    next
  }
  in_defaults && $0 !~ /^[[:space:]]*$/ && indent($0) <= 4 {
    in_defaults = 0
    in_run = 0
  }
  !in_defaults { next }
  $0 ~ /^      run:[[:space:]]*$/ {
    in_run = 1
    next
  }
  in_run && $0 !~ /^[[:space:]]*$/ && indent($0) <= 6 {
    in_run = 0
  }
  in_run && $0 ~ /^        shell:[[:space:]]*bash[[:space:]]*$/ {
    found = 1
  }
  END { exit found ? 0 : 1 }
' "$security_workflow"; then
  printf 'Security Semgrep workflow must run every inline command with Bash.\n' >&2
  exit 1
fi

if ! awk '
  function indent(line, leading) {
    leading = line
    sub(/[^ ].*$/, "", leading)
    return length(leading)
  }
  function trim(line) {
    gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
    return line
  }
  function is_non_bash_shell(line, quote, separator, position, key, value) {
    line = trim(line)
    quote = substr(line, 1, 1)
    if (quote == "\"" || quote == sprintf("%c", 39)) {
      separator = quote ":"
      position = index(line, separator)
      if (!position) return 0
      key = substr(line, 2, position - 2)
      value = substr(line, position + 2)
    } else {
      position = index(line, ":")
      if (!position) return 0
      key = substr(line, 1, position - 1)
      value = substr(line, position + 1)
    }
    return trim(key) == "shell" && trim(value) != "bash"
  }
  $0 ~ /^  semgrep:[[:space:]]*$/ {
    in_semgrep = 1
    saw_semgrep = 1
    next
  }
  in_semgrep && $0 !~ /^[[:space:]]*$/ && indent($0) <= 2 {
    in_semgrep = 0
    in_steps = 0
  }
  !in_semgrep { next }
  $0 ~ /^    steps:[[:space:]]*$/ {
    in_steps = 1
    saw_steps = 1
    next
  }
  in_steps && $0 !~ /^[[:space:]]*$/ && indent($0) <= 4 {
    in_steps = 0
  }
  in_steps && indent($0) == 8 && is_non_bash_shell($0) {
    failed = 1
  }
  END { exit (failed || !saw_semgrep || !saw_steps) ? 1 : 0 }
' "$security_workflow"; then
  printf 'Security Semgrep steps may only override their shell with Bash.\n' >&2
  exit 1
fi

# Registry aliases are floating inputs. Normalize shell continuations and
# adjacent quotes before looking for their operand tokens.
if awk '
  {
    line = $0
    while (sub(/\\$/, "", line)) {
      if ((getline continuation) <= 0) break
      sub(/^[[:space:]]*/, "", continuation)
      line = line continuation
    }
    gsub(/["'\''"]/, "", line)
    print line
  }
' "$security_workflow" "$semgrep_finding_contract" \
  | grep -qE -- '(^|[^[:alnum:]_.-])(p|r)/[[:alnum:]_.-]+'; then
  printf 'Security workflow must not use floating Semgrep Registry inputs.\n' >&2
  exit 1
fi

for required in \
  'fetch-depth: 0' \
  'select-semgrep-baseline.sh' \
  'PR_BASE_SHA' \
  'BEFORE_SHA' \
  'HEAD_SHA' \
  '--baseline-commit' \
  'assert-semgrep-findings.sh' \
  'tests/test-semgrep-baseline.sh' \
  'tests/test-semgrep-rule-lock.sh' \
  'fetch-semgrep-rules.sh' \
  '--config .semgrep-rules/php.yml' \
  '--config .semgrep-rules/security-audit.yml' \
  '--config .semgrep-rules/secrets.yml'; do
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
    if (field != 3) failed = 1
    in_checkout = 0
  }
  {
    current_indent = indent($0)
    if (in_checkout && $0 !~ /^[[:space:]]*$/ &&
        current_indent <= checkout_indent) finish_checkout()

    if (index($0, "actions/checkout@")) {
      checkout_occurrences++
      if ($0 !~ /^      - uses: actions\/checkout@/) {
        failed = 1
        next
      }
      ref = $0
      sub(/^      - uses: actions\/checkout@/, "", ref)
      if (length(ref) != 40 || ref !~ /^[0-9a-f]+$/) failed = 1
      finish_checkout()
      in_checkout = 1
      checkout_indent = current_indent
      field = 0
      next
    }
    if (!in_checkout) next
    if ($0 ~ /^[[:space:]]*$/) next

    if (field == 0 && $0 == "        with:") field = 1
    else if (field == 1 && $0 == "          fetch-depth: 0") field = 2
    else if (field == 2 &&
             $0 == "          persist-credentials: false") field = 3
    else failed = 1
  }
  END {
    finish_checkout()
    exit checkout_occurrences == 1 && !failed ? 0 : 1
  }
' "$security_workflow"; then
  printf 'Security workflow checkout must disable persisted credentials.\n' >&2
  exit 1
fi
