#!/usr/bin/env bash
#
# ViMbAdmin ships Bootstrap 5. Bootstrap 5 removed Bootstrap 2's grid classes
# entirely: `.span1`-`.span12`, `.offset1`-`.offset12` and `.row-fluid` have no
# rules in `public/css/800-bootstrap.css` any more. A template that reintroduces
# one does not fail loudly — the element simply loses its width and the layout
# silently collapses to a full-width stacked block — so this scan is the
# regression guard for that class of visual-only breakage.
#
# The Bootstrap 5 spellings to use instead:
#   .span<N>    -> .col-md-<N>
#   .offset<N>  -> .offset-md-<N>
#   .row-fluid  -> .row
#
# Scope: own Smarty templates only, `application/views/**/*.phtml`.
#
# Deliberately NOT scanned:
#   - public/css/**, public/js/** — vendored Bootstrap and other third-party
#     assets legitimately contain these tokens, and the generated
#     `min.bundle-v*` artifacts are never hand-edited.
#   - DataTables `sDom` strings in application/views/*/js/*.js. Those encode
#     `span6` as a DataTables domPositioning TOKEN, not a CSS class; renaming
#     them would break the table layout API rather than fix anything. Their
#     Bootstrap 5 rewrite is separate work.
#
# Only `class="..."` / `class='...'` attribute values are scanned, so ordinary
# prose or an identifier that merely contains "span" is not a false positive.
#
# Exit 0 = clean, 1 = a Bootstrap 2 grid class was found in an own template.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# `**` needs globstar, which is a bash 4+ feature. On bash 3.2 (still the
# default /bin/bash on macOS) `application/views/**/*.phtml` silently degrades
# to a single-level glob: the file list is non-empty, every nested template goes
# unscanned, and the gate reports OK while having checked almost nothing. Refuse
# to run rather than emit a false clean verdict.
if [ "${BASH_VERSINFO[0]:-0}" -lt 4 ]; then
  echo "  -> bash 4+ required for globstar; refusing to run a partial scan." >&2
  exit 1
fi

# Both quoting styles: `class="span6"` and `class='span6'` are equally valid
# HTML and Smarty emits either, so a double-quote-only pattern would walk past
# half the possible regressions.
#
# `class[[:space:]]*=[[:space:]]*` rather than a bare `class=`: HTML permits
# whitespace on either side of the `=`, so `class = "span6"` is a valid
# regression that a tight pattern walks straight past.
#
# The scan is also whole-file rather than line-based (`grep -z`, and `[^"\x27]`
# therefore spans newlines), because a class attribute may wrap:
#   <div class="row
#               span6">
# is one attribute value on two lines, and a per-line scan sees neither half as
# a match.
patterns=(
  "class[[:space:]]*=[[:space:]]*[\"'][^\"']*\\brow-fluid\\b"
  "class[[:space:]]*=[[:space:]]*[\"'][^\"']*\\bspan[0-9]+\\b"
  "class[[:space:]]*=[[:space:]]*[\"'][^\"']*\\boffset[0-9]+\\b"
)
labels=(
  'row-fluid (use "row")'
  'spanN (use "col-md-N")'
  'offsetN (use "offset-md-N")'
)

# scan_files: run every Bootstrap 2 grid pattern over the given file list.
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
    # -z treats each file as one NUL-terminated record, so `[^"\x27]*` in the
    # pattern crosses newlines and a wrapped class attribute is matched. That
    # costs the line number, so report the filename and the offending value.
    hits=$(grep -zoHE "$pattern" "${files[@]}" 2>/dev/null | tr '\0' '\n' || true)
    if [ -n "$hits" ]; then
      echo "  Bootstrap 2 grid class '$label' found:"
      while IFS= read -r line; do
        echo "    $line"
      done <<<"$hits"
      fail=1
    fi
  done

  return "$fail"
}

# self_test: prove the scan actually fires on reintroduced Bootstrap 2 grid
# classes (negative control) and stays clean on Bootstrap 5 markup (positive
# control). Runs on every invocation so a mis-escaped pattern that degraded to
# always-pass cannot slip past CI unnoticed.
self_test() {
  local tmpdir dirty clean status=0

  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  dirty="$tmpdir/dirty.phtml"
  clean="$tmpdir/clean.phtml"

  # The single-quoted row is load-bearing: it is what proves the patterns are
  # not double-quote-only.
  cat >"$dirty" <<'EOF'
<div class="row-fluid">
    <div class="span6">left</div>
    <div class='span6'>right, single-quoted</div>
    <div class="span4 offset2">offset</div>
    <div class = "span6">whitespace around the equals sign</div>
    <div class="row
                span3">wrapped attribute value, spanning two lines</div>
</div>
EOF

  cat >"$clean" <<'EOF'
<div class="row">
    <div class="col-md-6">left</div>
    <div class='col-md-6'>right, single-quoted</div>
    <div class="col-md-4 offset-md-2">offset</div>
</div>
<p>The span of this deployment and its offset10 schedule are prose, not classes.</p>
EOF

  echo "== self-test: negative control, reintroduced Bootstrap 2 grid classes must be caught =="
  if ! scan_files "$dirty"; then
    echo "  OK: row-fluid / spanN / offsetN were detected, in both quoting styles"
  else
    echo "  FAIL: scan did not detect Bootstrap 2 grid classes in $dirty" >&2
    status=1
  fi

  echo "== self-test: Bootstrap 5 markup must not false-positive =="
  if scan_files "$clean" >/dev/null 2>&1; then
    echo "  OK: Bootstrap 5 template reported no Bootstrap 2 grid classes"
  else
    echo "  FAIL: scan reported a false positive on Bootstrap 5 markup" >&2
    status=1
  fi

  return "$status"
}

echo "== own templates must not use Bootstrap 2 grid classes =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob globstar
files=(application/views/**/*.phtml)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Own template inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: no Bootstrap 2 grid class in own templates (${#files[@]} templates scanned)"
  exit 0
else
  exit 1
fi
