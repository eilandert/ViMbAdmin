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
# as its own gate: it is a library, and its behaviour is asserted through this
# gate's self-test below.
# shellcheck source=tests/support/smarty-lexer.sh
source "$script_dir/support/smarty-lexer.sh"

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
# ⚠ STAGE 2 MASKS STAGE 1 DEFECTS, and that is why this gate needed a real
# lexer rather than a seventh regex round. Replacing the Smarty delimiters with
# spaces and tokenising on whitespace RECOVERS the class token even from a value
# stage 1 mangled, as long as the truncation happens to land AFTER the class.
# So a broken extractor still reports the right answer, the gate stays green,
# and the parsing bug is invisible from the gate's verdict. Four prior regex
# rounds each shipped green over a real mis-parse for exactly this reason.
#
# Measured consequence: of the five Smarty spellings in VIM-A15.31, only the
# same-quote forms are live gate-level false negatives; the brace-in-string,
# escaped-quote and Smarty-comment forms are extractor-level only, because this
# tokenisation recovers their class anyway. The one input that defeats it
# end-to-end is TWO same-quote pairs (`{if $m == "a" && $n == "b"}span6{/if}`),
# where the old `.*?` extractor truncated at the FIRST inner quote -- before
# the class -- leaving nothing to recover.
#
# The practical rule this leaves behind: extractor correctness must be asserted
# DIRECTLY, on the extracted value, never inferred from this gate staying green.
# That is what the per-case `grep -qxF` assertions in self_test() below are for.

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
#
# The scanning is delegated to the shared Smarty-aware lexer in
# tests/support/smarty-lexer.sh. It used to be a single regex here, and that
# regex was defeated by five different Smarty spellings across four PR review
# rounds (VIM-A15.31); read the lexer's header for the list and for why a lexer
# rather than a sixth alternation branch.
extract_class_values() {
  smarty_extract_attr_values "$1" class
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
    <div class="{if $mode == "wide" }span6{/if}">a DOUBLE-quoted Smarty string
        inside a DOUBLE-quoted attribute: the inner quote must not end the
        value before span6 (VIM-A15.31 case 1)</div>
    <div class="{if $mode == '{'}span6{/if}">a Smarty string literal whose
        CONTENT is a brace. The span must be delimited by structural braces
        only, or the value is dropped entirely and span6 goes unseen
        (VIM-A15.31 case 3)</div>
    <div class="{if $name == 'O\'Brien'}span6{/if}">a Smarty string literal
        containing an ESCAPED quote. The string branch must consume the escape
        as a unit, or the value is dropped and span6 goes unseen
        (VIM-A15.31 case 4)</div>
    <div class="{* user's note *}span6">a Smarty COMMENT containing an
        apostrophe. Its body is not Smarty syntax, so the apostrophe must not
        open a string; every regex formulation of this branch either dropped
        the value or backtracked catastrophically (VIM-A15.31 case 5)</div>
</div>
    <div class="{if $mode == '}"'}span6{/if}">a Smarty string carrying BOTH a
        closing brace and the attribute's own delimiter. Only skipping strings
        while walking the span body keeps the span from closing at that `}`
        and the value from ending at that `"`, taking span6 with it
        (VIM-A15.31 case 3)</div>
    <div class="{if $m == "a" && $n == "b"}span6{/if}">TWO same-quote Smarty
        string pairs. This is the only measured input that defeats the OLD grid
        gate end-to-end: its `.*?` extractor truncates at the FIRST inner quote,
        to `{if $m == "a`, which lands BEFORE the class -- so the
        delimiter-to-space tokenisation has nothing left to recover and span6 is
        never seen. Both gates reported a genuine Bootstrap 2 class as clean
        (VIM-A15.31 case 1b)</div>
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
  # 15 = one hit per Bootstrap 2 token seeded in the dirty fixture, counting
  # each token separately where a value carries two. Derived:
  #
  #   row-fluid, span6 (double-quoted), span6 (single-quoted),
  #   span4 + offset2 (two in one value), span6 (spaced equals),
  #   span3 (wrapped value), span6 + span12 (the {if}/{else} row)   = 9
  #   plus one each for the six VIM-A15.31 Smarty spellings below
  #   (cases 1, 1b, 3, 3b, 4, 5)                                    = 6
  #                                                                 ---
  #                                                                  15
  #
  # This total is the COARSE check -- "nothing else moved". It is deliberately
  # NOT the control for the individual Smarty cases, and it cannot be: as the
  # stage-2 note at the top of this file explains, the delimiter-to-space
  # tokenisation recovers the class token from a mangled value whenever the
  # truncation lands after the class, so breaking cases 3, 4 or 5 does not
  # reliably move this number at all. It was also measured to stay UNCHANGED
  # when two adjacent fixture rows merged into one value.
  #
  # What pins the individual cases is the per-case `grep -qxF` assertion on the
  # EXTRACTED VALUE, in the block immediately below this one.
  echo "== self-test: negative control, reintroduced Bootstrap 2 grid classes must be caught =="
  expected_hits=15
  actual_hits=$(scan_files "$dirty" | grep -c 'Bootstrap 2 grid class' || true)
  if [ "$actual_hits" -eq "$expected_hits" ]; then
    echo "  OK: all $expected_hits seeded regressions detected, in both quoting styles"
  else
    echo "  FAIL: expected $expected_hits hits in $dirty, got $actual_hits" >&2
    status=1
  fi


  # Per-case assertion for the four VIM-A15.31 Smarty spellings, on the
  # EXTRACTED VALUE rather than on the hit count. The count alone is not a
  # sufficient control here: when one of these cases regresses, the value's
  # closing delimiter is missed and the row MERGES with the next one, which can
  # leave the total unchanged (measured -- disabling the string-escape branch
  # did exactly that in this gate). Requiring each case to come back as its own
  # value, ending where it should, is what makes all four independently
  # load-bearing.
  echo "== self-test: each VIM-A15.31 Smarty spelling must be extracted as its own value =="
  local case_entry case_desc case_value
  # SC2016: the `$mode`/`$name` here are LITERAL Smarty source text in the
  # expected value, not shell expansions -- single quotes are correct.
  # shellcheck disable=SC2016
  local -a a1531_cases=(
    'case 1, same-quote Smarty literal|{if $mode == "wide" }span6{/if}'
    'case 1b, two same-quote Smarty pairs|{if $m == "a" && $n == "b"}span6{/if}'
    "case 3, brace inside a Smarty string|{if \$mode == '{'}span6{/if}"
    "case 3b, string carrying a brace AND the delimiter|{if \$mode == '}\"'}span6{/if}"
    "case 4, escaped quote in a Smarty string|{if \$name == 'O'Brien'}span6{/if}"
    "case 5, Smarty comment containing an apostrophe|{* user's note *}span6"
  )
  for case_entry in "${a1531_cases[@]}"; do
    case_desc="${case_entry%%|*}"
    case_value="${case_entry##*|}"
    # -F: the expected value contains regex metacharacters ({ } $ * . ') and is
    # compared as literal text, never interpolated into a pattern.
    # -x: the whole extracted value must equal this, so a value that swallowed
    # the following fixture row (the merge failure above) does NOT match.
    if extract_class_values "$dirty" | grep -qxF "$case_value"; then
      echo "  OK: $case_desc"
    else
      echo "  FAIL: $case_desc -- value not extracted whole" >&2
      echo "        expected exactly: $case_value" >&2
      status=1
    fi
  done

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
