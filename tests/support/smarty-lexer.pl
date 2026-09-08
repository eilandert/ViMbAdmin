# tests/support/smarty-lexer.pl
#
# Shared Smarty-aware value scanner for the migration markup lint gates.
# Loaded with `require` by the Perl one-liners inside those gates; the bash
# entry points live in tests/support/smarty-lexer.sh next to it.
#
# WHY THIS EXISTS (VIM-A15.31 and VIM-A15.42; PR #178 spent four review rounds
# and was closed unmerged): three gates each hand-rolled a Smarty-aware scanner
# as ONE regex with an alternation over "a Smarty {...} span, or a string
# literal, or an ordinary character", and each was defeated by a markup
# spelling its author had not enumerated:
#
#   1. class="{if $m == "wide"}span6{/if}"   -- a Smarty string in the SAME
#      quote as the attribute, which ends the attribute value early;
#   2. overlapping alternation branches, which made an UNTERMINATED value
#      backtrack catastrophically (>20s at 20 spans, vs ~6ms disjoint);
#   3. class="{if $m == '{'}span6{/if}"      -- a brace inside a Smarty string;
#   4. class="{if $n == 'O\'Brien'}span6{/if}" -- an escaped quote;
#   5. class="{* user's note *}span6"        -- a Smarty COMMENT containing an
#      apostrophe. Every regex formulation measured for this one either
#      reintroduced (2) or still timed out at 60 spans.
#
# Each of (1) and (3)-(5) drops the ENTIRE attribute value, so the gate reports
# GREEN on a genuinely reintroduced Bootstrap 2 class -- a false negative in a
# trusted regression guard, which is worse than no guard.
#
# THE FIX IS THE DESIGN, NOT ANOTHER BRANCH. This is a LEXER: a single forward
# pass that consumes the input one construct at a time and never reconsiders a
# decision. Each iteration of the loop advances `pos()` by at least one
# character and never moves it backwards, so the work is O(n) in the input
# length by construction rather than by measurement -- including on
# unterminated input, which is the shape that has no successful parse and
# therefore forces a backtracking matcher to explore its whole search space.
# There is no `.*?`, no nested quantifier and no alternation whose branches can
# match the same text, because there is no single regex at all.
#
# Note this is deliberately NOT built on tests/support/html-opening-tags.sh
# (VIM-A15.35's shared opening-tag reader). That helper reads HTML opening
# tags; these gates read attribute VALUES and also scan JS string literals in
# view-JS files, which are not opening tags. Routing them through an
# opening-tag extractor would silently drop the VIM-A15.19 view-JS coverage.
# Same KIND of fix, one level down.

use strict;
use warnings;

