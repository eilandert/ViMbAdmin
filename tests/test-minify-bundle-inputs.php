<?php

/**
 * The bundle's input list is repo-owned and excludes the dead assets.
 *
 * bin/minify-options.php used to hand vendor/opensolutions/minify/minify.php a
 * glob, and the vendor script expanded it only after require_once()ing the
 * config, so nothing could keep a file on disk without shipping it. Chosen and
 * Colorbox were removed from the application in PR #180 but stayed on disk for
 * tests/test-jquery-migrate-compat.sh's development lane, so every regeneration
 * both re-shipped them and rewrote the header .phtml files from the glob,
 * reverting PR #180.
 *
 * This test pins the replacement: bin/minify-bundle-files.php enumerates the
 * inputs, bin/minify-bundle.php resolves them, and the four dead assets are
 * absent while every live asset is present and correctly ordered.
 *
 * It runs without Java or clean-css, which is why it asserts against
 * vimbadminResolveBundleInputs() and `--print-inputs` rather than against a
 * generated bundle. bin/minify-options.php exit(1)s when clean-css is missing,
 * so it is deliberately never loaded here.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;

$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== minify bundle inputs ==\n";

require_once $root . '/bin/minify-bundle.php';

$check(
    'the driver exposes a resolver that can be loaded without a build toolchain',
    function_exists('vimbadminResolveBundleInputs')
);

/** @var array{js: list<string>, css: list<string>, jsExcluded: list<string>, cssExcluded: list<string>} $lists */
$lists = require $root . '/bin/minify-bundle-files.php';

foreach (['js', 'css', 'jsExcluded', 'cssExcluded'] as $key) {
    $check("the file list declares '{$key}'", isset($lists[$key]) && is_array($lists[$key]));
}

$js = vimbadminResolveBundleInputs(
    $root . '/public/js',
    '[0-9][0-9][0-9]-*.js',
    $lists['js'],
    $lists['jsExcluded']
);
$css = vimbadminResolveBundleInputs(
    $root . '/public/css',
    '[0-9][0-9][0-9]-*.css',
    $lists['css'],
    $lists['cssExcluded']
);

$jsNames = array_map('basename', $js);
$cssNames = array_map('basename', $css);

// The point of the whole change: these four match the retired glob, are still
// on disk, and must never reach a bundle again.
foreach (['130-jquery.colorbox.js', '300-chosen.jquery.js'] as $dead) {
    $check("dead JS asset is excluded from the bundle: {$dead}", !in_array($dead, $jsNames, true));
    $check(
        "dead JS asset is still on disk for the development compat lane: {$dead}",
        is_file($root . '/public/js/' . $dead)
    );
    $check(
        "dead JS asset is explicitly ledgered as excluded: {$dead}",
        in_array($dead, $lists['jsExcluded'], true)
    );
}

foreach (['130-colorbox.css', '300-chosen.css'] as $dead) {
    $check("dead CSS asset is excluded from the bundle: {$dead}", !in_array($dead, $cssNames, true));
    $check(
        "dead CSS asset is still on disk: {$dead}",
        is_file($root . '/public/css/' . $dead)
    );
    $check(
        "dead CSS asset is explicitly ledgered as excluded: {$dead}",
        in_array($dead, $lists['cssExcluded'], true)
    );
}

// The live assets, enumerated from the real tree, in bundle concatenation
// order. An exact comparison rather than a subset check: a bundle that gained
// an unreviewed file is as much a defect as one that lost a library.
$expectedJs = [
    '100-jquery.js',
    '120-jquery.validate.js',
    '150-jquery.datatables.js',
    '151-jquery.datatables.ext.js',
    '152-jquery.datatables.bootstrap5.js',
    '310-throbber.js',
    '800-bootstrap.js',
    '850-bootbox.js',
    '900-vimbadmin.validate.js',
    '910-vimbadmin.functions.js',
    '990-vimbadmin.js',
];
$expectedCss = [
    '800-bootstrap.css',
    '815-bootstrap-icons.css',
    '816-datatables-bootstrap5.css',
    '890-override_container_app.css',
    '895-bootstrap-override.css',
    '920-style.css',
    '930-popup.css',
];

$check('the JS bundle inputs are exactly the live assets, in order', $jsNames === $expectedJs);
if ($jsNames !== $expectedJs) {
    echo '       got: ' . implode(', ', $jsNames) . "\n";
}
$check('the CSS bundle inputs are exactly the live assets, in order', $cssNames === $expectedCss);
if ($cssNames !== $expectedCss) {
    echo '       got: ' . implode(', ', $cssNames) . "\n";
}

