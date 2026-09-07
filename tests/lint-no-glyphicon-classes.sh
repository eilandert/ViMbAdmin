#!/usr/bin/env bash
#
# VIM-A15.18 replaced the Bootstrap 2 Glyphicons `icon-*` classes with
# self-hosted Bootstrap Icons (`bi-*`) and deleted the glyphicons-halflings
# PNG sprites. Neither can silently regress: a reintroduced `icon-*` class
# just loses its icon glyph (Bootstrap 5 ships no `.icon-*` rule), and a
# reintroduced glyphicons reference points at an asset that no longer exists.
# This is the guard for that class of breakage, sibling of
# tests/lint-bs2-component-classes.sh, tests/lint-bs2-grid-classes.sh,
# tests/lint-bs5-data-attrs.sh and tests/lint-modal-aria-labelledby.sh.
#
# Unlike those gates this one does NOT parse `class="..."` attribute values.
# The 19 legacy names are a closed, known list and none of them is a
# substring of any real Bootstrap 5 / Bootstrap Icons / project class name
# (`bi-*` never collides with `icon-*`), so a plain whole-word literal search
# for the 19 names plus the string `glyphicons` is sufficient and far more
# robust than re-deriving attribute/tag parsing for a two-item job. See
# lessons.md on hand-rolled markup gates meeting spellings their author never
# enumerated -- the fix here is to not need a spelling-sensitive parser at
# all, not to enumerate harder.
#
# Scope: the whole tree, not just own templates. The done criterion is
# "nothing survives anywhere" (PHP, CSS, JS, docs), not "own Smarty views are
# clean" -- a stray reference in a comment or a vendored-but-hand-edited file
# is exactly as real a regression as one in application/views. Excluded:
# .git/ (not shipped content) and this gate's own self-test fixtures (they
# must contain the literal strings to prove the scan fires).
#
# Exit 0 = clean, 1 = a glyphicon icon-* class or a glyphicons-halflings
# reference was found.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# The 19 legacy Glyphicons icon-* names VIM-A15.18 replaced, plus the bare
# `glyphicons` token (catches `glyphicons-halflings[-white].png` references
# and any other glyphicons-branded mention). Word-boundary literal match:
# `\b` on the LEFT only for the icon- names (their right edge is already
# name-specific and a `\b` there would also refuse to match e.g. icon-plus
# immediately followed by a `"`, which is exactly the case we need to catch --
# `"` is not a word character so `\b` DOES match there; kept for clarity, not
# because it changes behaviour) and both edges for the bare word "glyphicons".
names=(
  icon-plus icon-trash icon-pencil icon-remove-circle icon-lock
  icon-eye-close icon-eye-open icon-align-justify icon-wrench icon-user
  icon-time icon-retweet icon-random icon-off icon-minus icon-inbox
  icon-envelope icon-screenshot icon-qrcode
)

build_pattern() {
  local joined
  joined=$(printf '%s|' "${names[@]}")
  joined=${joined%|}
  printf '%s' "\\b(${joined})\\b|\\bglyphicons\\b"
}

pattern=$(build_pattern)

# scan_files: grep the given files for the pattern. Prints each hit (grep -n)
# and returns 1 if any file matched, 0 if clean. `-E` for the alternation,
# `-I` to skip anything grep sniffs as binary (the woff/woff2 font files a
# case-insensitive match on their compressed bytes could otherwise spuriously
# hit), `-r` is not used -- callers pass an explicit file list so an
# unreadable-file bug can be told apart from "found nothing" (see the missing
# check below), which a silent recursive skip cannot do.
scan_files() {
  local files=("$@") fail=0

  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi

  if grep -InE "$pattern" "${files[@]}"; then
    fail=1
  fi

  return "$fail"
}

