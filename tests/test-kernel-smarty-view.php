<?php
/**
 * Smoke test: the native Smarty view (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * Builds a SmartyView over a temp template dir and proves the plumbing the
 * native controllers rely on: magic-property var assignment, render() of a real
 * template, auto HTML-escaping, and skin resolution (a _skins/<skin>/ copy wins
 * over the default). Uses the real \Smarty\Smarty engine from vendor — the full
 * chrome/OSS-plugin render is validated in the image when the native bootstrap
 * wires this in.
 *
 * Runs in the cache-wiring CI job (vendor present). Exit 0 = pass, non-zero = fail.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;
require __DIR__ . '/../src/Kernel/View/SmartyView.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.tmplinclude.php';

use ViMbAdmin\Kernel\View\SmartyView;

if (!class_exists(\Smarty\Smarty::class)) {
    echo "SKIP Smarty 5 not available\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/vmb-smartyview-' . getmypid();
@mkdir($tmp . '/tpl/_skins/myskin', 0770, true);
@mkdir($tmp . '/compile', 0770, true);
@mkdir($tmp . '/cache', 0770, true);
@mkdir($tmp . '/config', 0770, true);
@mkdir($tmp . '/plugins-one', 0770, true);
@mkdir($tmp . '/plugins-two', 0770, true);
@mkdir($tmp . '/app/views', 0770, true);
@mkdir($tmp . '/app/configs/smarty', 0770, true);
if (!defined('APPLICATION_PATH')) {
    define('APPLICATION_PATH', $tmp . '/app');
}
file_put_contents($tmp . '/tpl/hello.tpl', 'Hello {$name}!');
file_put_contents($tmp . '/tpl/raw.tpl', '{$html}');
file_put_contents($tmp . '/tpl/skinned.tpl', 'DEFAULT {$name}');
file_put_contents($tmp . '/tpl/_skins/myskin/skinned.tpl', 'SKIN {$name}');
file_put_contents($tmp . '/tpl/included.tpl', 'DEFAULT INCLUDE {$name}');
file_put_contents($tmp . '/tpl/_skins/myskin/included.tpl', 'SKIN INCLUDE {$name}');
file_put_contents($tmp . '/tpl/plugin.tpl', '{$name|marker}');
file_put_contents($tmp . '/plugins-two/modifier.marker.php', <<<'PHP'
<?php
function smarty_modifier_marker(string $value): string { return "[{$value}]"; }
PHP);

$failures = 0;
function check(string $label, callable $fn): void {
    global $failures;
    try { $fn(); echo "OK   $label\n"; }
    catch (\Throwable $e) { $failures++; printf("FAIL %s :: %s: %s\n", $label, get_class($e), $e->getMessage()); }
}

$mk = fn() => new SmartyView(['templates' => $tmp . '/tpl', 'compiled' => $tmp . '/compile']);

check('magic __set + render a template', function () use ($mk) {
    $v = $mk();
    $v->__set('name', 'World');
    if (trim($v->render('hello.tpl')) !== 'Hello World!') {
        throw new RuntimeException('got: ' . $v->render('hello.tpl'));
    }
});

check('auto HTML-escape on by default', function () use ($mk) {
    $v = $mk();
    $v->__set('html', '<b>x</b>');
    $out = $v->render('raw.tpl');
    if (str_contains($out, '<b>')) {
        throw new RuntimeException('not escaped: ' . $out);
    }
    if (!str_contains($out, '&lt;b&gt;')) {
        throw new RuntimeException('unexpected escape output: ' . $out);
    }
});

check('compile dir is created when missing', function () use ($tmp) {
    $dir = $tmp . '/compile-fresh';
    @rmdir($dir);
    new SmartyView(['templates' => $tmp . '/tpl', 'compiled' => $dir]);
    if (!is_dir($dir)) {
        throw new RuntimeException('compile dir not created');
    }
});

check('default template used when no skin set', function () use ($mk) {
    $v = $mk();
    $v->__set('name', 'Z');
    if (trim($v->render('skinned.tpl')) !== 'DEFAULT Z') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
});

check('skin override wins when skin set + file present', function () use ($mk) {
    $v = $mk();
    $v->setSkin('myskin');
    if ($v->getSkin() !== 'myskin') {
        throw new RuntimeException('skin not set');
    }
    if ($v->resolveTemplate('skinned.tpl') !== '_skins/myskin/skinned.tpl') {
        throw new RuntimeException('resolve: ' . $v->resolveTemplate('skinned.tpl'));
    }
    $v->__set('name', 'Z');
    if (trim($v->render('skinned.tpl')) !== 'SKIN Z') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
});

check('skin fallback and tmplinclude compatibility', function () use ($mk, $tmp) {
    $v = $mk();
    $v->setSkin('myskin');
    // hello.tpl has no _skins/myskin copy -> default resolves.
    if ($v->resolveTemplate('hello.tpl') !== 'hello.tpl') {
        throw new RuntimeException('resolve: ' . $v->resolveTemplate('hello.tpl'));
    }

    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir($tmp . '/tpl');
    $smarty->setCompileDir($tmp . '/compile');
    $smarty->assign('___SKIN', 'myskin');
    $smarty->assign('name', 'original');

    $out = smarty_function_tmplinclude(['file' => "'included.tpl'", 'name' => 'temporary'], $smarty);
    if (trim($out) !== 'SKIN INCLUDE temporary') {
        throw new RuntimeException('got: ' . $out);
    }
    if ($smarty->getTemplateVars('name') !== 'temporary') {
        throw new RuntimeException('plugin parameter was not retained');
    }

    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir($tmp . '/tpl');
    $smarty->setCompileDir($tmp . '/compile');
    $smarty->assign('name', 'template');
    $template = $smarty->createTemplate('hello.tpl');

    $out = smarty_function_tmplinclude(['file' => '"included.tpl"', 'assign' => 'included'], $template);
    if ($out !== '') {
        throw new RuntimeException('assigned include unexpectedly returned output');
    }
    if (trim((string) $template->getTemplateVars('included')) !== 'DEFAULT INCLUDE template') {
        throw new RuntimeException('assigned output missing from template caller');
    }

    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir($tmp . '/tpl');
    $smarty->setCompileDir($tmp . '/compile');
    $smarty->assign('name', 'variable');
    $smarty->assign('include_name', 'included.tpl');

    $forms = [
        "\$_smarty_tpl->tpl_vars['include_name']",
        '($_smarty_tpl->tpl_vars[include_name])',
        '$_smarty_tpl->tpl_vars[include_name]',
    ];
    foreach ($forms as $form) {
        $out = smarty_function_tmplinclude(['file' => $form], $smarty);
        if (trim($out) !== 'DEFAULT INCLUDE variable') {
            throw new RuntimeException($form . ' resolved to: ' . $out);
        }
    }

    $smarty = new \Smarty\Smarty();
    $smarty->setTemplateDir($tmp . '/tpl');
    $smarty->setCompileDir($tmp . '/compile');

    $malformedRejected = false;
    try {
        smarty_function_tmplinclude(['file' => '$_smarty_tpl->tpl_vars[unterminated'], $smarty);
    } catch (\Smarty\Exception $e) {
        if ($e->getMessage() === 'Source: Missing  name') {
            $malformedRejected = true;
        } else {
            throw new RuntimeException('unexpected Smarty error: ' . $e->getMessage());
        }
    }
    if (!$malformedRejected) {
        throw new RuntimeException('expected missing-source Smarty error');
    }
});

check('unknown skin throws', function () use ($mk) {
    try {
        $mk()->setSkin('does-not-exist');
    } catch (\RuntimeException) {
        return;
    }
    throw new RuntimeException('expected throw for unknown skin');
});

check('fromOptions preserves valid, missing, and malformed options', function () use ($tmp) {
    $v = SmartyView::fromOptions(['resources' => ['smarty' => [
        'templates' => $tmp . '/tpl',
        'compiled'  => $tmp . '/compile',
        'cache'     => $tmp . '/cache',
        'config'    => $tmp . '/config',
        'plugins'   => [$tmp . '/plugins-one', $tmp . '/plugins-two'],
        'skin'      => 'myskin',
    ]]]);
    $engine = $v->getEngine();
    if (!in_array($tmp . '/tpl/', (array) $engine->getTemplateDir(), true)
        || rtrim($engine->getCompileDir(), '/') !== $tmp . '/compile'
        || rtrim($engine->getCacheDir(), '/') !== $tmp . '/cache'
        || !in_array($tmp . '/config/', $engine->getConfigDir(), true)) {
        throw new RuntimeException('explicit Smarty directories were not retained');
    }
    $v->__set('name', 'Q');
    if (trim($v->render('skinned.tpl')) !== 'SKIN Q') {
        throw new RuntimeException('got: ' . $v->render('skinned.tpl'));
    }
    if (trim($v->render('plugin.tpl')) !== '[Q]') {
        throw new RuntimeException('plugin directory list was not retained');
    }

    $engine = SmartyView::fromOptions([])->getEngine();
    if (!in_array($tmp . '/app/views/', (array) $engine->getTemplateDir(), true)
        || rtrim($engine->getCompileDir(), '/') !== $tmp . '/var/templates_c'
        || rtrim($engine->getCacheDir(), '/') !== $tmp . '/var/cache'
        || !in_array($tmp . '/app/configs/smarty/', $engine->getConfigDir(), true)) {
        throw new RuntimeException('default Smarty directories were not retained');
    }

    foreach ([
        ['resources' => new stdClass()],
        ['resources' => ['smarty' => new stdClass()]],
    ] as $options) {
        $engine = SmartyView::fromOptions($options)->getEngine();
        if (!in_array($tmp . '/app/views/', (array) $engine->getTemplateDir(), true)) {
            throw new RuntimeException('malformed options did not retain the default template directory');
        }
    }
});

// cleanup
array_map('unlink', glob($tmp . '/tpl/*.tpl') ?: []);
array_map('unlink', glob($tmp . '/tpl/_skins/myskin/*.tpl') ?: []);

echo 'PHP ' . PHP_VERSION . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
