#!/bin/bash
# Negative control: mutating the spinner wrapper must turn the spinner
# replacement suite RED. A control that stays green under mutation proves
# nothing, so the oracle here is the suite's exit status, not its output text.

set -e

PROJECT_ROOT="$(cd "$(dirname "$0")" && cd .. && pwd)"
cd "$PROJECT_ROOT"

SOURCE_FILE="public/js/990-vimbadmin.js"
SUITE="tests/test-spinner-replacement.php"

# Mutate a copy, never the tracked source: an abnormal exit must not be able
# to leave a broken working tree behind.
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

cp -a "$PROJECT_ROOT/." "$WORKDIR/"
cd "$WORKDIR"

# Baseline: the suite must pass on the unmutated tree, otherwise a red result
# after mutation would prove nothing about the mutation.
echo "Baseline: running suite against unmutated source..."
if ! php "$SUITE" > /dev/null 2>&1; then
    echo "FAIL: suite is already red before mutation; control is inconclusive" >&2
    exit 1
fi
echo "  OK: suite is green before mutation"

# Mutation: break the Bootstrap spinner class the wrapper depends on.
echo "Mutating $SOURCE_FILE ..."
perl -pi -e "s/spinner-border/OLD_THROBBER_BROKEN/g" "$SOURCE_FILE"

if ! grep -q 'OLD_THROBBER_BROKEN' "$SOURCE_FILE"; then
    echo "FAIL: mutation did not apply; the pattern no longer matches the source" >&2
    exit 1
fi

echo "Running suite against mutated source (it must fail)..."
set +e
php "$SUITE" > /dev/null 2>&1
mutated_status=$?
set -e

if [[ $mutated_status -eq 0 ]]; then
    echo "FAIL: negative control - the suite passed on mutated source" >&2
    exit 1
fi

echo "  OK: suite went red on the mutated source (exit $mutated_status)"
echo "Negative control successful"
