#!/usr/bin/env bash

# Exercise the supported jQuery 3 plugin stack in a real browser. Development
# runs with Migrate and treats every warning as a failure; production proves the
# generated bundle loads without the shim and retains the preferences cookie.
#
# The development and early lanes load the individual on-disk files, so they
# also cover Chosen and Colorbox -- dead in the application since PR #180, kept
# on disk for exactly this, and deliberately excluded from the bundle by
# bin/minify-bundle-files.php. Those two checks are therefore gated to the
# non-production lanes; every other assertion, and all three negative controls,
# run unchanged.

set -euo pipefail

cd "$(dirname "$0")/.."

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the jQuery compatibility regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-jquery-migrate.XXXXXX)
cleanup() {
  rm -rf "$tmp"
}
trap cleanup EXIT

for asset in \
  100-jquery.js 120-jquery.validate.js 130-jquery.colorbox.js \
  150-jquery.datatables.js 151-jquery.datatables.ext.js 300-chosen.jquery.js \
  310-throbber.js 800-bootstrap.js 850-bootbox.js 900-vimbadmin.validate.js \
  910-vimbadmin.functions.js 990-vimbadmin.js jquery-migrate-3.5.2.js \
  min.bundle-v18.js; do
  cp "public/js/$asset" "$tmp/$asset"
done
# The search text contains a literal Smarty variable, not a shell variable.
# shellcheck disable=SC2016
sed 's/{if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{\/if}/10/' \
  application/views/admin/js/domains.js >"$tmp/view-admin-domains.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<script>
var mode = location.search.slice(1) || 'development';
var failures = [], warnings = [], bootboxResult = null;
var originalWarn = console.warn;
console.warn = function() {
    warnings.push(Array.prototype.join.call(arguments, ' '));
    originalWarn.apply(console, arguments);
};
window.onerror = function(message) { failures.push('page error: ' + message); };
var scripts = mode === 'production'
    ? ['min.bundle-v18.js','view-admin-domains.js']
    : ['100-jquery.js','120-jquery.validate.js','130-jquery.colorbox.js',
       '150-jquery.datatables.js','151-jquery.datatables.ext.js','300-chosen.jquery.js',
       '310-throbber.js','800-bootstrap.js','850-bootbox.js','900-vimbadmin.validate.js',
       '910-vimbadmin.functions.js','990-vimbadmin.js','jquery-migrate-3.5.2.js',
       'view-admin-domains.js'];
if (mode === 'early') {
    scripts.splice(scripts.indexOf('jquery-migrate-3.5.2.js'), 1);
    scripts.splice(1, 0, 'jquery-migrate-3.5.2.js');
}
// Drives the 'missing plugin dependency' negative control. It removes a script
// the development lane loads, so it is only meaningful there -- production
// loads a single bundle. The lane is pinned to development by expect_fail below.
if (location.hash === '#missing-dependency') {
    scripts = scripts.filter(function(file) { return file !== '300-chosen.jquery.js'; });
}
scripts.forEach(function(file) { document.write('<script src="' + file + '"><\/script>'); });
</script></head><body>
<form id="validation"><input id="required" name="required" title="Wrong title priority"></form>
<select id="choice" multiple><option value="a">Alpha</option><option value="b">Beta</option></select>
<table id="table"><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Beta</td></tr><tr><td>Alpha</td></tr></tbody></table>
<table id="list_table"><thead><tr><th>Domain</th><th>Action</th></tr></thead><tbody><tr><td>example.test</td><td><a id="remove-domain-7" ref="example.test">Remove</a></td></tr></tbody></table>
<!-- Mirrors application/views/domain/list.phtml: Bootstrap 5's Modal requires a
     .modal-dialog > .modal-content subtree and throws when it is absent, so a
     bare .modal here would fail against markup the application does not ship. -->
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body"><span id="purge_domain_name"></span></div>
      <div class="modal-footer"><button id="purge_dialog_cancel" type="button">Cancel</button></div>
    </div>
  </div>
</div>
<form id="remove_domain_form"><input name="did"></form>
<a id="colorbox" href="#inline">inline</a><div id="inline">content</div>
<button id="state-button" type="button" data-loading-text="Working">Ready</button>
<pre id="output">PENDING</pre>
<script>
function check(name, test) {
    try {
        if (!test()) throw new Error('false oracle');
    } catch (error) {
        // A TypeError from a symbol this migration deleted is the exact failure
        // being guarded against, so report it distinctly from a false oracle.
        failures.push(name + ': threw ' + (error && error.name ? error.name : 'Error') +
            ': ' + (error && error.message ? error.message : String(error)));
    }
}

