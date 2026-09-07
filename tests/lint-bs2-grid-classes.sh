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
#   - DataTables `sDom`/`dom` strings in application/views/*/js/*.js. Those
#     encode `span6` as a DataTables domPositioning TOKEN, not a CSS class;
#     renaming them here would break the table layout API rather than fix
#     anything. VIM-A15.23 removed the BS2 `sDom` overrides and covers that
#     token class with tests/lint-datatables-bs5-dom.sh instead.
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

# The scan is deliberately two-stage rather than one clever regex, because the
# one-regex versions kept trading a false positive for a missed regression:
#
#   1. `extract_class_values` pulls out every `class` attribute VALUE, whole,
#      including values that wrap across lines and values containing a Smarty
#      expression with its own inner quotes. It reads each file as one record
#      so a wrapped attribute is a single value.
#   2. The value is then split on whitespace and each token compared against
#      the Bootstrap 2 spellings exactly.
#
# Stage 2 being an exact token comparison is what makes `custom-span6`,
# `span6-label`, `myoffset2`, `span60` and `col-md-60` non-matches without any
# boundary regex to get wrong -- they are simply different tokens. And stage 1
# owning the quote handling is what lets a Smarty expression carry inner quotes
# (`class="{if $m == 'wide'}span6{/if}"`) without ending the value early.
#
# `data-class=` and `ng-class=` are excluded by the attribute-name boundary in
# stage 1: neither applies a CSS class to the element.

# Bootstrap 2 defines exactly .span1-.span12 and .offset1-.offset12; there was
# never a .span60. Bounding the number keeps an unrelated own class such as
# `span60` from being reported as a Bootstrap 2 leftover.
bs2_num='(1[0-2]|[1-9])'
bs2_tokens_re="^(row-fluid|span${bs2_num}|offset${bs2_num})$"

