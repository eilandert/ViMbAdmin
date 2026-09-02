<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tests/render-residual-stored-xss-fixture.php OUTPUT BUNDLE_URI\n");
    exit(2);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Entities\\')) {
        return;
    }
    $file = __DIR__ . '/../application/Entities/'
        . str_replace('\\', '/', substr($class, strlen('Entities\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

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
if (file_put_contents($stubs . '/header.phtml', '') === false
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

$payload = str_repeat('destination@example.test,', 3)
    . '"<svg/onload=document.body.dataset.pwned=1>"@example.test';
$mailbox = (new \Entities\Mailbox())->setUsername('victim@example.test');
$alias = (new \Entities\Alias())
    ->setAddress('stored-xss-regression@example.test')
    ->setGoto($payload);

$smarty->assign([
    'mailbox' => $mailbox,
    'aliases' => [],
    'inAliases' => [$alias],
]);
$purge = $smarty->fetch('mailbox/purge.phtml');

$smarty->clearAllAssign();
$smarty->assign([
    'session' => new stdClass(),
    'logs' => [[
        'id' => 91,
        'action' => 'ALIAS_EDIT',
        'data' => $payload,
        'admin' => 'administrator@example.test',
        'domain' => 'example.test',
        'timestamp' => new DateTimeImmutable('2026-09-02 03:00:00'),
    ]],
]);
$log = $smarty->fetch('log/list.phtml');

$document = '<!doctype html><meta charset="utf-8"><script src="'
    . htmlspecialchars($bundleUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    . '"></script><body data-pwned="0" data-test-result="pending">'
    . '<section id="mailbox-purge-fixture">' . $purge . '</section>'
    . '<section id="log-fixture">' . $log . '</section>'
    . '<span id="trusted-html-tooltip" class="have-tooltip-long" '
    . 'title="&lt;strong id=&quot;trusted-tooltip-content&quot;&gt;trusted&lt;/strong&gt;">trusted</span>';

if (file_put_contents($output, $document) === false) {
    throw new RuntimeException("could not write {$output}");
}
