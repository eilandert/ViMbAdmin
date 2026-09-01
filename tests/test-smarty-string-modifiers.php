<?php

require __DIR__ . '/../library/OSS/Smarty/functions/modifier.substr.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.strstr.php';
require __DIR__ . '/../library/OSS/Smarty/functions/modifier.strpos.php';

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

echo "== Smarty string modifiers ==\n";

$check('substr omitted length', smarty_modifier_substr('abcdef', 0), substr('abcdef', 0));
$check('substr nonzero offset', smarty_modifier_substr('abcdef', 2), substr('abcdef', 2));
$check('substr negative offset', smarty_modifier_substr('abcdef', -2), substr('abcdef', -2));
$check('substr explicit null length', smarty_modifier_substr('abcdef', 1, null), substr('abcdef', 1, null));
$check('substr explicit length', smarty_modifier_substr('abcdef', 1, 3), substr('abcdef', 1, 3));
$check('substr empty string', smarty_modifier_substr('', 0), substr('', 0));

$check('strstr match', smarty_modifier_strstr('abcdef', 'cd'), strstr('abcdef', 'cd'));
$check('strstr not found remains false', smarty_modifier_strstr('abcdef', 'xy'), strstr('abcdef', 'xy'));
$check('strstr empty strings', smarty_modifier_strstr('', ''), strstr('', ''));

$check('strpos zero offset', smarty_modifier_strpos('abcabc', 'a'), strpos('abcabc', 'a'));
$check('strpos nonzero offset', smarty_modifier_strpos('abcabc', 'a', 1), strpos('abcabc', 'a', 1));
$check('strpos negative offset', smarty_modifier_strpos('abcabc', 'a', -3), strpos('abcabc', 'a', -3));
$check('strpos not found remains false', smarty_modifier_strpos('abcabc', 'z'), strpos('abcabc', 'z'));
$check('strpos empty strings', smarty_modifier_strpos('', ''), strpos('', ''));

echo $failures === 0
    ? "OK: all Smarty string modifier assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
