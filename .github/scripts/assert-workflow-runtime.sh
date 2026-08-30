#!/usr/bin/env bash
set -euo pipefail

readonly workflows=(
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
  'mirror\.gcr\.io/library/(php|mariadb)@sha256:' "${workflows[@]}" \
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
actual_php_containers=$(grep -hEc '^[[:space:]]+container:' "${workflows[@]}" \
  | awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_php_containers -ne $expected_php_containers ]]; then
  printf 'Expected %d PHP container jobs, found %d.\n' \
    "$expected_php_containers" "$actual_php_containers" >&2
  exit 1
fi

actual_checkout_preparations=$(grep -hEc \
  'name: Prepare PHP container for checkout' "${workflows[@]}" \
  | awk '{ total += $1 } END { print total + 0 }')

if [[ $actual_checkout_preparations -ne $expected_php_containers ]]; then
  printf 'Expected %d container checkout preparations, found %d.\n' \
    "$expected_php_containers" "$actual_checkout_preparations" >&2
  exit 1
fi

printf 'Workflow runtime contract holds for %d PHP container jobs.\n' \
  "$expected_php_containers"