# smarty_skip_construct(\$s, $i)
#
# The single primitive both public functions are built from. Given the input
# string and an offset $i pointing at a character, decide what construct starts
# there and return the offset just PAST it. Recognised constructs:
#
#   {* ... *}   a Smarty comment. Its body is arbitrary text -- an apostrophe,
#               a quote, a brace all mean nothing inside it (case 5 above).
#   { ... }     a Smarty tag span. Quoted strings inside it are skipped
#               recursively, so a brace inside a string (case 3) does not close
#               the span and a quote inside it (case 1) is not the attribute's
#               delimiter.
#   " ... "     a string literal, honouring backslash escapes (case 4).
#   ' ... '     likewise.
#
# Anything else advances exactly one character. An UNTERMINATED construct
# consumes to end of input and returns that -- the scan then simply ends. It
# never backs up to retry a shorter interpretation, which is precisely why
# unterminated input costs the same as terminated input here.
#
# Returns ($next_offset, $kind) where $kind is one of 'comment', 'span',
# 'string', 'char'.
sub smarty_skip_construct {
    my ($sref, $i) = @_;
    my $n = length($$sref);
    return ($n, 'char') if $i >= $n;

    my $c = substr($$sref, $i, 1);

    if ($c eq '{') {
        # A Smarty comment `{* ... *}`: scan for the literal `*}` terminator
        # and nothing else. index() is a forward memchr-style search, so this
        # is linear and cannot backtrack -- which is exactly what every regex
        # formulation of this branch failed to be.
        if (substr($$sref, $i + 1, 1) eq '*') {
            my $end = index($$sref, '*}', $i + 2);
            return ($end < 0 ? $n : $end + 2, 'comment');
        }
        # A Smarty tag span. Walk its body, skipping nested strings so a quote
        # or a brace inside one is inert, until the matching `}`.
        my $j = $i + 1;
        while ($j < $n) {
            my $d = substr($$sref, $j, 1);
            if ($d eq '}') {
                return ($j + 1, 'span');
            }
            if ($d eq '"' || $d eq "'") {
                ($j) = smarty_skip_construct($sref, $j);
                next;
            }
            $j++;
        }
        # Unterminated span: consume the rest and stop.
        return ($n, 'span');
    }

    if ($c eq '"' || $c eq "'") {
        my $j = $i + 1;
        while ($j < $n) {
            my $d = substr($$sref, $j, 1);
            # A backslash escapes the NEXT character whatever it is, so an
            # escaped delimiter does not close the string (case 4).
            if ($d eq "\\") { $j += 2; next; }
            return ($j + 1, 'string') if $d eq $c;
            $j++;
        }
        # Unterminated string: consume the rest and stop.
        return ($n, 'string');
    }

    return ($i + 1, 'char');
}

# smarty_attr_values(\$s, $name)
#
# Extract every value of the HTML attribute $name from $$sref, Smarty-aware.
#
# The attribute NAME is matched with a boundary on its left so `data-class=`
# and `ng-class=` are not read as `class=`, and the value's closing delimiter
# is found by the lexer above rather than by a regex, so a Smarty construct
# inside the value cannot end it early however it is spelled.
#
# View-JS builds markup inside a JS string literal, where the attribute
# delimiter itself appears escaped (`"<span class=\"btn\">"`). An optional
# backslash before the opening delimiter is therefore accepted, and the same
# escaped form is then required to close the value.
#
# Returns a list of values with backslash escapes resolved and whitespace runs
# collapsed to single spaces (a value wrapped across lines becomes one line),
# which is exactly the tokenisation the callers want.
# smarty_scan_attr_value(\$s, $from, $q, $esc)
#
# Walk an attribute value starting at offset $from, whose opening delimiter was
# the quote $q (escaped as `\$q` when $esc is "\\", the view-JS form), and
# return ($value_end, $next_offset). $value_end is -1 when the value never
# closes.
#
# A quote inside a Smarty span, a string or a comment is stepped OVER by
# smarty_skip_construct, so it can never be mistaken for the delimiter -- this
# is what fixes VIM-A15.31 cases 1, 3, 4 and 5 at once, in one place, rather
# than as four alternation branches.
#
# When the attribute was opened with an ESCAPED delimiter (view-JS building
# markup inside a JS string, `"<span class=\\"btn\\">"`), the closing delimiter
# is escaped the same way, so the terminator is the two-character sequence
# `\$q` rather than a bare $q -- and a bare $q is then ordinary value text that
# must NOT end it. Getting that backwards truncates the value.
# smarty_match_attr_header(\$s, $at, $name)
#
# Decide whether the occurrence of $name at offset $at really is an attribute
# NAME introducing a quoted value, and if so return ($value_start, $quote,
# $escape). Returns (-1) otherwise.
#
# The left boundary is what keeps `data-class=` and `ng-class=` from reading as
# `class=`: only start-of-input, whitespace or `<` may precede the name, so `-`
# (and any other name character) rejects it.
sub smarty_match_attr_header {
    my ($sref, $at, $name) = @_;
    my $n = length($$sref);

    if ($at > 0) {
        return (-1) unless substr($$sref, $at - 1, 1) =~ /[\s<]/;
    }

    # `name` must be followed by optional whitespace, `=`, optional whitespace,
    # then an optionally-backslash-escaped quote.
    my $j = $at + length($name);
    $j++ while $j < $n && substr($$sref, $j, 1) =~ /\s/;
    return (-1) unless $j < $n && substr($$sref, $j, 1) eq '=';
    $j++;
    $j++ while $j < $n && substr($$sref, $j, 1) =~ /\s/;

    my $esc = '';
    if ($j < $n && substr($$sref, $j, 1) eq "\\") { $esc = "\\"; $j++; }
    return (-1) unless $j < $n;
    my $q = substr($$sref, $j, 1);
    return (-1) unless $q eq '"' || $q eq "'";
    return ($j + 1, $q, $esc);
}

