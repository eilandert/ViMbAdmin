<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tests/render-alias-list-fixture.php OUTPUT BUNDLE_URI\n");
    exit(2);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

// This focused fixture does not exercise translations. The pinned PHP 8.4
// browser-support image intentionally omits gettext, so provide the identity
// behavior that the rendered template needs without masking production gates.
if (!function_exists('_')) {
    function _(string $message): string
    {
        return $message;
    }
}

$output = $argv[1];
$bundleUri = $argv[2];
$tmp = dirname($output);
$stubs = $tmp . '/template-stubs';
$compile = $tmp . '/templates-c';

foreach ([$stubs, $compile] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create {$dir}");
    }
}

$header = '<!doctype html><meta charset="utf-8"><script src="'
    . htmlspecialchars($bundleUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '"></script><body data-pwned="0" data-test-result="pending">';
if (file_put_contents($stubs . '/header.phtml', $header) === false
    || file_put_contents($stubs . '/footer.phtml', '') === false) {
    throw new RuntimeException('could not write template stubs');
}

$smarty = new \Smarty\Smarty();
$smarty->setEscapeHtml(true);
$smarty->setTemplateDir([$stubs, __DIR__ . '/../application/views']);
$smarty->setCompileDir($compile);
@$smarty->addPluginsDir([
    __DIR__ . '/../library/ViMbAdmin/Smarty/functions',
    __DIR__ . '/../library/OSS/Smarty/functions',
]);
foreach (['strlen', 'count', 'in_array', 'is_array'] as $modifier) {
    $smarty->registerPlugin('modifier', $modifier, $modifier, true);
}
$storedDestination = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
$smarty->assign([
    'session' => new stdClass(),
    'ima' => 0,
    'options' => [
        'defaults' => [
            'server_side' => ['pagination' => ['enable' => false]],
            'table' => ['entries' => 10],
        ],
    ],
    'aliases' => [[
        'id' => 43,
        'address' => 'stored-xss-regression@example.com',
        'domain' => 'example.com',
        'active' => true,
        'goto' => $storedDestination,
    ]],
    'csrfToken' => 'test-csrf-token',
]);

$rendered = $smarty->fetch('alias/list.phtml');
if (file_put_contents($output, $rendered) === false) {
    throw new RuntimeException("could not write {$output}");
}

$smarty->assign('options', [
    'defaults' => [
        'server_side' => ['pagination' => ['enable' => true]],
        'table' => ['entries' => 10],
    ],
]);
$serverSideScript = $smarty->fetch('alias/js/list.js');
if (file_put_contents($output . '.server-side.js', $serverSideScript) === false) {
    throw new RuntimeException("could not write {$output}.server-side.js");
}
