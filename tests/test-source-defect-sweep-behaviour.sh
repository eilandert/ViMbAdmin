#!/usr/bin/env bash

# VIM-A15.43 / VIM-A15.47: two pre-existing SOURCE defects CodeRabbit surfaced
# while reviewing PR #186's bundle regeneration but that PR was artifact-only
# and could not carry a source fix, so they were ledgered instead. Both are
# behavioural, not stylistic, and a string check on the source cannot prove
# either is fixed -- so this drives the real production functions in a real
# browser, the same pattern tests/test-confirm-guard-behaviour.sh uses for
# VIM-D07.
#
# VIM-A15.43: ossToggle()'s cleanup used `typeof( delElement ) != undefined`.
# `typeof` always yields a STRING, so `"undefined" != undefined` is ALWAYS
# TRUE regardless of whether delElement was passed -- the guard guarded
# nothing. It was harmless only by luck (`$(undefined)` is an empty jQuery
# set and `.hide()` on it is a no-op), so this asserts the callback the guard
# is supposed to gate is never invoked when delElement is absent.
#
# VIM-A15.47: bootbox's alertDialog(), when Bootstrap's Modal constructor is
# unavailable, removed the dialog and ran the callback WITHOUT ever showing
# the message -- so `bootbox.alert('Delete failed, contact support')` told the
# user nothing while the caller believed the alert had been acknowledged. This
# asserts the message text still reaches the user (via window.alert) on that
# fallback path, and that the callback still fires.
#
# VIM-A15.44: addPluginTab() emitted `class="text-error"` for a plugin tab
# whose panel contains an `.error` element. `text-error` is a Bootstrap 2
# class with no Bootstrap 5 styling, so the tab silently lost its error
# indicator; the Bootstrap 5 spelling is `text-danger`. This asserts the
# emitted tab markup carries `text-danger` and never `text-error`.

set -euo pipefail

cd "$(dirname "$0")/.."

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the source-defect-sweep regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-source-defect-sweep.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

cp public/js/100-jquery.js "$tmp/jquery.js"
cp public/js/990-vimbadmin.js "$tmp/990-vimbadmin.js"
cp public/js/850-bootbox.js "$tmp/850-bootbox.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script src="jquery.js"></script>
<script>
// Minimal stand-in for the vendored public/js/310-throbber.js Throbber
// constructor ossToggle() depends on -- only the two methods ossToggle calls
// (.appendTo / .start) are needed, so the real animation library is out of
// scope for this behavioural fixture.
function Throbber() {
    return {
        appendTo: function () { return this; },
        start: function () { return this; }
    };
}
</script>
<script src="990-vimbadmin.js"></script>
<script src="850-bootbox.js"></script>

<button id="toggle-target" class="btn btn-success" data-throb-key="t1"></button>
<div id="throb-toggle-target"></div>
<div id="del-target">to be hidden and removed</div>
<ul id="plugin_tabs" style="display:none"></ul>
<div id="tab_errplug"><span class="error">bad plugin</span></div>

<script>
var results = { toggleRan: false, toggleDelRemoved: null, undefinedCallSeen: false, alertMessage: null, alertCallbackRan: false, pluginTabClass: null };