sub smarty_scan_attr_value {
    my ($sref, $from, $q, $esc) = @_;
    my $n = length($$sref);
    my $j = $from;

    while ($j < $n) {
        my $c = substr($$sref, $j, 1);
        if ($esc eq '') {
            return ($j, $j + 1) if $c eq $q;
        }
        elsif ($c eq "\\" && substr($$sref, $j + 1, 1) eq $q) {
            return ($j, $j + 2);
        }
        if ($c eq '{') {
            ($j) = smarty_skip_construct($sref, $j);
            next;
        }
        if ($c eq "\\") { $j += 2; next; }
        $j++;
    }
    return (-1, $n);
}

sub smarty_attr_values {
    my ($sref, $name) = @_;
    my @out;
    my $n = length($$sref);
    my $i = 0;

    while ($i < $n) {
        # Find the next candidate `name` occurrence. index() is linear; the
        # boundary and `=` checks below are O(1) per candidate, so the whole
        # loop stays linear in the input.
        my $at = index($$sref, $name, $i);
        last if $at < 0;
        $i = $at + 1;

        my ($vstart, $q, $esc) = smarty_match_attr_header($sref, $at, $name);
        next if $vstart < 0;
        my $j = $vstart;

        # Walk the value with the lexer until its closing delimiter.
        my ($vend, $after) = smarty_scan_attr_value($sref, $j, $q, $esc);
        $j = $after;
        # Unterminated attribute value: the walk above already consumed to end
        # of input, so there is nothing left for the outer loop to find.
        # Stopping here (rather than resuming from just after the attribute
        # name) is what keeps the scan LINEAR: resuming would re-walk that same
        # tail once per remaining candidate, which is quadratic -- measurably
        # so, 0.25s at 600 unterminated `{*` spans before this early exit.
        if ($vend < 0) {
            last;
        }

        my $v = substr($$sref, $vstart, $vend - $vstart);
        $v =~ s/\\(.)/$1/gs;
        $v =~ s/\s+/ /g;
        push @out, $v;
        $i = $j;
    }

    return @out;
}

# smarty_js_string_spans(\$s)
#
# Return the [start, end) offsets of every JS string literal in $$sref, using
# the same lexer. Callers use this to answer "is this offset INSIDE a string
# literal?", which is the question tests/lint-template-escaping.sh needs and
# could not ask with a preceding-character regex guard (VIM-A15.42): a quoted
# key that IMMEDIATELY follows a string delimiter, `var s = "'data': {$rows}";`,
# has a quote as its preceding character, and the guard that correctly rejects
# an unmatched `'data:` also rejected this.
#
# Deciding it by CONTEXT rather than by the preceding character keeps both
# behaviours: `mydata:` still fails the identifier boundary, an unmatched
# `'data:` still fails the paired-quote requirement, and a properly paired
# `'data':` inside a string literal is now reachable.
sub smarty_js_string_spans {
    my ($sref) = @_;
    my @spans;
    my $n = length($$sref);
    my $i = 0;
    while ($i < $n) {
        my $c = substr($$sref, $i, 1);
        if ($c eq '"' || $c eq "'") {
            my ($next, $kind) = smarty_skip_construct($sref, $i);
            # A string that runs to end of input is unterminated; record it as
            # a span anyway so the caller's "inside a string" answer is stable,
            # and stop.
            push @spans, [$i, $next];
            $i = $next;
            next;
        }
        if ($c eq '{') {
            ($i) = smarty_skip_construct($sref, $i);
            next;
        }
        $i++;
    }
    return @spans;
}

