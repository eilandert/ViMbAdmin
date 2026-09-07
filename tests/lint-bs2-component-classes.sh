#!/usr/bin/env bash
#
# ViMbAdmin ships Bootstrap 5. Bootstrap 5 renamed or dropped several
# Bootstrap 2 component classes; a template that reintroduces one does not
# fail loudly -- the element just loses its Bootstrap 5 styling and the
# regression is visual-only -- so this scan is the guard for that class of
# breakage (VIM-A15.19, sibling of tests/lint-bs2-grid-classes.sh which
# guards the grid classes).
#
# The Bootstrap 5 spellings to use instead:
#   .well                  -> .card / .card-body (no direct successor; pick a
#                              structure, this only guards the literal class)
#   .label                 -> .badge text-bg-secondary (bare, no variant)
#   .label-important       -> .badge text-bg-danger
#   .label-success         -> .badge text-bg-success
#   .label-info            -> .badge text-bg-info
#   .label-warning         -> .badge text-bg-warning
#   .btn-mini / .btn-small -> .btn-sm
#   .pull-right            -> .float-end on a plain float target (e.g. a
#                              `<ul class="nav">`); a `<ul class="dropdown-menu">`
#                              is positioned by Bootstrap 5's own dropdown JS
#                              (Popper), not float, so its Bootstrap 5
#                              alignment class is `.dropdown-menu-end`, not
#                              `.float-end` -- check which element you have
#                              before substituting.
#   .pull-left             -> .float-start (same caveat as pull-right)
#   .alert-error           -> .alert-danger
#   .nav-collapse          -> .navbar-collapse
#   .navbar-inner          -> no successor; restructure (drop the wrapper)
#
# Scope: own Smarty templates AND own view-JS that builds markup client-side
# via string concatenation, since a Bootstrap 2 component class embedded in a
# JS string literal is exactly as live a regression as one in a .phtml file
# and is otherwise invisible to a templates-only scan (VIM-A15.19 round 2:
# `application/views/*/js/*.js` DataTables row-action/mRender builders and
# `public/js/990-vimbadmin.js`'s dropdown-toggle markup both shipped
# reintroduced classes that a `*.phtml`-only version of this gate could not
# see and reported clean over). Concretely:
# `application/views/**/*.phtml`, `application/views/**/js/*.js`,
# `public/js/990-vimbadmin.js`.
#
# Deliberately NOT scanned:
#   - public/css/**, and public/js/** other than 990-vimbadmin.js -- vendored
#     Bootstrap and other third-party assets legitimately contain these
#     tokens, and the generated `min.bundle-v*` artifacts are never
#     hand-edited.
#   - DataTables `sDom` strings, which live in these SAME view-JS files now
#     scanned above (e.g. `"sDom": "<'row'<'span6'l>...`). They encode
#     `row`/`span6`/positioning as DataTables domPositioning TOKENS, not CSS
#     classes, and only ever appear inside a `"sDom": "..."` value, never a
#     `class="..."` attribute value -- extract_class_values() only looks at
#     `class=`/`class =`, so an sDom string is never even a candidate. That
#     rewrite is VIM-A15.23's separate work regardless.
#   - Glyphicon `icon-*` classes (VIM-A15.18's separate scope).
#   - `.alert-error` reachable only through
#     library/OSS/Smarty/functions/function.OSS_Message.php's `{$class}`
#     Smarty variable (frozen API, `OSS_Message::ERROR` = the literal string
#     `'error'`) is not a literal `alert-error` class TOKEN in a template, so
#     it is not and cannot be flagged by this scan -- it is covered by the
#     `.alert-error` compatibility shim in public/css/895-bootstrap-override.css
#     instead.
#
# Only `class="..."` / `class='...'` attribute values are scanned, so ordinary
# prose or an identifier that merely contains one of these words is not a
# false positive (`aria-label`, `control-label`, `well-known`, `text-error`
# form validation classes are not our target classes and are not matched).
#
# Exit 0 = clean, 1 = a Bootstrap 2 component class was found in an own
# template.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# `**` needs globstar, a bash 4+ feature; see lint-bs2-grid-classes.sh for the
# same refusal-over-silent-partial-scan rationale.
if [ "${BASH_VERSINFO[0]:-0}" -lt 4 ]; then
  echo "  -> bash 4+ required for globstar; refusing to run a partial scan." >&2
  exit 1
fi

