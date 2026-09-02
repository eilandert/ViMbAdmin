#!/usr/bin/env bash
set -euo pipefail

# Proves the coverage guard reads the unit runner's exclusions rather than a
# duplicated list. The mutation below must leave test-date.php unowned and
# therefore make the guard fail.
guard=$PWD/tests/assert-regression-coverage.sh
runner=$PWD/tests/run-unit-tests.sh
phpstan_runner=$PWD/tests/run-phpstan-tests.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-regression-coverage.XXXXXX")

cleanup() {
  rm -rf -- "$test_root"
}
trap cleanup EXIT

mkdir -p "$test_root/tests" "$test_root/.github/workflows"
cp -- "$guard" "$runner" "$phpstan_runner" "$test_root/tests/"
chmod +x "$test_root/tests/assert-regression-coverage.sh" \
  "$test_root/tests/run-unit-tests.sh" \
  "$test_root/tests/run-phpstan-tests.sh"

cat >"$test_root/.github/workflows/regression.yml" <<'YAML'
jobs:
  unit:
    steps:
      - run: bash tests/run-unit-tests.sh
  cache:
    steps:
      - run: php tests/test-cache-bootstrap.php
      - run: php tests/test-kernel-em-factory.php
      - run: php tests/test-kernel-smarty-view.php
      - run: php tests/test-oss-message.php
  schema:
    steps:
      - run: php tests/test-schema-no-pending.php
YAML

cat >"$test_root/.github/workflows/static-analysis.yml" <<'YAML'
jobs:
  static:
    steps:
      - run: bash tests/run-phpstan-tests.sh
YAML

cp -- "$test_root/.github/workflows/regression.yml" "$test_root/regression.yml.valid"
cp -- "$test_root/.github/workflows/static-analysis.yml" "$test_root/static-analysis.yml.valid"

for test in \
  test-cache-bootstrap.php \
  test-kernel-em-factory.php \
  test-kernel-smarty-view.php \
  test-oss-message.php \
  test-schema-no-pending.php \
  test-date.php; do
  printf '%s\n' '<?php' >"$test_root/tests/$test"
done
git -C "$test_root" init -q
git -C "$test_root" config user.email 'test@example.invalid'
git -C "$test_root" config user.name 'Regression coverage test'
git -C "$test_root" add tests .github regression.yml.valid static-analysis.yml.valid
git -C "$test_root" commit -q --no-gpg-sign -m fixture

run_guard() {
  if (cd "$test_root" && bash tests/assert-regression-coverage.sh) >"$test_root/output" 2>&1; then
    guard_status=0
  else
    guard_status=$?
  fi
}

if git -C "$test_root" status --short | grep -q .; then
  printf 'Fixture unexpectedly became dirty.\n' >&2
  exit 1
fi
run_guard
[[ $guard_status -eq 0 ]] || {
  cat "$test_root/output" >&2
  exit 1
}

# shellcheck disable=SC2016 # Match the runner's literal loop variable.
sed -i '/^[[:space:]]*php "\$test"$/d' "$test_root/tests/run-unit-tests.sh"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a unit runner that discovers tests without executing them.\n' >&2
  exit 1
}
grep -qF 'Unit test runner does not discover and execute tracked PHP tests.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

expect_unreachable() {
  local test=$1

  run_guard
  [[ $guard_status -ne 0 ]] || {
    printf 'Coverage guard accepted an unexecuted test: %s\n' "$test" >&2
    exit 1
  }
  grep -qF "Dedicated regression test is not reachable: $test" \
    "$test_root/output" || {
    cat "$test_root/output" >&2
    exit 1
  }
}

expect_owner_rejected() {
  local label=$1 message=$2

  run_guard
  [[ $guard_status -ne 0 ]] || {
    printf 'Coverage guard accepted a disabled owner: %s\n' "$label" >&2
    exit 1
  }
  grep -qF "$message" "$test_root/output" || {
    cat "$test_root/output" >&2
    exit 1
  }
}

sed -i "s|php tests/test-cache-bootstrap.php|php -r 'echo \\\"tests/test-cache-bootstrap.php\\\";'|" \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php tests/test-cache-bootstrap.php|php tests/test-cache-bootstrap.php.suffix|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|bash tests/run-unit-tests.sh|echo disabled # tests/run-unit-tests.sh|' \
  "$test_root/.github/workflows/regression.yml"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a disabled unit runner.\n' >&2
  exit 1
}
grep -qF 'Regression workflow does not invoke the unit test runner.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|bash tests/run-phpstan-tests.sh|echo tests/test-phpstan-*.php|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'disabled PHPStan test runner' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php tests/test-cache-bootstrap.php|echo php tests/test-cache-bootstrap.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash tests/run-unit-tests.sh|      - run: bash tests/run-unit-tests.sh\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'conditional unit runner' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash tests/run-unit-tests.sh|      - if: ${{ false }}\n        run: bash tests/run-unit-tests.sh|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner with an initial conditional field' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: php tests/test-cache-bootstrap.php|      - run: php tests/test-cache-bootstrap.php\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: php tests/test-cache-bootstrap.php|      - if: ${{ false }}\n        run: php tests/test-cache-bootstrap.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|      - run: bash tests/run-phpstan-tests.sh|      - run: bash tests/run-phpstan-tests.sh\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'conditional PHPStan runner' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|      - run: bash tests/run-phpstan-tests.sh|      - if: ${{ false }}\n        run: bash tests/run-phpstan-tests.sh|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner with an initial conditional field' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i "s@      - run: bash tests/run-unit-tests.sh@      - run: |\\n          if [[ -n \${UNSET_ENV:-} ]]; then\\n            bash tests/run-unit-tests.sh\\n          fi@" \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner shell conditional' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i "s@      - run: bash tests/run-phpstan-tests.sh@      - run: |\\n          if [[ -n \${UNSET_ENV:-} ]]; then\\n            bash tests/run-phpstan-tests.sh\\n          fi@" \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner shell conditional' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i '/readonly excluded_tests=(/a\  tests/test-date.php' \
  "$test_root/tests/run-unit-tests.sh"
expect_unreachable tests/test-date.php

printf 'Regression coverage guard rejects an excluded test without an owner.\n'
