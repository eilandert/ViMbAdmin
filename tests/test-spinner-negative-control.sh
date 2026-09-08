#!/bin/bash
# Negative control: Verify that if we break the spinner replacement, the tests fail

set -e

PROJECT_ROOT="$(cd "$(dirname "$0")" && cd .. && pwd)"
cd "$PROJECT_ROOT"

# Create a backup of the wrapper function
TEST_FILE="public/js/990-vimbadmin.js"
BACKUP="${TEST_FILE}.backup"
cp "$TEST_FILE" "$BACKUP"
trap "mv '$BACKUP' '$TEST_FILE'" EXIT

echo "Running negative control: mutating wrapper function..."

# Mutate the source: remove the spinner-border class creation to simulate the old code
# This breaks the replacement logic
sed -i "s/\.addClass('spinner-border')/\.addClass('OLD_THROBBER_BROKEN')/" "$TEST_FILE"

# Try to run the test - it should FAIL because we broke the code
if php -r "
require_once 'tests/test-spinner-replacement.php';
\$test = new Test_SpinnerReplacement();
try {
    \$test->test_throbber_library_removed();
    echo 'FAIL: test should have caught the mutation';
    exit(1);
} catch (Exception \$e) {
    echo 'FAIL: test threw exception unexpectedly';
    exit(1);
}
" 2>&1 | grep -q "FAIL\|FAIL"; then
    echo "Negative control FAILED (as expected) - mutation was not detected"
    exit 1
fi

# Restore original
mv "$BACKUP" "$TEST_FILE"

# Now verify the test passes with the correct code
echo "Verifying test passes with correct implementation..."

php -r "
require_once 'tests/test-spinner-replacement.php';
\$test = new Test_SpinnerReplacement();
\$test->test_throbber_library_removed();
echo 'Negative control PASSED - correct code passes the test';
" 2>&1

echo "Negative control successful"
