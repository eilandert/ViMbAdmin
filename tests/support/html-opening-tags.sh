# shellcheck shell=bash
# tests/support/html-opening-tags.sh
#
# Shared opening-tag extractor for the migration markup lint gates. Source
# this file, then call `extract_opening_tags FILE`.
#
# WHY THIS EXISTS (PR #171 needed seven review rounds, six fixes, all the same
# shape): tests/lint-modal-aria-labelledby.sh used to be line-oriented --
# `grep -nP` emits one record per LINE, and `tag="${tag%%>*}>"` keeps only the
# FIRST `>` on that line. Two elements sharing a line collapse into one
# record (the second is never scanned), and an attribute value that
# legitimately contains `>` truncates the tag early. Both are "this substring
# is not actually one opening tag" bugs; a single correct extractor removes
# the whole class of them instead of patching each gate's ad hoc slice
# separately.
#
# This extractor finds each element's opening tag as the substring from its
# `<tagname` up to its first UNQUOTED `>` -- i.e. real HTML tag-close
# semantics, not "the first `>` on this line". It:
#   - spans multiple lines (an opening tag split across lines is read whole);
#   - does not terminate on a `>` that appears inside a single- or
#     double-quoted attribute value;
#   - returns ONE record per element, so two elements on the same line are
#     two independent records, each independently readable;
#   - reports the 1-based line number the tag STARTS on, for diagnostics.
#
# Output: one line per opening tag found, as two fields joined by a tab:
#   "<start-line>\t<tag text, internal newlines collapsed to spaces>"
# Internal newlines are flattened so callers can keep reading tag content
# with ordinary single-line tools (sed/grep -P/bash [[ =~ ]]) exactly as
# before; only the boundaries of what counts as "the tag" changed.
#
# extract_opening_tags FILE
#   Prints every opening tag in FILE. This helper does no class/attribute
#   filtering by design -- each caller already owns its own "is this the
#   element I care about" test (a class-token regex, an attribute-name regex,
#   etc); duplicating that policy into a shared parsing helper is how a
#   caller-specific rule (e.g. the modal gate's word-bounded `.modal` match)
#   would leak into gates that never wanted it. Callers filter the returned
#   tag TEXT with their own existing regex, unchanged.
#
# Attribute VALUES are never interpolated into a regex by this helper -- it
# only locates tag boundaries. Reading a specific attribute back out of the
# returned tag substring, and comparing a referenced id as literal text
# rather than as a regex, remains each caller's responsibility (as it was
# before).
extract_opening_tags() {
  local file="$1"
  perl -0777 -ne '
    # Walk every opening tag: "<" + tag name, then attribute material up to
    # the first UNQUOTED ">". The attribute-material alternation is the
    # standard "either a quoted string (either quote style, no length limit)
    # or a single non-quote non-> character" trick -- it is what lets a `>`
    # inside `alt=">"` or a Smarty `{if $x > 1}` pass through without ending
    # the tag, while a bare `>` still ends it.
    while (/<[a-zA-Z][a-zA-Z0-9]*(?:"[^"]*"|'"'"'[^'"'"']*'"'"'|[^">])*>/gs) {
      my $whole = $&;
      # Line number: count newlines before the match start.
      my $prefix = substr($_, 0, pos($_) - length($whole));
      my $line = 1 + ($prefix =~ tr/\n//);
      $whole =~ s/\n/ /g;
      print "$line\t$whole\n";
    }
  ' "$file"
}
