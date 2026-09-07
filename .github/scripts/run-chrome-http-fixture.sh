#!/usr/bin/env bash

# Purpose: serve a private browser fixture over loopback, then run Chrome.
# Usage: run-chrome-http-fixture.sh <document-root> [chrome arguments...]
# Inputs: a readable document root and Chrome CLI arguments; output is Chrome's.
# Side effects: creates one marker in the root, starts one loopback-only PHP
# server, and removes/stops only those owned resources on exit.
# Limits: waits at most two seconds for readiness, and each probe is itself
# bounded so a listener that accepts but never replies cannot outlive that
# budget; VIMBADMIN_FIXTURE_PORT and VIMBADMIN_FIXTURE_ROUTER are deterministic
# test overrides, the latter existing so the readiness regression can serve a
# deliberately stalling response. Extend the readiness bound here.

set -euo pipefail

if [[ ${1:-} == --help ]]; then
  sed -n '3,7p' "$0"
  exit 0
fi
if [[ $# -lt 1 || ! -d $1 ]]; then
  echo 'FAIL: fixture document root is required' >&2
  exit 64
fi

root=$1
shift
port=${VIMBADMIN_FIXTURE_PORT:-}
if [[ -z $port ]]; then
  # The program is literal PHP, not a shell interpolation site.
  # shellcheck disable=SC2016
  port=$(php -r '
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errorNumber, $errorMessage);
    if ($socket === false) exit(1);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    echo substr(strrchr($name, ":"), 1);
  ')
fi
if [[ ! $port =~ ^[0-9]+$ || $port -lt 1024 || $port -gt 65535 ]]; then
  echo 'FAIL: fixture HTTP port is invalid' >&2
  exit 64
fi

# VIMBADMIN_FIXTURE_ROUTER is a test-only seam: php -S runs a router script for
# every request, which is how the readiness regression produces a response that
# accepts and then stalls. Unset in normal use, so the root is served statically.
# Validated before the marker exists, so a bad value cannot exit past the
# cleanup trap and leave the marker behind in the fixture root.
router=${VIMBADMIN_FIXTURE_ROUTER:-}
if [[ -n $router && ! -f $router ]]; then
  echo 'FAIL: fixture router override is not a file' >&2
  exit 64
fi

marker_name=.vimbadmin-ready-$$-$RANDOM
marker_value=vimbadmin-fixture-$$-$RANDOM
printf '%s' "$marker_value" >"$root/$marker_name"

if [[ -n $router ]]; then
  php -S "127.0.0.1:$port" -t "$root" "$router" >/dev/null 2>&1 &
else
  php -S "127.0.0.1:$port" -t "$root" >/dev/null 2>&1 &
fi
server=$!
cleanup() {
  kill "$server" 2>/dev/null || true
  rm -f -- "$root/$marker_name"
}
trap cleanup EXIT

# Readiness is bounded twice: each probe gets its own connect+read timeout so a
# listener that accepts and then stalls cannot block, and the whole loop gets a
# wall-clock deadline so the accumulated probe time cannot exceed the budget
# either. Without both, an accepting-but-silent socket hangs the runner forever.
# The deadline uses bash's own SECONDS so the wait stays bounded even when PHP
# itself is unavailable or failing; a clock that depends on the thing being
# probed is not a bound.
readiness_budget=${VIMBADMIN_FIXTURE_READY_SECONDS:-2}
probe_timeout=${VIMBADMIN_FIXTURE_PROBE_SECONDS:-0.5}
SECONDS=0

ready=false
while :; do
  if (( SECONDS >= readiness_budget )); then
    break
  fi
  # The probe body is literal PHP; only its argv are dynamic. PHP clamps the
  # timeout to whatever is left of the budget, so a listener that accepts just
  # before the deadline cannot stall past it; bash keeps the outer clock.
  # shellcheck disable=SC2016
  if kill -0 "$server" 2>/dev/null &&
    [[ $(php -r '
      $remaining = (float) $argv[3] - (float) $argv[4];
      $timeout = min((float) $argv[2], $remaining);
      if ($timeout <= 0) exit(0);
      $context = stream_context_create(["http" => [
        "timeout" => $timeout,
        "ignore_errors" => true,
      ]]);
      $body = @file_get_contents($argv[1], false, $context);
      if ($body !== false) echo $body;
    ' "http://127.0.0.1:$port/$marker_name" "$probe_timeout" \
      "$readiness_budget" "$SECONDS") == "$marker_value" ]]; then
    ready=true
    break
  fi
  sleep 0.05
done
if [[ $ready != true ]]; then
  echo 'FAIL: fixture HTTP server did not become ready' >&2
  exit 1
fi

chrome_args=()
for arg in "$@"; do
  chrome_args+=("${arg/http:\/\/127.0.0.1:8765/http:\/\/127.0.0.1:$port}")
done
chrome_bin=${CHROME_BIN:-google-chrome}
"$chrome_bin" --no-sandbox "${chrome_args[@]}"
