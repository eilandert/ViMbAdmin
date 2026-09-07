#!/usr/bin/env bash
#
# VIM-A15.26. A DataTables initialiser that keeps the 1.9-era `fnServerData`
# callback MUST also keep `sAjaxSource`, never the 1.10+ `ajax` key.
#
# In DataTables 1.11.5 (public/js/150-jquery.datatables.js, `_fnBuildAjax`)
# the two options are mapped as SEPARATE settings, and the fnServerData branch
# passes `oSettings.sAjaxSource` -- never `oSettings.ajax` -- as the callback's
# first `source` argument:
#
#     if ( oSettings.fnServerData ) {
#         oSettings.fnServerData.call( instance,
#             oSettings.sAjaxSource,   // <-- ajax is NOT consulted here
#             ...
#
# Our `vmDataTableServerData` (public/js/990-vimbadmin.js) forwards that
# argument straight into `$.ajax({ url: source, ... })`. So renaming
# `sAjaxSource` to `ajax` while retaining `fnServerData` makes `source`
# undefined; jQuery then resolves `url: undefined` to the CURRENT PAGE URL.
# Every server-side list silently requests its own list page instead of
# /<controller>/list-data and never loads -- with no JS error and no
# server-side test able to see it. This is the same silent-no-op class as
# VIM-A15.23's sPaginationType break.
#
# The pairing is only safe to remove once the callbacks are migrated to the
# `ajax` FUNCTION interface (VIM-A15.27/.28), at which point this gate should
# be updated rather than deleted.

set -u
fail=0
shopt -s nullglob

echo "== DataTables: fnServerData initialisers must use sAjaxSource, not ajax =="

for f in application/views/*/js/*.js; do
  # Only initialisers that actually retain the 1.9 callback are in scope.
  grep -qE "^[[:space:]]*['\"]fnServerData['\"][[:space:]]*:" "$f" || continue

  if ! grep -qE "^[[:space:]]*['\"]sAjaxSource['\"][[:space:]]*:" "$f"; then
    echo "  $f: has fnServerData but no sAjaxSource"
    echo "    -> the callback's 'source' argument would be undefined;"
    echo "       jQuery resolves url:undefined to the current page URL."
    fail=1
  fi

  if grep -qE "^[[:space:]]*['\"]ajax['\"][[:space:]]*:" "$f"; then
    echo "  $f: uses the 1.10+ 'ajax' key alongside fnServerData"
    echo "    -> fnServerData reads sAjaxSource only; 'ajax' is ignored here."
    fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  echo "  OK: every fnServerData initialiser still supplies sAjaxSource"
fi

exit "$fail"
