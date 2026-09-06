#!/usr/bin/env bash
#
# jQuery 4.0 removed a batch of long-deprecated APIs. This project ships
# jQuery 1.12.4 today (VIM-A15 phase 2), but own code is being kept clear of
# these APIs ahead of the eventual jQuery 3.7.1 (phase 3) and 4-safe baseline,
# so the removal never has to be a big-bang rewrite.
#
# Scope: own (non-vendor) JS only.
#   - application/views/*/js/*.js   (view-specific behaviour)
#   - public/js/9[0-9][0-9]-*.js    (900-999 prefix = own code; 100-899 is
#                                    vendored third-party and is never linted)
#
# public/js/min.bundle-v16.js is a generated build artifact (regenerated from
# the files above via vendor/bin/minify.php) and is deliberately excluded: it
# is never hand-edited and never linted directly.
#
# Exit 0 = clean, 1 = a removed/deprecated jQuery API was found in own code.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Parallel arrays: a grep -E pattern paired with the human-readable API name
# at the same index. (Not colon-joined single strings: a POSIX bracket class
# like [[:space:]] contains a literal ':', which broke a `%%:*` split.)
# Whitespace is tolerated around '.'/'(' so `.bind (`, `. bind(`,
# `jQuery . trim(` etc. cannot slip past the scan.
WS='[[:space:]]*'
patterns=(
  "\\.${WS}bind${WS}\\("
  "\\.${WS}unbind${WS}\\("
  "\\.${WS}delegate${WS}\\("
  "\\.${WS}undelegate${WS}\\("
  "jQuery${WS}\\.${WS}trim${WS}\\("
  "\\\$${WS}\\.${WS}trim${WS}\\("
  "jQuery${WS}\\.${WS}parseJSON${WS}\\("
  "\\\$${WS}\\.${WS}parseJSON${WS}\\("
  "jQuery${WS}\\.${WS}isArray${WS}\\("
  "\\\$${WS}\\.${WS}isArray${WS}\\("
  "jQuery${WS}\\.${WS}isFunction${WS}\\("
  "\\\$${WS}\\.${WS}isFunction${WS}\\("
  "jQuery${WS}\\.${WS}isNumeric${WS}\\("
  "\\\$${WS}\\.${WS}isNumeric${WS}\\("
  "jQuery${WS}\\.${WS}type${WS}\\("
  "\\\$${WS}\\.${WS}type${WS}\\("
  "\\.${WS}live${WS}\\("
  "\\.${WS}size${WS}\\(${WS}\\)"
  "jQuery${WS}\\.${WS}browser"
  "\\\$${WS}\\.${WS}browser"
)
labels=(
  '.bind('
  '.unbind('
  '.delegate('
  '.undelegate('
  'jQuery.trim'
  '$.trim'
  'jQuery.parseJSON'
  '$.parseJSON'
  'jQuery.isArray'
  '$.isArray'
  'jQuery.isFunction'
  '$.isFunction'
  'jQuery.isNumeric'
  '$.isNumeric'
  'jQuery.type('
  '$.type('
  '.live('
  '.size()'
  'jQuery.browser'
  '$.browser'
)

# scan_files: run every removed-API pattern over the given file list.
# Prints each hit and returns 1 if any pattern matched, 0 if clean.
scan_files() {
  local files=("$@")
  local i pattern label hits fail=0

  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi

  for i in "${!patterns[@]}"; do
    pattern="${patterns[$i]}"
    label="${labels[$i]}"
    hits=$(grep -nE "$pattern" "${files[@]}" 2>/dev/null || true)
    if [ -n "$hits" ]; then
      echo "  removed API '$label' found:"
      echo "$hits" | sed 's/^/    /'
      fail=1
    fi
  done

  return "$fail"
}

# self_test: prove the scan actually fires on a reintroduced removed API
# (negative control) and stays clean on ordinary code (positive control).
# Runs as part of every invocation so a mis-escaped pattern degrading to
# always-pass cannot go unnoticed by CI.
self_test() {
  local tmpdir dirty clean status=0

  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  dirty="$tmpdir/dirty.js"
  clean="$tmpdir/clean.js"

  cat > "$dirty" <<'EOF'
$( '#thing' ).bind( 'click', f );
EOF

  cat > "$clean" <<'EOF'
$( '#thing' ).on( 'click', f );
EOF

  echo "== self-test: negative control, a reintroduced .bind() must be caught =="
  if scan_files "$dirty" >/dev/null 2>&1; then
    echo "  FAIL: scan did not detect reintroduced .bind( 'click', f ) in $dirty" >&2
    status=1
  else
    echo "  OK: reintroduced .bind( ) was detected"
  fi

  echo "== self-test: clean code must not false-positive =="
  if scan_files "$clean" >/dev/null 2>&1; then
    echo "  OK: clean file reported no removed APIs"
  else
    echo "  FAIL: scan reported a false positive on clean code" >&2
    status=1
  fi

  return "$status"
}

echo "== own JS must not use jQuery APIs removed in 4.0 =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob
files=(application/views/*/js/*.js public/js/9[0-9][0-9]-*.js)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Own JS inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: no jQuery-4-removed API in own JS"
  exit 0
else
  exit 1
fi
