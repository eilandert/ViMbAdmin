<?php

require __DIR__ . '/../library/ViMbAdmin/Smarty/functions/modifier.flipflop.php';
require __DIR__ . '/../library/ViMbAdmin/Smarty/functions/modifier.int.php';
require __DIR__ . '/../library/ViMbAdmin/Smarty/functions/modifier.yesno.php';

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

echo "== ViMbAdmin Smarty modifiers ==\n";

$check('flipflop false', smarty_modifier_flipflop(false), 1);
$check('flipflop true', smarty_modifier_flipflop(true), 0);
$check('flipflop empty string', smarty_modifier_flipflop(''), 1);
$check('flipflop string zero', smarty_modifier_flipflop('0'), 1);
$check('flipflop multi-zero string remains truthy', smarty_modifier_flipflop('00'), 0);
$check('flipflop empty array', smarty_modifier_flipflop([]), 1);
$check('flipflop non-empty array', smarty_modifier_flipflop([0]), 0);

$check('int null', smarty_modifier_int(null), 0);
$check('int false', smarty_modifier_int(false), 0);
$check('int true', smarty_modifier_int(true), 1);
$check('int positive numeric string', smarty_modifier_int('42'), 42);
$check('int negative numeric string', smarty_modifier_int('-42'), -42);
$check('int decimal truncates toward zero', smarty_modifier_int(3.9), 3);
$check('int negative decimal truncates toward zero', smarty_modifier_int(-3.9), -3);
$check('int maximum boundary', smarty_modifier_int((string) PHP_INT_MAX), PHP_INT_MAX);
$check('int minimum boundary', smarty_modifier_int((string) PHP_INT_MIN), PHP_INT_MIN);
$check('int non-numeric string', smarty_modifier_int('mailbox'), 0);
$intArrayRejected = false;
try {
    smarty_modifier_int(['42']);
} catch (TypeError) {
    $intArrayRejected = true;
}
$check('int rejects array input', $intArrayRejected, true);

$check('yesno null', smarty_modifier_yesno(null), _('No'));
$check('yesno zero', smarty_modifier_yesno(0), _('No'));
$check('yesno empty string', smarty_modifier_yesno(''), _('No'));
$check('yesno string zero', smarty_modifier_yesno('0'), _('No'));
$check('yesno multi-zero string remains truthy', smarty_modifier_yesno('00'), _('Yes'));
$check('yesno negative integer', smarty_modifier_yesno(-1), _('Yes'));
$check('yesno non-empty string', smarty_modifier_yesno('mailbox'), _('Yes'));

echo $failures === 0
    ? "OK: all ViMbAdmin Smarty modifier assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
