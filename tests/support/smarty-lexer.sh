# shellcheck shell=bash
# tests/support/smarty-lexer.sh
#
# Bash entry points for the shared Smarty-aware value scanner. Source this
# file, then call `smarty_extract_attr_values FILE NAME` to enumerate the
# values of an HTML attribute Smarty-aware, or `smarty_unescaped_js_keys FILE
# KEYS` to find unescaped Smarty emits into JS object keys.
#
# The scanning itself lives in tests/support/smarty-lexer.pl next to this file
# -- read the header there for WHY it is a lexer and not a regex. In short:
# three gates each hand-rolled this as one regex with an alternation, and each
# was defeated by a Smarty spelling its author had not enumerated, twice by
# catastrophic backtracking on unterminated input (VIM-A15.31, VIM-A15.42;
# PR #178 closed unmerged after four rounds).
#
# Like tests/support/html-opening-tags.sh, this helper applies no POLICY:
# it decides where a value STARTS and ENDS, never which values matter. Each
# gate keeps its own token comparison.

# `require` resolves a RELATIVE path through @INC, not the cwd, so the path
# must be absolute. These gates also `cd` to the repo root themselves, which
# would break a cwd-relative path a second way.
#
# Failing LOUDLY here is deliberate. `${BASH_SOURCE[0]%/*}` yields the FILENAME
# unchanged when the path contains no slash (sourced as `smarty-lexer.sh` from
# its own directory), the `cd` then fails, and without this guard the path
# would silently become `/smarty-lexer.pl` -- so every gate would die inside a
# Perl `require` quoting a path that was never the real one. This file exists
# because silent wrong answers cost four review rounds; it should not add one.
_smarty_lexer_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)" || _smarty_lexer_dir=""
if [ -z "$_smarty_lexer_dir" ] || [ ! -r "$_smarty_lexer_dir/smarty-lexer.pl" ]; then
  echo "smarty-lexer.sh: cannot locate smarty-lexer.pl next to ${BASH_SOURCE[0]}" >&2
  # `return` works when this file is sourced (the supported use); `exit` is the
  # fallback if someone executes it directly. shellcheck cannot see that the
  # `return` fails in the executed case, so it reads the `exit` as dead.
  # shellcheck disable=SC2317
  return 1 2>/dev/null || exit 1
fi
SMARTY_LEXER_PL="$_smarty_lexer_dir/smarty-lexer.pl"
unset _smarty_lexer_dir

# smarty_extract_attr_values FILE NAME
#   Prints one line per value of attribute NAME found in FILE. A value that
#   wrapped across lines is printed with its whitespace runs collapsed to
#   single spaces. Backslash escapes are resolved.
smarty_extract_attr_values() {
  local file="$1" name="$2"
  SMARTY_ATTR="$name" SMARTY_LEXER_PL="$SMARTY_LEXER_PL" perl -0777 -ne '
    BEGIN { require $ENV{SMARTY_LEXER_PL}; }
    print "$_\n" for smarty_attr_values(\$_, $ENV{SMARTY_ATTR});
  ' "$file"
}

# smarty_unescaped_js_keys FILE KEYS_ALTERNATION
#   Prints one line per unescaped JS key emit found in FILE, as
#   "<line>:<line text>" -- the same shape `grep -n` produced, so callers'
#   diagnostics are unchanged. KEYS_ALTERNATION is a Perl alternation of the
#   key names to guard, e.g. `source|data|aaData`.
smarty_unescaped_js_keys() {
  local file="$1" keys="$2"
  SMARTY_KEYS="$keys" SMARTY_LEXER_PL="$SMARTY_LEXER_PL" perl -0777 -ne '
    BEGIN { require $ENV{SMARTY_LEXER_PL}; }
    my $re = qr/(?:$ENV{SMARTY_KEYS})/;
    my @lines = split /\n/, $_, -1;
    my %seen;
    for my $ln (smarty_unescaped_js_keys(\$_, $re)) {
      next if $seen{$ln}++;
      print "$ln:$lines[$ln - 1]\n";
    }
  ' "$file"
}
