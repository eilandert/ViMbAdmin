#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

readonly runner=.github/scripts/run-headless-chrome.sh
readonly php_runner=.github/scripts/run-static-php-container.sh
readonly dollar='$'

require() {
  local pattern=$1
  if ! grep -Fq -- "$pattern" "$runner"; then
    echo "FAIL: Chrome container runner must contain: $pattern" >&2
    exit 1
  fi
}

require "readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'"
require 'exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges'
require "  --user \"${dollar}(id -u):${dollar}(id -g)\""
require "  --env \"HOME=${dollar}fixture_dir\""
require "  --mount \"type=bind,src=${dollar}fixture_dir,dst=${dollar}fixture_dir\""
require "  \"${dollar}image\" google-chrome --no-sandbox \"${dollar}@\""
require 'vimbadmin-(alias-destination|residual-stored-xss)'

require_php() {
  local pattern=$1
  if ! grep -Fq -- "$pattern" "$php_runner"; then
    echo "FAIL: Static PHP container runner must contain: $pattern" >&2
    exit 1
  fi
}

require_php "readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'"
require_php 'docker run --rm --network bridge --cap-drop ALL'
require_php 'exec docker run --rm --network none --cap-drop ALL'
require_php "--mount \"type=bind,src=${dollar}workspace,dst=${dollar}workspace,readonly\""
require_php "--mount \"type=bind,src=${dollar}writable_dir,dst=${dollar}writable_dir\""
require_php 'vimbadmin-(alias-destination|residual-stored-xss)'

if grep -Eq -- '(^|[[:space:]])--(privileged|cap-add)([=[:space:]]|$)|apt(-get)?[[:space:]]+(install|update)' "$runner"; then
  echo 'FAIL: Chrome container runner must not elevate or install host packages' >&2
  exit 1
fi

fixture_root=$(mktemp -d /tmp/vimbadmin-alias-destination.XXXXXX)
readonly fixture_root
readonly fixture_dir=$fixture_root
readonly stub_dir=$fixture_root/bin
readonly docker_args=$fixture_root/docker-args
readonly expected_args=$fixture_root/expected-args
readonly output=$fixture_root/output
foreign_root=$(mktemp -d /tmp/vimbadmin-chrome-foreign.XXXXXX)
readonly foreign_root

cleanup() {
  rm -rf -- "$fixture_root"
  rm -rf -- "$foreign_root"
}
trap cleanup EXIT

mkdir -p "$stub_dir" "$foreign_root/profile"
cat >"$stub_dir/docker" <<'SH'
#!/bin/sh
printf '%s\n' "$@" > "$VIMBADMIN_DOCKER_ARGS"
SH
chmod +x "$stub_dir/docker"

run_runner() {
  PATH="$stub_dir:/usr/bin:/bin" \
    VIMBADMIN_DOCKER_ARGS="$docker_args" \
    "$runner" "$@" >"$output" 2>&1
}

run_runner \
  --headless \
  --user-data-dir="$fixture_dir/profile" \
  --dump-dom "file://$fixture_dir/regression.html"

cat >"$expected_args" <<EOF
run
--rm
--network
none
--cap-drop
ALL
--security-opt
no-new-privileges
--user
$(id -u):$(id -g)
--env
HOME=$fixture_dir
--mount
type=bind,src=$fixture_dir,dst=$fixture_dir
cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d
google-chrome
--no-sandbox
--headless
--user-data-dir=$fixture_dir/profile
--dump-dom
file://$fixture_dir/regression.html
EOF
cmp -- "$expected_args" "$docker_args"

if run_runner --headless; then
  echo 'FAIL: Chrome container runner accepted a missing profile directory' >&2
  exit 1
fi
grep -qF 'requires a test-owned --user-data-dir' "$output"

if run_runner --user-data-dir="$fixture_dir/not-profile"; then
  echo 'FAIL: Chrome container runner accepted a non-profile user-data directory' >&2
  exit 1
fi
grep -qF 'requires a test-owned --user-data-dir' "$output"

if run_runner --user-data-dir="$foreign_root/profile"; then
  echo 'FAIL: Chrome container runner accepted a writable foreign profile' >&2
  exit 1
fi
grep -qF 'requires a private test fixture directory' "$output"

echo 'OK: Chrome runner image and confinement are pinned'