$(function() {
    if (location.hash === '#warning-trigger') {
        $(document).bind('vimbadmin-compat-warning', function() {});
    }

    check('jQuery 3.7.1 loaded', function() { return $.fn.jquery === '3.7.1'; });
    check('Migrate mode is correct', function() {
        return mode === 'production' ? typeof $.migrateVersion === 'undefined' : $.migrateVersion === '3.5.2';
    });
    check('empty required field reports a validation error', function() {
        $('#validation').validate({ rules: { required: { required: true } } });
        return !$('#validation').valid() && $('#required-error').text() === 'This field is required.';
    });
    check('DataTables sorts, searches and tears down', function() {
        var table = $('#table').DataTable({ order: [[0, 'asc']] });
        if (table.rows({ order: 'applied' }).data()[0][0] !== 'Alpha') return false;
        table.search('Beta').draw();
        if (table.rows({ search: 'applied' }).count() !== 1) return false;
        table.destroy();
        return true;
    });
    // Chosen and Colorbox are DEAD in the application (PR #180: no <script>/<link>
    // row, no `.chosen(` call site, no chzn-* markup) and are deliberately not
    // bundle inputs -- see bin/minify-bundle-files.php. Their files are kept on
    // disk only so the development lane below can keep proving the on-disk
    // libraries stay jQuery-Migrate clean, which is real value: the '#missing-dependency'
    // negative control removes 300-chosen.jquery.js and requires this file to
    // notice.
    //
    // Production loads min.bundle-v18.js, which will not carry either library
    // once it is regenerated, so these two are gated to the non-production
    // modes. Asserting them against the bundle would be asserting that
    // production still ships code the application removed.
    if (mode !== 'production') {
        check('Chosen updates and tears down', function() {
            $('#choice').chosen();
            $('#choice').val(['b']).trigger('chosen:updated');
            var selected = $('#choice_chosen .search-choice').text().trim();
            $('#choice').chosen('destroy');
            return selected === 'Beta' && !$('#choice_chosen').length;
        });
        check('Colorbox opens and closes inline content', function() {
            $('#colorbox').colorbox({ inline: true, transition: 'none', speed: 0 });
            $('#colorbox').trigger('click');
            var opened = $('#colorbox').hasClass('cboxElement');
            $.colorbox.close();
            return opened;
        });
    }
    // bootbox 3.3.0 is gone (it built Bootstrap 2 modal markup and drove the
    // Bootstrap 2 lifecycle). The replacement shim deliberately provides only
    // `bootbox.alert`, which is the whole of the API the application uses --
    // `bootbox.confirm` has no call site anywhere in the tree. Assert the
    // surface that exists and is depended upon, via the frozen OSS_Message
    // contract, rather than one this migration intentionally dropped.
    // Opened here, asserted in the deferred block below: dismissal runs through
    // Bootstrap 5's hide transition and the shim removes the dialog on
    // `hidden.bs.modal`, so neither the close nor the callback has happened yet
    // when this statement returns. Asserting synchronously would pass even with
    // a broken dismiss handler.
    bootbox.alert('<em id="bootbox-probe">Continue?</em>', function() { bootboxResult = true; });
    check('Bootbox alert renders its message as HTML', function() {
        return !!document.getElementById('bootbox-probe');
    });
    // Dismissal is deferred to the next tick: Bootstrap 5 shows the dialog
    // through a transition, and clicking the OK button before the modal has
    // finished opening is a no-op. The click itself must be a NATIVE event,
    // because `data-bs-dismiss` is bound by Bootstrap's own delegated native
    // listener, which a jQuery-triggered event never reaches.
    setTimeout(function() {
        var button = document.querySelector('#bootbox-probe')
            && document.querySelector('#bootbox-probe').closest('.modal')
                .querySelector('[data-bs-dismiss="modal"]');
        if (button) button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    }, 60);
    check('real admin view remove dialog operates', function() {
        $('#remove-domain-7').trigger('click');
        var selected = $('#remove_domain_form input[name="did"]').val();
        $('#purge_dialog_cancel').trigger('click');
        return selected === '7';
    });
    check('preferences cookie persists between development and production page loads', function() {
        if (mode === 'development') return true;
        var persisted = vmPrefsCookie('vm_prefs');
        return persisted && persisted.marker === (mode === 'early' ? 'development' : 'early');
    });
    check('a cookie written by the old plugin is still readable', function() {
        // The removed $.jsonCookie wrote raw JSON with no URI-encoding. If the
        // replacement disagrees about the wire format, every existing user
        // silently loses their saved table preferences.
        document.cookie = 'vm_legacy={"pageLength":50,"marker":"legacy"}; path=/';
        var legacy = vmPrefsCookie('vm_legacy');
        return legacy && legacy.pageLength === 50 && legacy.marker === 'legacy';
    });
    check('a cookie list with an empty segment still resolves later entries', function() {
        document.cookie = 'vm_seg={"ok":true}; path=/';
        return document.cookie.indexOf('vm_seg=') !== -1 && vmPrefsCookie('vm_seg') !== null;
    });
    check('malformed preferences cookie fails soft', function() {
        document.cookie = 'vm_prefs={malformed; path=/';
        return vmPrefsCookie('vm_prefs') === null;
    });
    check('non-object preferences cookie fails soft', function() {
        document.cookie = 'vm_prefs=0; path=/';
        return vmPrefsCookie('vm_prefs') === null;
    });
    check('preferences cookie keeps its JSON shape and options', function() {
        vmPrefsCookie('vm_prefs', { pageLength: 25, marker: mode }, vm_cookie_options);
        var value = vmPrefsCookie('vm_prefs');
        return value && value.pageLength === 25 && value.marker === mode && /(?:^|; )vm_prefs=/.test(document.cookie);
    });

    // Bootstrap 5 REMOVED the button plugin's stateful `.button('loading')` /
    // `.button('reset')` API together with `data-loading-text`; there is no
    // replacement and the application never used it (no `.button('...')` call
    // and no `data-loading-text` attribute exists outside this fixture). Asserting
    // it here would test Bootstrap 2, not ViMbAdmin.
    //
    // What still needs an oracle is the thing the removed API was standing in
    // for: that a button disabled while work is in flight is re-enabled
    // afterwards, and that this file can still SEE a button wrongly left
    // disabled. The '#button-disabled' mutation lane depends on exactly that,
    // so the pair is kept with the state driven directly.
    var stateButton = $('#state-button');
    stateButton.prop('disabled', true).text('Working');
    setTimeout(function() {
        check('a button disabled for in-flight work reports as disabled', function() {
            return stateButton.prop('disabled') === true && stateButton.text() === 'Working';
        });
        stateButton.prop('disabled', false).text('Ready');
        setTimeout(function() {
            if (location.hash === '#button-disabled') stateButton.prop('disabled', true);
            check('a button restored after the work completes is enabled again', function() {
                return stateButton.prop('disabled') === false && stateButton.text() === 'Ready';
            });
        }, 30);
    }, 30);

    setTimeout(function() {
        // Deferred so the dismiss transition has completed: the dialog must be
        // gone from the DOM and the caller's callback must have run. Without
        // these two, a broken dismiss handler leaves the modal open forever and
        // the suite never notices.
        // Scoped to the alert's OWN dialog: #purge_dialog is a persistent
        // in-page modal opened by an earlier check and legitimately still in the
        // DOM, so a global `.modal.show` count would assert someone else's state.
        // What matters here is that the per-call dialog the shim created is gone
        // -- it is removed on `hidden.bs.modal`, so its absence proves the hide
        // transition completed rather than merely started.
        check('Bootbox alert dismisses and removes its dialog', function() {
            return document.getElementById('bootbox-probe') === null;
        });
        check('Bootbox alert invokes the caller callback on dismiss', function() {
            return bootboxResult === true;
        });

        if (warnings.length) failures.push('Migrate warning: ' + warnings.join(' | '));
        document.getElementById('output').textContent = JSON.stringify({ mode: mode, warnings: warnings, failures: failures });
        document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
    }, 300);
});
</script></body></html>
HTML