foreach ($expectedJs as $live) {
    $check("live JS asset resolves to a real file: {$live}", is_file($root . '/public/js/' . $live));
}
foreach ($expectedCss as $live) {
    $check("live CSS asset resolves to a real file: {$live}", is_file($root . '/public/css/' . $live));
}

// jQuery Migrate must never be bundled: it has no NNN- prefix so the glob never
// saw it, and $mini_js_conditional_end appends its development-only row.
$check(
    'jQuery Migrate is not a bundle input',
    !in_array('jquery-migrate-3.5.2.js', $jsNames, true)
);

// The header {else} branches are what the driver regenerates from these lists,
// so they must already agree -- otherwise the first regeneration silently
// changes what development loads.
$headerJs = (string) file_get_contents($root . '/application/views/header-js.phtml');
$headerCss = (string) file_get_contents($root . '/application/views/header-css.phtml');

foreach ($expectedJs as $live) {
    $check("header-js.phtml lists the bundled asset: {$live}", str_contains($headerJs, '/js/' . $live . '"'));
}
foreach (['130-jquery.colorbox.js', '300-chosen.jquery.js'] as $dead) {
    $check("header-js.phtml does not list the dead asset: {$dead}", !str_contains($headerJs, $dead));
}
foreach ($expectedCss as $live) {
    $check("header-css.phtml lists the bundled asset: {$live}", str_contains($headerCss, '/css/' . $live . '"'));
}
foreach (['130-colorbox.css', '300-chosen.css'] as $dead) {
    $check("header-css.phtml does not list the dead asset: {$dead}", !str_contains($headerCss, $dead));
}

// Reconciliation guards. An asset that is in neither list is unreviewed, and
// bundling or skipping it silently are both wrong.
$rejects = static function (callable $call): bool {
    try {
        $call();
    } catch (RuntimeException) {
        return true;
    }

    return false;
};

$check(
    'an asset in neither list is rejected rather than silently skipped',
    $rejects(static fn () => vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        $lists['js'],
        [] // Chosen and Colorbox now unaccounted for.
    ))
);
$check(
    'a listed input that is missing from disk is rejected',
    $rejects(static fn () => vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        array_merge($lists['js'], ['999-not-on-disk.js']),
        $lists['jsExcluded']
    ))
);
$check(
    'a file that is both bundled and excluded is rejected',
    $rejects(static fn () => vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        array_merge($lists['js'], ['300-chosen.jquery.js']),
        $lists['jsExcluded']
    ))
);
$check(
    'a stale exclusion for a file no longer on disk is rejected',
    $rejects(static fn () => vimbadminResolveBundleInputs(
        $root . '/public/js',
        '[0-9][0-9][0-9]-*.js',
        $lists['js'],
        array_merge($lists['jsExcluded'], ['777-deleted-long-ago.js'])
    ))
);

// The driver's own --print-inputs path, which is what a human runs, must agree
// with the resolver the assertions above used.
$printed = [];
$status = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/minify-bundle.php') . ' --print-inputs 2>&1',
    $printed,
    $status
);
$check('--print-inputs succeeds without a build toolchain', $status === 0);
$expectedPrinted = array_merge(
    array_map(static fn (string $n): string => 'js  ' . $n, $expectedJs),
    array_map(static fn (string $n): string => 'css ' . $n, $expectedCss)
);
$check('--print-inputs reports the same list the resolver returns', $printed === $expectedPrinted);
if ($printed !== $expectedPrinted) {
    echo '       got: ' . implode(' | ', $printed) . "\n";
}

// The retired vendor invocation must not be advertised anywhere: running it
// would reintroduce the glob and revert PR #180 again.
foreach (['bin/minify-options.php', 'bin/minify-bundle.php'] as $documented) {
    $source = (string) file_get_contents($root . '/' . $documented);
    $check(
        "{$documented} does not advertise a runnable vendor minify.php invocation",
        preg_match('/^[^\n*]*\bphp\s+\S*vendor\S*minify\.php\b/m', $source) !== 1
    );
}
$optionsSource = (string) file_get_contents($root . '/bin/minify-options.php');
$check(
    'bin/minify-options.php points at the repo-owned driver',
    str_contains($optionsSource, 'bin/minify-bundle.php')
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
