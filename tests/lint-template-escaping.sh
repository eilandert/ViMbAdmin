#!/usr/bin/env bash
#
# Regression guard: Smarty 5 JS-template escaping.
#
# Background (commits 455af0e, 3fc37ea, lesson
# feedback-vimbadmin-smarty5-js-escaping):
#
#   library/OSS/View/Smarty.php calls setEscapeHtml(true), so EVERY {$var}
#   in a template is run through htmlspecialchars(ENT_QUOTES) by default.
#   In HTML (.phtml) templates that is correct and desirable. In a JavaScript
#   template (application/views/*/js/*.js) it is a footgun: a json_encode()
#   array emitted as {$emails} becomes  [&quot;a@b&quot;]  — a JS syntax error
#   that throws and silently kills the whole <script> block. That is exactly
#   how the alias [+] (add-goto) button died after the Smarty 5 bump.
#
#   The correct shapes inside a .js template are:
#     * {$jsonArray nofilter}              for json_encode() output (raw)
#     * {$stringValue|escape:'javascript'} for a scalar string value
#
# This lint looks for the SPECIFIC dangerous pattern that bit us: a value that
# is assigned from a json_encode() Smarty variable, or fed to a typeahead/
# DataTables `source:` / used as a JS array/object literal, emitted as a bare
# {$var} (no nofilter / no escape:'javascript'). It deliberately does NOT flag
# simple token emits like {$value} / {$item.url} in the DataTables action
# builders — those are server-defined tokens without HTML-special chars and
# have always worked under escaping.
#
# Exit 0 = clean, 1 = a json/array value is emitted into JS unescaped.
#
set -euo pipefail

# Resolve this script's own directory to an ABSOLUTE path BEFORE any `cd`.
# `$0` is relative when the gate is invoked as `bash lint-bs2-grid-classes.sh`
# from inside tests/, so `$(dirname "$0")` re-resolves against whatever the cwd
# is at the moment it is evaluated -- after the `cd` below it would mean the
# repo root, not tests/. Resolving once, up front, is what makes the gate work
# from any cwd. (VIM-A15.31 follow-up: this is the same silent-wrong-path class
# the shared lexer exists to remove, so the gates must not reintroduce it.)
script_dir=$(unset CDPATH; cd -- "$(dirname -- "$0")" && pwd)

cd "$script_dir/.."

# Shared Smarty-aware value scanner (VIM-A15.31/.42). Sourced, not registered
# as its own gate: it is a library, and its behaviour is asserted through the
# self-test below.
# shellcheck source=tests/support/smarty-lexer.sh
source "$script_dir/support/smarty-lexer.sh"

# The JS object keys whose value must never be a bare {$var} emit.
GUARDED_KEYS='source|data|aaData|aoColumns|columns'

# scan_file FILE
#   Prints one `<line>:<text>` record per unescaped emit. Returns 0 always;
#   test the output, not $?.
scan_file() {
  smarty_unescaped_js_keys "$1" "$GUARDED_KEYS"
}

# self_test: prove the scan fires on each dangerous spelling (negative control)
# and stays quiet on each safe one (positive control). The gate had NO self-test
# before VIM-A15.42, which is how three consecutive review rounds each shipped
# green while each left a real blind spot behind.
self_test() {
  local tmpdir dirty clean status=0 expected actual probe_hits
  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN
  dirty="$tmpdir/dirty.js"
  clean="$tmpdir/clean.js"

  cat >"$dirty" <<'EOF'
var a = { source: {$emails} };
var b = { 'data': {$rows} };
var c = { "data": {$rows} };
var d = "'data': {$rows}";
var e = { aaData: {$rows} };
EOF

  # Line 4 above is VIM-A15.42: a PAIRED quoted key that immediately follows a
  # string delimiter. The old preceding-character guard rejected it, because the
  # same rule that (correctly) rejects an unmatched `'data:` also excluded a
  # quote here. It is now decided by context instead.
  cat >"$clean" <<'EOF'
var a = { mydata: {$rows} };
var b = { 'data: {$rows} };
var c = { data: {$rows nofilter} };
var d = { source: {$emails|escape:'javascript'} };
EOF

  # Non-vacuity probe for the clean fixture. A file the scan simply cannot see
  # -- wrong glob, unreadable, an early `return` -- would also report zero hits,
  # so "0 hits on $clean" alone does not prove the four spellings above are
  # being REJECTED rather than never examined. Append one real finding, require
  # it to be caught, then remove it and require the file to go quiet again.
  # Both halves are observed, which is what the earlier write-then-delete round
  # trip failed to do (it deleted the line before anything read it).
  # SC2016: `{$rows}` is literal Smarty source text in the fixture, not a shell
  # expansion -- single quotes are correct.
  # shellcheck disable=SC2016
  echo 'var e = { data: {$rows} };' >>"$clean"
  probe_hits=$(scan_file "$clean" | grep -c . || true)
  if [ "$probe_hits" -lt 1 ]; then
    echo "  FAIL: clean fixture is not being scanned at all -- the seeded finding went unreported" >&2
    status=1
  fi
  # Rewrite without the seeded line (no `sed -i`, which is GNU-only and fails
  # on BSD/macOS sed without a backup suffix).
  grep -v '^var e = ' "$clean" >"$clean.tmp" && mv "$clean.tmp" "$clean"

  echo "== self-test: negative control, unescaped JS emits must be caught =="
  expected=5
  actual=$(scan_file "$dirty" | grep -c . || true)
  if [ "$actual" -eq "$expected" ]; then
    echo "  OK: all $expected seeded unescaped emits detected"
  else
    echo "  FAIL: expected $expected hits in $dirty, got $actual" >&2
    status=1
  fi

  echo "== self-test: safe and non-key spellings must not false-positive =="
  actual=$(scan_file "$clean" | grep -c . || true)
  if [ "$actual" -eq 0 ]; then
    echo "  OK: mydata:, an unmatched-quote key, nofilter and escape:'javascript' all rejected"
  else
    echo "  FAIL: expected 0 hits in $clean, got $actual" >&2
    scan_file "$clean" | sed 's/^/    /' >&2
    status=1
  fi

  return "$status"
}

fail=0
shopt -s nullglob

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

echo "== JS templates: json/array values must be nofilter or escape:'javascript' =="

for f in application/views/*/js/*.js; do
  # Lines that look like a JS array/object source being fed a Smarty var:
  #   source: {$emails}      |  data: {$rows}   |  = {$foo}.split(   etc.
  # i.e. a {$var} that sits where a JSON literal is expected.
  #
  # The key/quote/escape rules all live in the shared Smarty lexer now; see
  # smarty_unescaped_js_keys() in tests/support/smarty-lexer.pl for why they
  # could not be expressed as a preceding-character guard (VIM-A15.42).
  hits=$(scan_file "$f")
  if [ -n "$hits" ]; then
    echo "  $f:"
    echo "$hits" | sed 's/^/    /'
    echo "    -> emit json_encode() arrays with |nofilter (raw) in a JS context."
    fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "  OK: no json/array Smarty var emitted unescaped into a JS literal"
fi

exit $fail
