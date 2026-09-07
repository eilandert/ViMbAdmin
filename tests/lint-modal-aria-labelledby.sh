#!/bin/bash
#
# Every statically-authored Bootstrap modal in this repo must be announced by
# assistive technology. Bootstrap 5 does not do this for you: a `.modal` with a
# visible `<h*>` title still announces as an unnamed dialog unless the modal
# element carries `aria-labelledby` pointing at that title's `id`.
#
# That matters most here because the majority of these dialogs are DESTRUCTIVE
# confirmations ("Are you sure?" before a purge/delete/remove). A screen-reader
# user who cannot hear what the dialog is confirming is being asked to approve
# an irreversible action blind.
#
# Scope: statically-authored modals in application/views/**/*.phtml only.
#
# DELIBERATELY EXEMPT -- `#modal_dialog_shell` in footer.phtml and
# _skins/myskin/footer.phtml. Those are empty shells: `#modal_dialog` inside is
# replaced wholesale at runtime by the bootbox helper, so there is no
# statically-authored title element for an `id` to point at, and a hard-coded
# `aria-labelledby` would be a permanently dangling reference (worse than none
# -- assistive tech falls back to nothing either way, but the dangling
# attribute hides the gap from this gate). Labelling those belongs with the
# runtime dialog work, not here.
#
# Exit 0 = every in-scope modal is labelled, 1 = at least one is not.
#
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${BASH_VERSINFO[0]:-0}" -lt 4 ]; then
  echo "  -> bash 4+ required for globstar; refusing to run a partial scan." >&2
  exit 1
fi

# Modal ids whose content is injected at runtime; see the header comment.
runtime_shell_ids='modal_dialog_shell'

# check_file FILE
# For each `<div id="..." class="modal ...">` opening tag in FILE, require an
# aria-labelledby on that same tag AND an element carrying the referenced id.
check_file() {
  local f="$1" fail=0 line id label
  while IFS= read -r line; do
    [ -n "$line" ] || continue
    id=$(sed -n 's/.*<div id="\([^"]*\)"[^>]*class="modal[ "].*/\1/p' <<<"${line#*:}")
    if [ -z "$id" ]; then
      echo "  Modal element without an id in $f: ${line%%:*}" >&2
      fail=1
      continue
    fi
    if grep -qx "$runtime_shell_ids" <<<"$id"; then
      continue
    fi
    label=$(sed -n 's/.*aria-labelledby="\([^"]*\)".*/\1/p' <<<"${line#*:}")
    if [ -z "$label" ]; then
      echo "  Modal #$id has no aria-labelledby in $f:${line%%:*}"
      fail=1
      continue
    fi
    # The referenced id must actually exist in the same template, or assistive
    # technology resolves the reference to nothing and announces no name.
    if ! grep -q "id=\"$label\"" "$f"; then
      echo "  Modal #$id in $f:${line%%:*} points aria-labelledby at \"$label\", which no element in that file defines"
      fail=1
    fi
  done < <(grep -n '<div id="[^"]*"[^>]*class="modal[ "]' "$f" || true)
  return "$fail"
}

scan_files() {
  local files=("$@") f fail=0
  if [ "${#files[@]}" -eq 0 ]; then
    echo "  -> file list is empty." >&2
    return 1
  fi
  for f in "${files[@]}"; do
    check_file "$f" || fail=1
  done
  return "$fail"
}

self_test() {
  local tmpdir status=0
  tmpdir=$(mktemp -d)
  trap 'rm -rf "$tmpdir"' RETURN

  echo "== self-test: an unlabelled modal must be caught =="
  cat >"$tmpdir/unlabelled.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-hidden="true">
    <h3 class="modal-title">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/unlabelled.phtml" >/dev/null 2>&1; then
    echo "  FAIL: unlabelled modal was not caught" >&2
    status=1
  else
    echo "  OK: unlabelled modal caught"
  fi

  echo "== self-test: a DANGLING aria-labelledby must be caught, not accepted =="
  # This is the failure mode a naive `grep -c aria-labelledby` gate misses
  # entirely: the attribute is present, so a presence check passes, but it
  # names an id no element defines and the dialog still announces unnamed.
  cat >"$tmpdir/dangling.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" aria-labelledby="does_not_exist" aria-hidden="true">
    <h3 class="modal-title" id="purge_dialog_label">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/dangling.phtml" >/dev/null 2>&1; then
    echo "  FAIL: dangling aria-labelledby was accepted" >&2
    status=1
  else
    echo "  OK: dangling aria-labelledby caught"
  fi

  echo "== self-test: a correctly labelled modal must not false-positive =="
  cat >"$tmpdir/good.phtml" <<'EOF'
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-labelledby="purge_dialog_label" aria-hidden="true">
    <h3 class="modal-title" id="purge_dialog_label">Are you sure?</h3>
</div>
EOF
  if scan_files "$tmpdir/good.phtml" >/dev/null 2>&1; then
    echo "  OK: correctly labelled modal accepted"
  else
    echo "  FAIL: false positive on a correctly labelled modal" >&2
    status=1
  fi

  echo "== self-test: the runtime shell is exempt by id, and only by id =="
  cat >"$tmpdir/shell.phtml" <<'EOF'
<div id="modal_dialog_shell" class="modal fade" tabindex="-1" aria-hidden="true">
    <div id="modal_dialog" class="modal-content"> </div>
</div>
EOF
  if scan_files "$tmpdir/shell.phtml" >/dev/null 2>&1; then
    echo "  OK: runtime shell exempted"
  else
    echo "  FAIL: runtime shell was flagged despite its exemption" >&2
    status=1
  fi
  # Prove the exemption is the id and not the shape: the same markup under any
  # other id must still be caught, so renaming the shell cannot silently widen
  # the exemption.
  sed 's/modal_dialog_shell/some_other_dialog/' "$tmpdir/shell.phtml" >"$tmpdir/shell-renamed.phtml"
  if scan_files "$tmpdir/shell-renamed.phtml" >/dev/null 2>&1; then
    echo "  FAIL: exemption is shape-based, not id-based; renaming the shell escapes the gate" >&2
    status=1
  else
    echo "  OK: exemption is keyed on the id, not the markup shape"
  fi

  return "$status"
}

echo "== statically-authored Bootstrap modals must carry a resolvable aria-labelledby =="

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
  echo "  OK: every statically-authored modal is labelled (${#files[@]} templates scanned)"
  exit 0
else
  echo "  -> Add aria-labelledby to the modal element and an id to its visible title." >&2
  exit 1
fi
