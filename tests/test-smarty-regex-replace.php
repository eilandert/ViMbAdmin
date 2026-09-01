<?php

require __DIR__ . '/../library/OSS/Smarty/functions/modifier.regex_replace.php';

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

echo "== Smarty regex_replace modifier ==\n";

$check(
    'scalar pattern and replacement',
    smarty_modifier_regex_replace('mailbox-123', '/\d+/', '456'),
    'mailbox-456'
);
$check(
    'pattern and replacement arrays',
    smarty_modifier_regex_replace('alpha-123', ['/alpha/', '/\d+/'], ['beta', '456']),
    'beta-456'
);
$check(
    'scalar replacement applies to every pattern',
    smarty_modifier_regex_replace('alpha-123', ['/alpha/', '/\d+/'], 'x'),
    'x-x'
);
$check(
    'escaped delimiter and options are preserved',
    smarty_modifier_regex_replace('A/B a/b', '/a\\/b/i', 'mailbox'),
    'mailbox mailbox'
);
$check(
    'unsafe e option is stripped while other options remain',
    smarty_modifier_regex_replace('MAILBOX', '/mailbox/ie', 'account'),
    'account'
);

$warning = null;
set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
    $warning = [$severity, $message];
    return true;
});
try {
    $malformed = smarty_modifier_regex_replace('mailbox', '/[/', 'account');
} finally {
    restore_error_handler();
}
$check('malformed pattern returns null', $malformed, null);
$check('malformed pattern raises warning', is_array($warning), true);
$check('malformed pattern warning severity', $warning[0] ?? null, E_WARNING);
$check(
    'null byte truncates unsafe pattern suffix',
    smarty_modifier_regex_replace('mailbox', '/mailbox/' . "\0" . 'e', 'account'),
    'account'
);

echo $failures === 0
    ? "OK: all Smarty regex_replace modifier assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