# self_test: prove the scan fires on every seeded shape and stays clean on
# genuine Bootstrap Icons markup. Runs on every invocation so a mis-escaped
# pattern that degraded to always-pass cannot slip past CI unnoticed.
self_test() {
  local tmpdir dirty clean status=0 expected_hits actual_hits

  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  dirty="$tmpdir/dirty.phtml"
  clean="$tmpdir/clean.phtml"

  # Covers: whitespace around `=`, single vs double quotes, a Smarty {if}
  # span inside the class attribute, a split/multi-line opening tag, two
  # elements on one line, the target not first/last in a class list, a
  # glyphicons PNG reference, and a JS-string-concatenation shape (the
  # STRING-CONCATENATION case the packet calls out explicitly -- a
  # phtml-only version of this gate would report clean over it, exactly the
  # VIM-A15.19-round-2 failure mode this repo has already hit once).
  cat >"$dirty" <<'EOF'
<i class="icon-plus"></i>
<i class ="icon-trash"></i>
<i class= "icon-pencil"></i>
<i class = "icon-remove-circle"></i>
<i class='icon-lock'></i>
<i class="{if $x}icon-eye-close{/if}"></i>
<i
  class="icon-eye-open"
></i>
<i class="icon-align-justify"></i><i class="icon-wrench"></i>
<button class="btn btn-sm icon-user"></button>
<button class="icon-time btn"></button>
<i class="icon-retweet"></i>
<i class="icon-random"></i>
<i class="icon-off"></i>
<i class="icon-minus"></i>
<i class="icon-inbox"></i>
<i class="icon-envelope"></i>
<i class="icon-screenshot"></i>
<i class="icon-qrcode"></i>
<img src="/img/glyphicons-halflings.png" alt="" />
<img src="/img/glyphicons-halflings-white.png" alt="" />
EOF

  cat >"$clean" <<'EOF'
<i class="bi-plus-lg"></i>
<i class ="bi-trash"></i>
<i class= "bi-pencil"></i>
<i class = "bi-x-circle"></i>
<i class='bi-lock'></i>
<i class="{if $x}bi-eye-slash{/if}"></i>
<i
  class="bi-eye"
></i>
<i class="bi-list-ul"></i><i class="bi-wrench"></i>
<button class="btn btn-sm bi-person"></button>
<button class="bi-clock-history btn"></button>
<i class="bi-arrow-repeat"></i>
<i class="bi-shuffle"></i>
<i class="bi-power"></i>
<i class="bi-dash-lg"></i>
<i class="bi-archive"></i>
<i class="bi-envelope"></i>
<i class="bi-display"></i>
<i class="bi-qr-code"></i>
EOF

  echo "== self-test: negative control, reintroduced glyphicon classes and glyphicons-halflings refs must be caught =="
  # 19 icon- rows + 2 glyphicons-halflings PNG rows = 21 matches, but the
  # icon-align-justify/icon-wrench row seeds TWO matches on ONE line (two
  # elements on one line, deliberately) -- so it is 20 grep -n LINES, not 21.
  # Count matches with grep -o (matches, not lines) to keep the seed count
  # honest regardless of how many share a line.
  expected_hits=21
  actual_hits=$(grep -oInE "$pattern" "$dirty" 2>/dev/null | wc -l || true)
  if [ "$actual_hits" -eq "$expected_hits" ]; then
    echo "  OK: all $expected_hits seeded regressions detected, across quoting/whitespace/Smarty/multi-line/glyphicons shapes"
  else
    echo "  FAIL: expected $expected_hits hits in $dirty, got $actual_hits" >&2
    status=1
  fi

  echo "== self-test: Bootstrap Icons markup must not false-positive =="
  if scan_files "$clean" >/dev/null 2>&1; then
    echo "  OK: Bootstrap Icons template reported no glyphicon classes"
  else
    echo "  FAIL: scan reported a false positive on Bootstrap Icons markup" >&2
    status=1
  fi

  echo "== self-test: view-JS string-concatenation negative control =="
  local jsdirty="$tmpdir/dirty-list.js"
  cat >"$jsdirty" <<'EOF'
str += '<button class="btn btn-sm have-tooltip" id="repair_' + id + '">\
        <i class="icon-wrench"></i></button>';
str += "<span class=\"icon-trash\">" + label + "</span>";
EOF
  local js_expected_hits=2 js_actual_hits
  js_actual_hits=$(grep -oInE "$pattern" "$jsdirty" 2>/dev/null | wc -l || true)
  if [ "$js_actual_hits" -eq "$js_expected_hits" ]; then
    echo "  OK: all $js_expected_hits seeded view-JS regressions detected"
  else
    echo "  FAIL: expected $js_expected_hits hits in $jsdirty, got $js_actual_hits" >&2
    status=1
  fi

  local jsclean="$tmpdir/clean-list.js"
  cat >"$jsclean" <<'EOF'
str += '<button class="btn btn-sm have-tooltip" id="repair_' + id + '">\
        <i class="bi-wrench"></i></button>';
str += "<span class=\"bi-trash\">" + label + "</span>";
EOF
  if scan_files "$jsclean" >/dev/null 2>&1; then
    echo "  OK: Bootstrap Icons view-JS reported no glyphicon classes"
  else
    echo "  FAIL: scan reported a false positive on Bootstrap Icons view-JS" >&2
    status=1
  fi

  echo "== self-test: unrelated bi-*/icon- lookalikes must not false-positive =="
  # A `bi-` name is never a target, and an unrelated `icon-` compound that is
  # NOT one of the 19 legacy names (e.g. a hypothetical future icon-search)
  # must not be flagged -- this gate guards a closed list, not the prefix.
  local lookalike="$tmpdir/lookalike.phtml"
  cat >"$lookalike" <<'EOF'
<i class="bi-search"></i>
<i class="icon-search"></i>
<p>Some prose mentions glyphicon-like icons without the exact word.</p>
EOF
  if scan_files "$lookalike" >/dev/null 2>&1; then
    echo "  OK: unrelated bi-*/icon-* names and near-miss prose were not flagged"
  else
    echo "  FAIL: scan incorrectly flagged an unrelated bi-*/icon-* name" >&2
    status=1
  fi

  return "$status"
}

