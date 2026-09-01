<?php

require __DIR__ . '/../library/OSS/Smarty/functions/modifier.alnum.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.escape.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.intlmobile.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.mobile.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.preg_replace.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.sizeof.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.toValidId.php';

$failures = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '         expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n";
    }
};

$check('alnum retains only ASCII alphanumerics', smarty_modifier_alnum('a-b 1!'), 'ab1');
$check('intlmobile keeps legacy formatting', smarty_modifier_intlmobile('+353 21 123 4567'), '+353 21 123 4567');
$check('mobile keeps legacy formatting', smarty_modifier_mobile('+353 21 123 4567'), '021 123 4567');
$check('preg_replace preserves successful replacement', smarty_modifier_preg_replace('abc', '/b/', 'X'), 'aXc');
$check('toValidId preserves its legacy string result', smarty_modifier_toValidId('a b/c'), 'a b/c');
$check('sizeof counts arrays', smarty_modifier_sizeof(['a', 'b']), 2);
$sizeofRejected = false;
try {
    smarty_modifier_sizeof('not-countable');
} catch (InvalidArgumentException) {
    $sizeofRejected = true;
}
$check('sizeof rejects scalar input', $sizeofRejected, true);

echo $failures === 0
    ? "OK: all OSS legacy modifier assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
