#!/usr/bin/env bash

# Prove the fixture HTTP runner's readiness wait is actually bounded. The
# runner probes a loopback marker URL; a server that accepts the connection and
# then withholds the body is the failure mode a naive probe cannot survive, because
# an untimed read blocks forever and the documented two-second budget becomes
# fiction. The negative control below is that exact socket.

set -euo pipefail

cd "$(dirname "$0")/.."

readonly runner=.github/scripts/run-chrome-http-fixture.sh

if [[ ! -x $runner ]]; then
  echo "FAIL: $runner is missing or not executable" >&2
  exit 2
fi
if ! command -v php >/dev/null 2>&1; then
  echo 'FAIL: php is required for the fixture readiness regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-fixture-readiness.XXXXXX)
cleanup() {
  rm -rf "${tmp:?}"
}
trap cleanup EXIT

# A fake Chrome: readiness succeeded if and only if this runs.
cat >"$tmp/fake-chrome" <<'FAKE'
#!/usr/bin/env bash
echo REACHED_CHROME
FAKE
chmod +x "$tmp/fake-chrome"

echo '<!doctype html><title>fixture</title>' >"$tmp/index.html"

# --- Positive: a real server must be reached, and quickly. ---
start=$(date +%s)
if ! CHROME_BIN=$tmp/fake-chrome "$runner" "$tmp" --dummy >"$tmp/positive.log" 2>&1; then
  echo 'FAIL: runner did not start against a healthy fixture root' >&2
  cat "$tmp/positive.log" >&2
  exit 1
fi
elapsed=$(($(date +%s) - start))
if ! grep -q REACHED_CHROME "$tmp/positive.log"; then
  echo 'FAIL: runner reported readiness but never invoked the browser' >&2
  exit 1
fi
if (( elapsed > 5 )); then
  echo "FAIL: healthy fixture readiness took ${elapsed}s, expected well under the budget" >&2
  exit 1
fi

# --- Negative control: a live server whose responses stall. ---
# The runner starts its own `php -S` in the document root, so squatting the port
# is NOT a valid control: the runner's server dies on the bind conflict and the
# `kill -0 "$server"` guard short-circuits the loop before any read happens.
# That passes for the wrong reason. Instead, keep the runner's server healthy
# and make the *marker response itself* stall, via a router script that sleeps
# far longer than the readiness budget. This is the real failure mode: a socket
# that accepts and then withholds the body.
stall_root=$tmp/stall
mkdir -p "$stall_root"
cat >"$stall_root/router.php" <<'ROUTER'
<?php
// Accept the request, then withhold the response well past any sane budget.
// php -S runs this for every request, so the readiness probe connects
// successfully and then waits on a body that never arrives.
sleep(120);
ROUTER

start=$(date +%s)
set +e
CHROME_BIN=$tmp/fake-chrome \
  VIMBADMIN_FIXTURE_ROUTER="$stall_root/router.php" \
  timeout 25 "$runner" "$stall_root" --dummy >"$tmp/negative.log" 2>&1
status=$?
set -e
elapsed=$(($(date +%s) - start))

if (( status == 124 )); then
  echo "FAIL: readiness probe hung against a stalling responder (killed at ${elapsed}s)" >&2
  exit 1
fi
if (( status == 0 )); then
  echo 'FAIL: runner claimed readiness against a server that never responded' >&2
  exit 1
fi
if ! grep -q 'did not become ready' "$tmp/negative.log"; then
  echo 'FAIL: runner failed against the stalling responder for the wrong reason' >&2
  cat "$tmp/negative.log" >&2
  exit 1
fi
if grep -q REACHED_CHROME "$tmp/negative.log"; then
  echo 'FAIL: runner launched the browser despite an unready fixture' >&2
  exit 1
fi
# The budget is 2s by default. Allow generous slack for PHP process startup on a
# loaded CI box, but stay far below the 120s the stalling responder would take
# if any probe were unbounded.
if (( elapsed > 15 )); then
  echo "FAIL: stalling-responder failure took ${elapsed}s, past the readiness budget" >&2
  exit 1
fi

# A probe that starts just before the deadline must be clamped to the time that
# is left, not granted a fresh full timeout. Give the loop a long per-probe
# timeout against a short budget: if the probe ignored the remaining time it
# would run for the full 30s.
start=$(date +%s)
set +e
CHROME_BIN=$tmp/fake-chrome \
  VIMBADMIN_FIXTURE_ROUTER="$stall_root/router.php" \
  VIMBADMIN_FIXTURE_READY_SECONDS=2 \
  VIMBADMIN_FIXTURE_PROBE_SECONDS=30 \
  timeout 25 "$runner" "$stall_root" --dummy >"$tmp/clamp.log" 2>&1
status=$?
set -e
elapsed=$(($(date +%s) - start))

if (( status == 124 )); then
  echo "FAIL: a long per-probe timeout was not clamped to the readiness budget (killed at ${elapsed}s)" >&2
  exit 1
fi
if (( elapsed > 15 )); then
  echo "FAIL: probe timeout ignored the remaining budget; took ${elapsed}s" >&2
  exit 1
fi
if ! grep -q 'did not become ready' "$tmp/clamp.log"; then
  echo 'FAIL: clamped probe failed for the wrong reason' >&2
  cat "$tmp/clamp.log" >&2
  exit 1
fi

echo 'OK: fixture HTTP readiness is bounded and rejects a stalling responder'
