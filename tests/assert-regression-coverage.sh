#!/usr/bin/env bash
set -euo pipefail

# Keep the partition in one executable guard. A newly tracked test is either
# picked up by the unit glob, by the PHPStan glob, or rejected here when it is
# accidentally added to a dedicated-job exclusion without a corresponding CI
# command.
readonly unit_runner=tests/run-unit-tests.sh
readonly phpstan_runner=tests/run-phpstan-tests.sh
readonly static_workflow=.github/workflows/static-analysis.yml
readonly regression_workflow=.github/workflows/regression.yml

for runner in "$unit_runner" "$phpstan_runner"; do
  [[ -x $runner ]] || {
    printf 'Test runner is missing or not executable: %s\n' "$runner" >&2
    exit 1
  }
done

if ! grep -qF "git ls-files 'tests/test-phpstan-*.php'" "$phpstan_runner" || \
  ! grep -qF "php \"\$test\"" "$phpstan_runner"; then
  printf 'PHPStan test runner does not discover and execute tracked PHPStan tests.\n' >&2
  exit 1
fi

workflow_has_shell_command() {
  local workflow=$1 command=$2

  awk -v command="$command" '
    function indent(line, leading) {
      leading = line
      sub(/[^ ].*$/, "", leading)
      return length(leading)
    }
    function is_command(line) {
      sub(/[[:space:]]+#.*/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      return line == command
    }
    {
      current_indent = indent($0)
      if ($0 ~ /^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/) {
        run_indent = current_indent
        line = $0
        sub(/^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/, "", line)
        in_run_block = line ~ /^[>|]/
        if (is_command(line)) found = 1
        next
      }
      if (in_run_block && $0 !~ /^[[:space:]]*$/ && current_indent <= run_indent) {
        in_run_block = 0
      }
      if (in_run_block && is_command($0)) found = 1
    }
    END { exit found ? 0 : 1 }
  ' "$workflow"
}

workflow_has_php_test_command() {
  local workflow=$1 test=$2

  awk -v test="$test" '
    function indent(line, leading) {
      leading = line
      sub(/[^ ].*$/, "", leading)
      return length(leading)
    }
    function is_php_test_command(line, fields, count, field_index) {
      sub(/[[:space:]]+#.*/, "", line)
      count = split(line, fields, /[[:space:]]+/)
      if (fields[1] != "php") return 0
      for (field_index = 2; field_index < count; field_index++) {
        if (fields[field_index] == "-d" || fields[field_index] == "--define") {
          field_index++
          continue
        }
        if (fields[field_index] ~ /^-d[^[:space:]]+$/ ||
            fields[field_index] ~ /^--define=[^[:space:]]+$/) continue
        return 0
      }
      return fields[count] == test
    }
    {
      current_indent = indent($0)
      if ($0 ~ /^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/) {
        run_indent = current_indent
        line = $0
        sub(/^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/, "", line)
        in_run_block = line ~ /^[>|]/
        if (is_php_test_command(line)) found = 1
        next
      }
      if (in_run_block && $0 !~ /^[[:space:]]*$/ && current_indent <= run_indent) {
        in_run_block = 0
      }
      if (in_run_block && is_php_test_command($0)) found = 1
    }
    END { exit found ? 0 : 1 }
  ' "$workflow"
}

workflow_has_phpstan_test_loop() {
  awk '
    function indent(line, leading) {
      leading = line
      sub(/[^ ].*$/, "", leading)
      return length(leading)
    }
    function normalized(line) {
      sub(/[[:space:]]+#.*/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      return line
    }
    function inspect(line) {
      line = normalized(line)
      if (loop_state == 0 && line == "for test in tests/test-phpstan-*.php; do") {
        loop_state = 1
      } else if (loop_state == 1 && line == "php \"$test\"") {
        loop_state = 2
      } else if (loop_state == 2 && line == "done") {
        found = 1
      }
    }
    {
      current_indent = indent($0)
      if ($0 ~ /^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/) {
        run_indent = current_indent
        line = $0
        sub(/^[[:space:]]*(-[[:space:]]+)?run:[[:space:]]*/, "", line)
        in_run_block = line ~ /^[>|]/
        loop_state = 0
        if (in_run_block) inspect(line)
        next
      }
      if (in_run_block && $0 !~ /^[[:space:]]*$/ && current_indent <= run_indent) {
        in_run_block = 0
        loop_state = 0
      }
      if (in_run_block) inspect($0)
    }
    END { exit found ? 0 : 1 }
  ' "$static_workflow"
}

workflow_has_unconditional_owner_step() {
  local workflow=$1 kind=$2 target=$3

  awk -v kind="$kind" -v target="$target" '
    function indent(line, leading) {
      leading = line
      sub(/[^ ].*$/, "", leading)
      return length(leading)
    }
    function normalize(line) {
      sub(/[[:space:]]#.*/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      return line
    }
    function matches_owner(line, fields, count, field_index) {
      line = normalize(line)
      if (kind == "command") return line == target
      count = split(line, fields, /[[:space:]]+/)
      if (fields[1] != "php") return 0
      for (field_index = 2; field_index < count; field_index++) {
        if (fields[field_index] == "-d" || fields[field_index] == "--define") {
          field_index++
          continue
        }
        if (fields[field_index] ~ /^-d[^[:space:]]+$/ ||
            fields[field_index] ~ /^--define=[^[:space:]]+$/) continue
        return 0
      }
      return fields[count] == target
    }
    function finish_step() {
      if (in_step && run_count == 1 && !has_if && matches_owner(run)) found = 1
      in_step = 0
    }
    {
      current_indent = indent($0)
      if ($0 ~ /^[[:space:]]+-[[:space:]]+/) {
        if (!in_step || current_indent <= step_indent) {
          finish_step()
          in_step = 1
          step_indent = current_indent
          has_if = 0
          run_count = 0
          line = $0
          sub(/^[[:space:]]+-[[:space:]]+/, "", line)
          if (line ~ /^if:[[:space:]]*/) {
            has_if = 1
          } else if (line ~ /^run:[[:space:]]*/) {
            run_count = 1
            sub(/^run:[[:space:]]*/, "", line)
            run = line
          }
        }
        next
      }
      if (in_step && $0 !~ /^[[:space:]]*$/ && current_indent <= step_indent) finish_step()
      if (!in_step || current_indent != step_indent + 2) next
      if ($0 ~ /^[[:space:]]+if:[[:space:]]*/) has_if = 1
      if ($0 ~ /^[[:space:]]+run:[[:space:]]*/) {
        run_count++
        line = $0
        sub(/^[[:space:]]+run:[[:space:]]*/, "", line)
        run = line
      }
    }
    END {
      finish_step()
      exit found ? 0 : 1
    }
  ' "$workflow"
}

workflow_has_unconditional_owner_step "$regression_workflow" command \
  'bash tests/run-unit-tests.sh' || {
  printf 'Regression workflow does not invoke the unit test runner.\n' >&2
  exit 1
}
workflow_has_unconditional_owner_step "$static_workflow" command \
  'bash tests/run-phpstan-tests.sh' || {
  printf 'Static-analysis workflow does not invoke the PHPStan test runner.\n' >&2
  exit 1
}

workflow_runs_php_test() {
  local test=$1

  workflow_has_unconditional_owner_step "$regression_workflow" php "$test"
}

manifest=$(mktemp)
excluded_manifest=$(mktemp)
cleanup() {
  rm -f "$manifest" "$excluded_manifest"
}
trap cleanup EXIT

if ! git ls-files 'tests/test-*.php' | sort > "$manifest"; then
  printf 'Could not enumerate tracked PHP tests.\n' >&2
  exit 1
fi

if ! "$unit_runner" --print-excluded-tests > "$excluded_manifest"; then
  printf 'Could not enumerate unit-runner exclusions.\n' >&2
  exit 1
fi

declare -A excluded_tests=()
while IFS= read -r test; do
  [[ -n $test ]] || {
    printf 'Unit test runner reported an empty exclusion.\n' >&2
    exit 1
  }
  [[ -z ${excluded_tests[$test]+x} ]] || {
    printf 'Unit test runner reports a duplicate exclusion: %s\n' "$test" >&2
    exit 1
  }
  grep -qxF "$test" "$manifest" || {
    printf 'Unit test runner excludes an untracked PHP test: %s\n' "$test" >&2
    exit 1
  }
  workflow_runs_php_test "$test" || {
    printf 'Dedicated regression test is not reachable: %s\n' "$test" >&2
    exit 1
  }
  excluded_tests[$test]=1
done < "$excluded_manifest"

while IFS= read -r test; do
  case "$test" in
    tests/test-phpstan-*.php)
      workflow_has_unconditional_owner_step "$static_workflow" command \
        'bash tests/run-phpstan-tests.sh' || exit 1
      ;;
    *)
      if [[ -n ${excluded_tests[$test]+x} ]]; then
      workflow_runs_php_test "$test" || exit 1
      else
      # The unit runner's tracked-test glob covers all remaining tests.
      grep -qF "git ls-files 'tests/test-*.php'" "$unit_runner" || exit 1
      fi
      ;;
  esac
done < "$manifest"

printf 'All tracked PHP regression tests are assigned to a CI job.\n'