# extract_class_values FILE
# Prints one line per class attribute value found, as `<value>`. A value that
# wrapped across lines is printed with its newlines collapsed to spaces, which
# is exactly the tokenisation stage 2 wants anyway.
extract_class_values() {
  perl -0777 -ne '
    # `\{[^{}]*\}` FIRST, so a Smarty span is consumed whole before the
    # non-greedy `.` branch can stop at a quote inside it. Without it,
    # `class="{if $mode == "wide"}span6{/if}"` ends the value at the inner
    # `"` and `span6` is never tokenised -- the gate reports green on a real
    # BS2 grid class (VIM-A15.31). The DIFFERENT-quote spelling
    # (a Smarty condition comparing against a SINGLE-quoted literal)
    # already worked, because the
    # closing-quote test is `\1`, the delimiter that actually opened the
    # attribute; only the SAME-quote spelling was affected.
    #
    # `[^{}]` inside the span, not `.`, keeps the branch non-overlapping and
    # linear -- no backtracking blowup. It means one level of braces, which
    # is all Smarty needs here: a nested `{...}` appears in a Smarty MODIFIER
    # argument, never in a condition wrapping a class list, and the outer
    # span would simply end early -- degrading to current behaviour, never
    # worse than it.
    while (/(?:^|[\s])class\s*=\s*(["\x27])((?:\{[^{}]*\}|(?!\1)[^{])*)\1(?=[\s>\/])/gs) {
      my $v = $2;
      $v =~ s/\s+/ /g;
      print "$v\n";
    }
  ' "$1"
}

# scan_files: extract every class attribute value from the given files and
# compare each of its tokens against the Bootstrap 2 grid spellings.
# Prints each hit and returns 1 if any token matched, 0 if clean.
scan_files() {
  local files=("$@")
  local f value token smarty_split fail=0
  local -a tokens

  # The main scan runs under `nullglob` (it is what makes an empty template
  # glob detectable). Word-splitting the attribute value would therefore also
  # PATHNAME-expand each token, and under nullglob a token matching no file is
  # deleted outright -- which silently drops almost every class from the scan.
  # Split explicitly with globbing off instead.
  set -f

  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi

  for f in "${files[@]}"; do
    while IFS= read -r value; do
      # Smarty emits classes from inside `{if}`/`{/if}` with no surrounding
      # whitespace (`class="{if $wide}span6{/if}"`), so `span6` is not a
      # whitespace-delimited token there. Replace the Smarty delimiters with
      # spaces before tokenising; the expression's own contents then tokenise
      # harmlessly alongside the class it emits.
      # Two plain substitutions rather than one `[{}]` character class: inside
      # `${...//...}` the `}` of the class is read as the end of the expansion,
      # which silently mangles the value instead of substituting.
      smarty_split="${value//\{/ }"
      smarty_split="${smarty_split//\}/ }"
      read -r -a tokens <<<"$smarty_split"
      for token in "${tokens[@]}"; do
        if [[ "$token" =~ $bs2_tokens_re ]]; then
          echo "  Bootstrap 2 grid class '$token' in $f: class=\"$value\""
          fail=1
        fi
      done
    done < <(extract_class_values "$f")
  done

  set +f
  return "$fail"
}

# self_test: prove the scan actually fires on reintroduced Bootstrap 2 grid
# classes (negative control) and stays clean on Bootstrap 5 markup (positive
# control). Runs on every invocation so a mis-escaped pattern that degraded to
# always-pass cannot slip past CI unnoticed.
self_test() {
  local tmpdir dirty clean status=0 expected_hits actual_hits

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
    <div class="{if $wide}span6{else}span12{/if}">Smarty expression inside the
        attribute value: its inner quotes must not end the scan early</div>
    <div class="{if $mode == "wide" && $enabled}span6{/if}">a DOUBLE-quoted
        Smarty string inside a DOUBLE-quoted attribute: the inner quote must
        not end the value before span6 (VIM-A15.31)</div>
</div>
EOF

  cat >"$clean" <<'EOF'
<div class="row">
    <div class="col-md-6">left</div>
    <div class='col-md-6'>right, single-quoted</div>
    <div class="col-md-4 offset-md-2">offset</div>
</div>
<p>The span of this deployment and its offset10 schedule are prose, not classes.</p>
<div data-class="span6">a data-* attribute is not the class attribute</div>
<div ng-class="span6">nor is ng-class</div>
<div class="span6-label offset2-wrapper">own class names that merely start with
    a Bootstrap 2 spelling are not Bootstrap 2 classes</div>
<div class="col-md-60">col-md-60 is not col-md-6</div>
<div class="custom-span6 myoffset2">own class names that merely END with a
    Bootstrap 2 spelling are not Bootstrap 2 classes</div>
<div class="span60 offset99">Bootstrap 2 defined only span1-span12 and
    offset1-offset12; these numbers were never Bootstrap 2 classes</div>
EOF

  # The count is asserted, not merely "nonzero". `if ! scan_files` succeeds on
  # any failure at all, so if a future edit broke every form except `row-fluid`
  # this control would still print OK: it would prove the scan is not DEAD
  # without proving it is COMPLETE. Pinning the number turns a partial
  # degradation into a visible mismatch.
  #
  # 10 = one hit per Bootstrap 2 token seeded in the dirty fixture, counting
  # each token separately where a value carries two (`span4 offset2`). The
  # tenth is the same-quote Smarty row (VIM-A15.31): before that fix the value
  # ended at the inner `"` and its span6 was never tokenised, so the count
  # dropping back to 9 is exactly what a regression there looks like.
  echo "== self-test: negative control, reintroduced Bootstrap 2 grid classes must be caught =="
  expected_hits=10
  actual_hits=$(scan_files "$dirty" | grep -c 'Bootstrap 2 grid class' || true)
  if [ "$actual_hits" -eq "$expected_hits" ]; then
    echo "  OK: all $expected_hits seeded regressions detected, in both quoting styles"
  else
    echo "  FAIL: expected $expected_hits hits in $dirty, got $actual_hits" >&2
    status=1
  fi

  # The Smarty-span branch and the ordinary-character branch must stay
  # DISJOINT. An earlier revision of this fix wrote the ordinary branch as a
  # bare `.`, which can also match every character `\{[^{}]*\}` matches --
  # so an UNTERMINATED class attribute containing many Smarty spans made the
  # engine reconsider each span as individual characters and the match time
  # exploded. Measured on this exact input at 20 spans: the overlapping form
  # did not finish in 20 SECONDS; the disjoint form returns in ~6ms.
  #
  # A hang is invisible to every other assertion here -- they all wait for the
  # scan -- and in CI it reads as a stuck job, not a lint failure. So bound it
  # explicitly. The input is deliberately unterminated (no closing quote):
  # that is the shape that has no successful parse and therefore forces the
  # engine through the whole search space.
  echo "== self-test: pathological Smarty input must not blow up the extractor =="
  local pathological
  pathological="$tmpdir/pathological.phtml"
  {
    printf '<div class="'
    # shellcheck disable=SC2016  # `$a` is literal Smarty source, not a shell var
    for _ in $(seq 1 20); do printf '{if $a}x{/if}'; done
    printf '\n'
  } >"$pathological"
  if timeout 10 bash -c "$(declare -f extract_class_values); extract_class_values '$pathological'" >/dev/null 2>&1; then
    echo "  OK: unterminated attribute with 20 Smarty spans extracted without blowing up"
  else
    echo "  FAIL: extractor did not finish in 10s on 20 Smarty spans -- the" >&2
    echo "        alternation branches have become overlapping again." >&2
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
