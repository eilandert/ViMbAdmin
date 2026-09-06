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

echo "== own JS must not use jQuery APIs removed in 4.0 =="

shopt -s nullglob
files=(application/views/*/js/*.js public/js/9[0-9][0-9]-*.js)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Own JS inventory is empty." >&2
  exit 1
fi

# Each entry: a grep -E pattern paired with the human-readable API name.
patterns=(
  '\.bind\(:.bind('
  '\.unbind\(:.unbind('
  '\.delegate\(:.delegate('
  '\.undelegate\(:.undelegate('
  'jQuery\.trim\(:jQuery.trim'
  '\$\.trim\(:$.trim'
  'jQuery\.parseJSON\(:jQuery.parseJSON'
  '\$\.parseJSON\(:$.parseJSON'
  '\$\.isArray\(:$.isArray'
  '\$\.isFunction\(:$.isFunction'
  '\$\.isNumeric\(:$.isNumeric'
  '\$\.type\(:$.type('
  '\.live\(:.live('
  '\.size\(\):.size()'
  '\$\.browser:$.browser'
)

fail=0

for entry in "${patterns[@]}"; do
  pattern="${entry%%:*}"
  label="${entry#*:}"
  hits=$(grep -nE "$pattern" "${files[@]}" 2>/dev/null || true)
  if [ -n "$hits" ]; then
    echo "  removed API '$label' found:"
    echo "$hits" | sed 's/^/    /'
    fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "  OK: no jQuery-4-removed API in own JS"
fi

exit "$fail"