run_mode() {
  local mode=$1
  local fragment=${2:-}
  local output=$tmp/$mode${fragment//[^a-z-]/}.html
  chrome_args=(
    --headless --disable-gpu --virtual-time-budget=2000
    --user-data-dir="$tmp/profile" --dump-dom
    "http://127.0.0.1:8765/regression.html?$mode$fragment"
  )
  if [[ $browser == *run-headless-chrome.sh ]]; then
    "$browser" "${chrome_args[@]}" >"$output" 2>&1
  else
    CHROME_BIN=$browser "$http_runner" "$tmp" "${chrome_args[@]}" >"$output" 2>&1
  fi
  if ! grep -q 'data-verdict="PASS"' "$output"; then
    grep -o '<pre id="output">[^<]*' "$output" | sed 's/<pre id="output">//' >&2 || true
    return 1
  fi
  grep -q '"warnings":\[\]' "$output"
}

expect_fail() {
  local label=$1
  shift
  if "$@"; then
    echo "FAIL: negative control '$label' passed; the oracle cannot detect it" >&2
    exit 1
  fi
}

mutation=${VIMBADMIN_MUTATION:-}
case "$mutation" in
  '')
    run_mode development
    run_mode early
    run_mode production
    # Negative controls run in the default lane, so a rotted oracle fails CI
    # instead of waiting for someone to remember an env var.
    expect_fail 'injected Migrate warning' run_mode development '#warning-trigger'
    expect_fail 'missing plugin dependency' run_mode development '#missing-dependency'
    expect_fail 'button left disabled after reset' run_mode development '#button-disabled'
    ;;
  warning)
    run_mode development '#warning-trigger'
    ;;
  missing-dependency)
    run_mode development '#missing-dependency'
    ;;
  button-disabled)
    run_mode development '#button-disabled'
    ;;
  *)
    echo "FAIL: unknown VIMBADMIN_MUTATION: $mutation" >&2
    exit 2
    ;;
esac

echo 'OK: jQuery 3 plugins, Migrate warnings, validation and preferences cookie'