echo "== no Glyphicons icon-* class or glyphicons-halflings reference anywhere in the tree =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob globstar

# Whole tree, minus .git (not shipped content) and the tests/lint-*.sh gate
# scripts themselves. This gate's own script has to contain the literal
# names in its names[] list and self-test fixtures to prove the scan fires,
# and tests/lint-bs2-component-classes.sh carries its OWN icon-*/icon-wrench
# self-test fixtures for the opposite proof (that IT correctly ignores
# glyphicon classes as out of its scope, per that file's own comments) -- in
# both cases the string lives in shell heredoc test data, not markup that
# ships to a browser, so it is not this gate's job to flag it.
# Materialise to a temp file and check find's own exit status before
# reading it back: a `mapfile < <(find ...)` process substitution returns
# mapfile's status, never find's, so a find that fails partway through
# (permission error, a source directory vanishing mid-walk) leaves a
# TRUNCATED list that still passes the `count > 0` guard below -- the gate
# would then certify only the files it reached and report PASS over a
# subset. Fail loudly instead of trusting an unchecked producer.
inventory=$(mktemp)
trap 'rm -f "$inventory"' EXIT
if ! find . -type d -name .git -prune -o \
  -type f -not -path './tests/lint-*.sh' -print0 >"$inventory"; then
  echo "  -> find failed while building the project file inventory; refusing to report a partial scan as clean." >&2
  exit 1
fi
mapfile -d '' -t files <"$inventory"

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Project file inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: no Glyphicons icon-* class or glyphicons-halflings reference found (${#files[@]} files scanned)"
  exit 0
else
  exit 1
fi