# smarty_unescaped_js_keys(\$s, $keys_re)
#
# Find every JS object key matching $keys_re whose value is a bare Smarty
# `{$var}` emit -- i.e. a json_encode()/array value going into a JS literal
# without `nofilter` or `escape:'javascript'`.
#
# The key may be bare (`data:`) or quoted with PAIRED quotes (`'data':`,
# `"data":`). It may NOT be an unmatched quote (`'data:`), and it may not be
# the tail of a longer identifier (`mydata:`).
#
# WHY THIS IS NOT A PRECEDING-CHARACTER GUARD (VIM-A15.42): the gate used to
# require the character before the key to be neither an identifier char nor a
# quote. Excluding quotes is what correctly rejects the unmatched-quote case --
# but it also rejects a legitimately PAIRED quoted key that immediately follows
# a string delimiter:
#
#   var s = "'data': {$rows}";
#
# The two are indistinguishable by looking at one character. They are trivially
# distinguishable by CONTEXT, which is what a lexer supplies: the paired-quote
# alternatives carry their OWN delimiters, so they need no left-hand quote
# restriction at all -- only the BARE alternative does. Splitting the guard that
# way keeps `mydata:` rejected (identifier boundary) and the unmatched-quote
# case rejected (its quote never closes), while making the quote-adjacent
# paired case reachable.
#
# Like the rest of this file it is a forward scan with no lazy quantifier and
# no overlapping alternation: `pos()` only ever advances, so an input with no
# match costs one pass, not a search over every split of the input.
#
# Returns a list of 1-based line numbers, one per match.
sub smarty_unescaped_js_keys {
    my ($sref, $keys_re) = @_;
    my @hits;
    my $s = $$sref;
    my $n = length($s);

    # Anchored at each candidate position in turn. `\G` plus an explicit
    # advance is the loop; there is no `.*?` doing the searching.
    # Line number is tracked INCREMENTALLY. Recomputing it as
    # `substr($s, 0, $i) =~ tr/\n//` per hit is O(n) per hit and therefore
    # quadratic overall -- measured at 1.0s on a 336KB input before this, and
    # linear after. Keeping the counter is what makes the whole scan O(n).
    my $i = 0;
    my $seen  = 0;   # offset up to which newlines have been counted
    my $lineno = 1;
    while ($i < $n) {
        pos($s) = $i;
        # Either a paired-quote key or a bare key, immediately followed by the
        # `: {$var}` emit. Both alternatives are anchored, so this either
        # matches AT $i or does not, and we step on.
        if ($s =~ /\G(?:(["'])($keys_re)\1|($keys_re))(\s*:\s*\{\$[A-Za-z_][^}]*\})/gc) {
            my $quoted = defined $1;
            my $emit   = $4;

            # The BARE alternative alone needs a left-hand guard, and it needs
            # BOTH halves of it: an identifier char before the key means this
            # is the tail of a longer name (`mydata:`), and a quote before it
            # means the key was opened as a quoted key whose quote never closed
            # (`'data:`). A PAIRED-quote key is delimited on both sides by its
            # own quotes and is exempt from both -- which is the whole fix.
            if (!$quoted && $i > 0) {
                my $prev = substr($s, $i - 1, 1);
                if ($prev =~ /[A-Za-z0-9_\$"']/) { $i++; next; }
            }

            # An already-escaped emit is not a finding.
            unless ($emit =~ /nofilter|escape:'javascript'/) {
                $lineno += (substr($s, $seen, $i - $seen) =~ tr/\n//);
                $seen = $i;
                push @hits, $lineno;
            }
            $i = pos($s);
            next;
        }
        $i++;
    }
    return @hits;
}

1;