# Two-stage design (extract-then-tokenise), not a single regex -- copied from
# lint-bs2-grid-classes.sh, where three review rounds proved a single regex
# cannot express this check without false positives/negatives.
#
#   1. extract_class_values pulls out every `class` attribute VALUE, whole,
#      including values wrapping across lines or containing a Smarty
#      expression with its own inner quotes.
#   2. The value is split on whitespace and each token compared against the
#      Bootstrap 2 component-class spellings exactly.
#
# Exact token comparison is what makes `well-known`, `custom-label`,
# `label-wrapper`, `btn-minimal`, `pulled-right`, `sub-navbar-inner-thing` and
# `col-md-6` non-matches without any boundary regex to get wrong -- they are
# simply different tokens.
bs2_tokens_re='^(well|label|label-important|label-success|label-info|label-warning|btn-mini|btn-small|pull-right|pull-left|alert-error|nav-collapse|navbar-inner)$'

# extract_class_values FILE
# Prints one line per class attribute value found, as `<value>`. A value that
# wrapped across lines is printed with its newlines collapsed to spaces, which
# is exactly the tokenisation stage 2 wants anyway.
extract_class_values() {
  perl -0777 -ne '
    # View-JS builds `class="..."` markup inside a JS double-quoted string
    # literal, which escapes the delimiter as `\"` (e.g.
    # `"<span class=\"btn btn-sm\">"`), so the delimiter itself may be
    # preceded by a literal backslash (`\\?["\x27]`), and the value may
    # contain escaped characters (`\\.`).
    #
    # The "ordinary char" branch excludes ONLY the delimiter that actually
    # opened this attribute (captured as $1 and back-referenced via the
    # (?!\1) guard), not both quote characters. Excluding both would drop a
    # Smarty condition that legitimately embeds the OTHER quote, e.g.
    # `class="{if $x == 'bad'}alert-error{/if}"` -- the value there contains
    # single quotes inside a double-quoted attribute, and the old
    # `[^\\"\x27]` branch truncated it before `alert-error`, so the gate
    # reported green on a real regression (VIM-A15.19 round 3, caught by
    # review). The guard is a single-character negative lookahead, NOT a
    # re-test of the whole delimiter run, so the two branches stay
    # non-overlapping and this does not reintroduce the catastrophic
    # backtracking a naive lookahead hit on
    # application/views/mailbox/js/list.js in round 2.
    while (/(?:^|[\s])class\s*=\s*(\\?)(["\x27])((?:(?!\1\2)(?:\\.|[^\\]))*)\1\2(?=[\s>\/]|$)/gs) {
      my $v = $3;
      $v =~ s/\\(.)/$1/g;
      $v =~ s/\s+/ /g;
      print "$v\n";
    }
  ' "$1"
}

# scan_files: extract every class attribute value from the given files and
# compare each of its tokens against the Bootstrap 2 component spellings.
# Prints each hit and returns 1 if any token matched, 0 if clean.
scan_files() {
  local files=("$@")
  local f value token smarty_split fail=0
  local -a tokens

  # Word-splitting the attribute value under nullglob (used by the main scan
  # below) would pathname-expand and silently drop tokens matching no file, so
  # globbing is disabled explicitly for the split instead
  # ([[feedback: mem_09a2c69e89c24a6c8ab3501251ac562b]]).
  set -f

  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi

  for f in "${files[@]}"; do
    while IFS= read -r value; do
      # Smarty emits classes from inside `{if}`/`{/if}` with no surrounding
      # whitespace (`class="{if $wide}label-success{/if}"`), so the class
      # token is not whitespace-delimited there. Replace the Smarty
      # delimiters with spaces before tokenising.
      #
      # Two plain substitutions rather than one `[{}]` character class:
      # inside `${...//...}` the `}` of the class is read as the end of the
      # expansion, which silently mangles the value instead of substituting
      # ([[feedback: mem_09a2c69e89c24a6c8ab3501251ac562b]]).
      smarty_split="${value//\{/ }"
      smarty_split="${smarty_split//\}/ }"
      read -r -a tokens <<<"$smarty_split"
      for token in "${tokens[@]}"; do
        if [[ "$token" =~ $bs2_tokens_re ]]; then
          echo "  Bootstrap 2 component class '$token' in $f: class=\"$value\""
          fail=1
        fi
      done
    done < <(extract_class_values "$f")
  done

  set +f
  return "$fail"
}

# self_test: prove the scan fires on reintroduced Bootstrap 2 component
# classes (negative control) and stays clean on Bootstrap 5 markup (positive
# control), plus stays clean on sDom strings and icon-* classes which are out
# of this item's scope. Runs on every invocation so a mis-escaped pattern that
# degraded to always-pass cannot slip past CI unnoticed.
self_test() {
  local tmpdir dirty clean status=0 expected_hits actual_hits

  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  dirty="$tmpdir/dirty.phtml"
  clean="$tmpdir/clean.phtml"

  # The single-quoted row is load-bearing: it is what proves the patterns are
  # not double-quote-only. The final row guards the mixed-delimiter case:
  # a Smarty string literal in the OTHER quote inside the attribute value.
  cat >"$dirty" <<'EOF'
<div class="well">panel</div>
<div class='well'>right, single-quoted</div>
<span class="label">bare label</span>
<span class="label label-important">important</span>
<span class="label label-success">success</span>
<span class="label label-info">info</span>
<span class="label label-warning">warning</span>
<button class="btn btn-mini">mini</button>
<button class="btn btn-small">small</button>
<ul class="nav pull-right">right</ul>
<ul class="nav pull-left">left</ul>
<div class="alert alert-error">error</div>
<div class="nav-collapse">collapse</div>
<div class="navbar-inner">inner</div>
<div class="{if $wide}well{else}navbar-inner{/if}">Smarty expression inside the
    attribute value: its inner quotes must not end the scan early</div>
<div class="{if $state == 'bad'}alert-error{/if}">A Smarty condition whose own
    string literal uses the OTHER quote character. The extractor must exclude
    only the delimiter that opened this attribute, or it truncates the value at
    the apostrophe and never sees alert-error (VIM-A15.19 round 3).</div>
EOF

  cat >"$clean" <<'EOF'
<div class="card card-body">panel</div>
<span class="badge text-bg-secondary">bare</span>
<span class="badge text-bg-danger">important</span>
<span class="badge text-bg-success">success</span>
<span class="badge text-bg-info">info</span>
<span class="badge text-bg-warning">warning</span>
<button class="btn btn-sm">small</button>
<ul class="nav float-end">right</ul>
<ul class="nav float-start">left</ul>
<div class="alert alert-danger">error</div>
<div class="navbar-collapse">collapse</div>
<p>As well as being a well-known label, the wells were labelled with a small
   pull toward the navbar innermost collapse. -- prose, not classes.</p>
<label class="control-label optional">not aria-label / control-label</label>
<button class="btn-close" aria-label="Close"></button>
<div data-class="well">a data-* attribute is not the class attribute</div>
<div ng-class="well">nor is ng-class</div>
<div class="well-known-thing label-wrapper btn-minimal pulled-right
    sub-navbar-inner-thing nav-collapse-panel">own class names that merely
    start or end with a guarded spelling are not the guarded class</div>
EOF

  echo "== self-test: negative control, reintroduced Bootstrap 2 component classes must be caught =="
  # Real Bootstrap 2 markup pairs the base `.label` with its variant
  # (`class="label label-important"`), and both `label` and `label-important`
  # independently match the guarded-class set -- so each of the four
  # label-variant rows seeds 2 hits, not 1: 10 single-hit rows (well x2, bare
  # label, btn-mini, btn-small, pull-right, pull-left, alert-error,
  # nav-collapse, navbar-inner) + 4 label-variant rows x 2 hits (8) + 2 hits
  # inside the trailing Smarty {if}/{else} expression (well, navbar-inner)
  # = 10 + 8 + 2 = 20.
  expected_hits=21
  actual_hits=$(scan_files "$dirty" | grep -c 'Bootstrap 2 component class' || true)
  if [ "$actual_hits" -eq "$expected_hits" ]; then
    echo "  OK: all $expected_hits seeded regressions detected, in both quoting styles"
  else
    echo "  FAIL: expected $expected_hits hits in $dirty, got $actual_hits" >&2
    status=1
  fi

  echo "== self-test: Bootstrap 5 markup must not false-positive =="
  if scan_files "$clean" >/dev/null 2>&1; then
    echo "  OK: Bootstrap 5 template reported no Bootstrap 2 component classes"
  else
    echo "  FAIL: scan reported a false positive on Bootstrap 5 markup" >&2
    status=1
  fi

  echo "== self-test: sDom strings and icon-* classes must not false-positive =="
  local jsfixture="$tmpdir/view.js"
  cat >"$jsfixture" <<'EOF'
var oTable = $('#list').dataTable( {
    "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>"
} );
EOF
  local phpfixture="$tmpdir/icons.phtml"
  cat >"$phpfixture" <<'EOF'
<i class="icon-plus"></i>
<i class="icon-remove"></i>
EOF
  if scan_files "$jsfixture" "$phpfixture" >/dev/null 2>&1; then
    echo "  OK: sDom domPositioning tokens and icon-* classes were not flagged"
  else
    echo "  FAIL: scan incorrectly flagged sDom or icon-* content" >&2
    status=1
  fi

  echo "== self-test: view-JS string-concatenation negative control =="
  # Own view-JS (application/views/*/js/*.js, public/js/990-vimbadmin.js)
  # builds row-action and dropdown markup as JS string literals rather than
  # static template markup. A Bootstrap 2 component class inside one of those
  # string literals is exactly as live a regression as one in a .phtml file,
  # so it must be caught the same way -- in both single- and double-quoted JS
  # string styles, and inside a `+`-concatenated multi-line build-up. This is
  # the exact shape that a `*.phtml`-only version of this scan missed
  # (VIM-A15.19 round 2).
  local jsdirty="$tmpdir/dirty-list.js"
  cat >"$jsdirty" <<'EOF'
str += '<button class="btn btn-mini have-tooltip" id="repair_' + id + '">\
        <i class="icon-wrench"></i></button>';
str += "<span class=\"btn btn-small\">" + label + "</span>";
elcode += '<ul class="dropdown-menu pull-right">';
row.badge = ( row.active == 1 ) ? '<span class="label label-success">Yes</span>' : '<span class="label">No</span>';
EOF
  echo "== self-test: view-JS negative control, seeded classes must be caught =="
  # 6 seeded hits: btn-mini, btn-small, pull-right, label-success, label
  # (bare, from label label-success), label (bare, from the lone "No" span) =
  # 6.
  local js_expected_hits=6 js_actual_hits
  js_actual_hits=$(scan_files "$jsdirty" | grep -c 'Bootstrap 2 component class' || true)
  if [ "$js_actual_hits" -eq "$js_expected_hits" ]; then
    echo "  OK: all $js_expected_hits seeded view-JS regressions detected, in both quoting styles"
  else
    echo "  FAIL: expected $js_expected_hits hits in $jsdirty, got $js_actual_hits" >&2
    status=1
  fi

  local jsclean="$tmpdir/clean-list.js"
  cat >"$jsclean" <<'EOF'
str += '<button class="btn btn-sm have-tooltip" id="repair_' + id + '">\
        <i class="icon-wrench"></i></button>';
str += "<span class=\"btn btn-sm\">" + label + "</span>";
elcode += '<ul class="dropdown-menu dropdown-menu-end">';
row.badge = ( row.active == 1 ) ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>';
EOF
  if scan_files "$jsclean" >/dev/null 2>&1; then
    echo "  OK: Bootstrap 5 view-JS reported no Bootstrap 2 component classes"
  else
    echo "  FAIL: scan reported a false positive on Bootstrap 5 view-JS" >&2
    status=1
  fi

  return "$status"
}

echo "== own templates must not use Bootstrap 2 component classes =="

if ! self_test; then
  echo "  -> lint self-test failed: the scan itself is broken, refusing to trust its verdict" >&2
  exit 1
fi

shopt -s nullglob globstar
# View-JS builds table-row markup client-side via string concatenation
# (application/views/*/js/*.js) and public/js/990-vimbadmin.js emits the
# shared dropdown-toggle/message markup -- both are as much an "own template"
# as the .phtml files for this scan's purposes, and both have already shipped
# reintroduced Bootstrap 2 component classes that a .phtml-only scan cannot
# see (VIM-A15.19 round 2: btn-mini/pull-right/label survived undetected in
# application/views/*/js/*.js and public/js/990-vimbadmin.js).
files=(application/views/**/*.phtml application/views/**/js/*.js public/js/990-vimbadmin.js)

if [ "${#files[@]}" -eq 0 ]; then
  echo "  -> Own template inventory is empty." >&2
  exit 1
fi

if scan_files "${files[@]}"; then
  echo "  OK: no Bootstrap 2 component class in own templates (${#files[@]} templates scanned)"
  exit 0
else
  exit 1
fi