$(function () {
    var failures = [];

    // -- VIM-A15.43: absent delElement must not enter the cleanup branch at all --
    //
    // The old guard, `typeof( delElement ) != undefined`, is a string-vs-value
    // comparison that is ALWAYS true, so it always called $( delElement ).hide(...)
    // regardless of whether delElement was passed. $(undefined) is an empty
    // jQuery set and .hide() on it silently no-ops, so the DOM end state is
    // identical whether the branch ran or not -- an end-state assertion cannot
    // discriminate the fixed guard from the broken one (this is the "harmless
    // today only by luck" the item names). What DOES discriminate is whether
    // the jQuery constructor `$` was ever invoked with `undefined` from inside
    // that cleanup at all: the fixed guard (`if( delElement )`) never calls
    // $(undefined) when delElement is omitted, the old guard always did.
    var realJQuery = window.$;
    var wrappedJQuery = function (selector) {
        if (selector === undefined) results.undefinedCallSeen = true;
        return realJQuery.apply(this, arguments);
    };
    for (var key in realJQuery) { if (Object.prototype.hasOwnProperty.call(realJQuery, key)) wrappedJQuery[key] = realJQuery[key]; }
    wrappedJQuery.fn = realJQuery.fn;
    wrappedJQuery.prototype = realJQuery.prototype;
    window.$ = wrappedJQuery;
    jQuery = wrappedJQuery;

    var xhr = { open: function () {}, send: function () {} };
    var realAjax = wrappedJQuery.ajax;
    wrappedJQuery.ajax = function (opts) {
        // Synchronously resolve as success, mirroring the shape ossToggle's
        // own success/complete handlers expect, with NO delElement passed to
        // ossToggle at all -- the exact absent-argument path VIM-A15.43 named.
        opts.success('ok');
        opts.complete();
        return xhr;
    };
    try {
        var target = wrappedJQuery('#toggle-target');
        ossToggle(target, '/x', {});
        results.toggleRan = true;
        results.toggleDelRemoved = target.prop('disabled') === false && target.hasClass('btn-danger');
    } catch (e) {
        failures.push('ossToggle with absent delElement threw: ' + e);
    } finally {
        wrappedJQuery.ajax = realAjax;
        window.$ = realJQuery;
        jQuery = realJQuery;
    }

    // -- VIM-A15.47: Modal unavailable must still surface the message --
    var realAlert = window.alert;
    var realModal = window.bootstrap;
    window.alert = function (msg) { results.alertMessage = msg; };
    // Simulate Bootstrap's JS not having loaded: bootbox's alertDialog() looks
    // up the Modal constructor lazily, so removing window.bootstrap makes that
    // lookup fail exactly like an unloaded Bootstrap bundle would.
    window.bootstrap = undefined;
    try {
        bootbox.alert('Delete failed, contact support', function () { results.alertCallbackRan = true; });
    } catch (e) {
        failures.push('bootbox.alert with Modal unavailable threw: ' + e);
    } finally {
        window.alert = realAlert;
        window.bootstrap = realModal;
    }

    // -- VIM-A15.44: the plugin tab error class must be the BS5 spelling --
    addPluginTab('Bad Plugin', 'errplug');
    var tabAnchor = document.querySelector('#plugin_tabs a[href="#tab_errplug"]');
    results.pluginTabClass = tabAnchor ? tabAnchor.className : null;

    if (!results.toggleRan) failures.push('ossToggle did not run with delElement omitted');
    if (results.toggleDelRemoved !== true) failures.push('ossToggle left the toggle button in a bad state with delElement omitted');
    if (results.undefinedCallSeen) failures.push('ossToggle called $(undefined) even though delElement was omitted -- the guard is not gating anything');
    if (results.alertMessage !== 'Delete failed, contact support') {
        failures.push('bootbox.alert did not surface its message via window.alert when Modal was unavailable: got ' + JSON.stringify(results.alertMessage));
    }
    if (results.alertCallbackRan !== true) failures.push('bootbox.alert callback did not run when Modal was unavailable');
    if (results.pluginTabClass === null) {
        failures.push('addPluginTab did not emit a tab anchor for the errored plugin panel');
    } else {
        if (results.pluginTabClass.indexOf('text-error') !== -1) failures.push('addPluginTab still emits the Bootstrap 2 class text-error');
        if (results.pluginTabClass.indexOf('text-danger') === -1) failures.push('addPluginTab did not emit the Bootstrap 5 class text-danger: got ' + results.pluginTabClass);
    }

    document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
    document.body.dataset.testFailures = failures.join('; ');
});
</script>
</body></html>
HTML

rm -rf "$tmp/profile"
"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=1000 \
  --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: source-defect-sweep regression: ${failures:-no browser verdict}" >&2
  exit 1
fi

echo "ok   ossToggle with delElement omitted runs cleanly (VIM-A15.43)"
echo "ok   addPluginTab emits text-danger, not text-error (VIM-A15.44)"
echo "ok   bootbox.alert surfaces its message when Modal is unavailable (VIM-A15.47)"
echo "ALL PASSED"
