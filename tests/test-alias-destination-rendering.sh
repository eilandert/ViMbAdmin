#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
    browser="$(command -v chromium || command -v chromium-browser || true)"
fi
if [[ -z "$browser" ]]; then
    echo "FAIL: Chromium is required for the alias destination rendering regression" >&2
    exit 2
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

bundle_uri="file://$(pwd)/public/js/min.bundle-v16.js"
php tests/render-alias-list-fixture.php "$tmp/regression.html" "$bundle_uri"

# Execute the production formatter rather than a test copy. The surrounding
# Smarty template is not JavaScript until rendered, so select its standalone
# formatter and run that function in a real browser DOM.
awk '
    /^function formatGoto\(/ { copying = 1 }
    /^function formatControlls\(/ { copying = 0 }
    copying { print }
' application/views/alias/js/list.js > "$tmp/format-goto.js"

if ! grep -q '^function formatGoto' "$tmp/format-goto.js"; then
    echo "FAIL: could not extract the production formatGoto()" >&2
    exit 2
fi

{
    printf '<script>\n'
    cat "$tmp/format-goto.js"
    cat <<'HTML'

const payload = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
const normalLongDestination = 'first.destination@example.com,second.destination@example.net';

const dynamicRows = document.createElement('table');
dynamicRows.innerHTML = '<tbody>' +
    '<tr id="alias_41"><td id="malicious-destination"></td>' +
        '<td><a id="edit_alias_41" href="/alias/edit/alid/41">Edit</a>' +
        '<a id="delete-alias-41" href="/alias/delete/alid/41">Delete</a></td></tr>' +
    '<tr id="alias_42"><td id="long-destination"></td></tr>' +
    '</tbody>';
document.body.appendChild(dynamicRows);

// This is the production list-data boundary: DataTables parses the JSON row,
// calls formatGoto(), then assigns the returned markup to the destination cell.
const response = JSON.parse(JSON.stringify({
    sEcho: 1,
    iTotalRecords: 2,
    iTotalDisplayRecords: 2,
    aaData: [
        { id: 41, goto: payload },
        { id: 42, goto: normalLongDestination }
    ]
}));

document.getElementById('malicious-destination').innerHTML =
    formatGoto(response.aaData[0].id, response.aaData[0].goto);
document.getElementById('long-destination').innerHTML =
    formatGoto(response.aaData[1].id, response.aaData[1].goto);

$(function () {
    $('#alias-goto-43').trigger('mouseenter');

    setTimeout(function () {
        const failures = [];
        const malicious = document.getElementById('alias-goto-41');
        const normal = document.getElementById('alias-goto-42');
        const serverRendered = document.getElementById('alias-goto-43');

        if (document.body.dataset.pwned !== '0') failures.push('event handler executed');
        if (document.querySelector('svg')) failures.push('SVG element created');
        if (document.querySelector('[onload]')) failures.push('onload attribute created');
        if (!malicious) failures.push('destination element missing');
        if (malicious && malicious.textContent !== payload.slice(0, 50) + '...') {
            failures.push('literal malicious text changed');
        }
        if (malicious && malicious.title !== payload) failures.push('full malicious title changed');
        if (!normal) failures.push('normal destination element missing');
        if (normal && normal.textContent !== normalLongDestination.slice(0, 50) + '...') {
            failures.push('normal truncation changed');
        }
        if (normal && normal.title !== normalLongDestination.replace(/[,]/g, ', ')) {
            failures.push('normal full title unreadable');
        }
        if (!serverRendered) failures.push('server-rendered destination missing');
        if (serverRendered && serverRendered.textContent.trim() !== payload.slice(0, 50) + '...') {
            failures.push('server-rendered literal text changed');
        }
        if (serverRendered && serverRendered.title !== payload) {
            failures.push('server-rendered full title changed');
        }
        if (!document.getElementById('edit_alias_41')) failures.push('dynamic edit action removed');
        if (!document.getElementById('delete-alias-41')) failures.push('dynamic delete action removed');
        if (!document.getElementById('edit_alias_43')) failures.push('server-rendered edit action removed');
        if (!document.getElementById('delete-alias-43')) failures.push('server-rendered delete action removed');

        document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
        document.body.dataset.testFailures = failures.join('; ');
    }, 100);
});
</script>
</body>
HTML
} >> "$tmp/regression.html"

"$browser" \
    --headless \
    --disable-gpu \
    --allow-file-access-from-files \
    --user-data-dir="$tmp/profile" \
    --virtual-time-budget=1000 \
    --dump-dom "file://$tmp/regression.html" > "$tmp/rendered.html" 2> "$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
    failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
    echo "FAIL: production alias destination formatter is unsafe: ${failures:-no browser verdict}" >&2
    exit 1
fi

echo "OK: browser rendered alias destinations as literal text with non-HTML titles"
