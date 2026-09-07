# shellcheck shell=bash
# tests/support/html-opening-tags.sh
#
# Shared HTML opening-tag reader for the migration markup lint gates. Source
# this file, then call `extract_opening_tags FILE` to enumerate the opening
# tags in a file and `tag_attr TAG NAME` to read one attribute out of a tag.
#
# Between them these two cover the whole "that substring is not the thing you
# think it is" defect family that these gates keep re-discovering: the first
# decides where a tag ENDS, the second decides what counts as an attribute
# NAME. Callers should not re-derive either with a regex of their own.
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
#   Prints every opening tag in FILE. This helper applies no POLICY by design
#   -- each caller owns its own "is this the element I care about" test (which
#   class counts, which attribute is required); baking that into a shared
#   parser is how one gate's rule leaks into gates that never wanted it. What
#   is shared is PARSING, not policy: callers select elements by reading
#   attributes with `tag_attr` below, rather than by regex over the tag text.
#
# Attribute VALUES are never interpolated into a regex by either function --
# they only locate structure. Comparing a returned value (a referenced id, say,
# which may contain regex metacharacters) as literal text remains the caller's
# responsibility.
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

# tag_attr TAG NAME
#   Prints the value of attribute NAME in the opening tag TAG, or nothing if
#   the attribute is absent. Exits 0 either way; test the output, not $?.
#
# WHY THIS EXISTS (PR #177 review, rounds 1 and 2): every caller was reading
# attributes with an ad hoc regex over the whole tag text, and both spellings
# that broke are invisible to that design:
#
#   - `\bclass` matches the TAIL of a hyphenated attribute name, because a
#     hyphen is a word boundary -- `data-class="modal"` and `ng-class="modal"`
#     both read as a `class` attribute;
#   - a regex over the raw tag cannot tell an attribute name from the same
#     text appearing INSIDE another attribute's quoted value, so
#     `<div data-example='class="modal"'>` also read as a `class` attribute.
#
# Both are "this text is not actually an attribute name" bugs, the same shape
# as the opening-tag bug above. So the fix is the same: parse the structure
# once, here, instead of hardening each caller's regex against the next
# spelling someone thinks of. This walks the tag attribute by attribute,
# skipping over quoted values rather than matching through them, and compares
# each attribute NAME as literal text.
#
# The value is returned as literal text and is never interpolated into a
# regex by this helper -- callers comparing it must do the same (an id may
# legitimately contain regex metacharacters).
tag_attr() {
  local tag="$1" name="$2"
  ATTR_NAME="$name" perl -0777 -ne '
    my $want = $ENV{ATTR_NAME};
    # Drop "<tagname", then walk attributes. Each iteration consumes either a
    # name=value pair (value quoted or bare) or a standalone/valueless token,
    # so a quoted value is stepped OVER, never scanned into.
    s/^\s*<[a-zA-Z][a-zA-Z0-9]*//;
    while (/\G\s*([^\s=>\/]+)(?:\s*=\s*(?:"([^"]*)"|'"'"'([^'"'"']*)'"'"'|([^\s>]*)))?/gc) {
      my ($n, $v) = ($1, defined $2 ? $2 : defined $3 ? $3 : $4);
      next unless lc($n) eq lc($want);
      print defined $v ? $v : "";
      last;
    }
  ' <<<"$tag"
}
