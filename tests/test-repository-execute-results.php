<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ViMbAdmin\Kernel\Doctrine\ResultValidator;

$checks = 0;
$failures = 0;

$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$failure = static function (mixed $result): ?string {
    try {
        ResultValidator::affectedRows($result, 'Execute test');
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }
    return null;
};

echo "== Doctrine execute results ==\n";

$check('zero affected rows is preserved', ResultValidator::affectedRows(0, 'Execute test') === 0);
$check('positive affected rows are preserved', ResultValidator::affectedRows(7, 'Execute test') === 7);

foreach (['7', null, false, []] as $invalid) {
    $check(
        'non-integer result is rejected: ' . get_debug_type($invalid),
        $failure($invalid) === 'Execute test must return an integer row count.',
    );
}

$check('fixed assertion count', $checks === 6);

echo $failures === 0 ? "ALL PASSED\n" : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
