<?php
/**
 * Regression test for the 2026-07-10 audit MAJOR (defence-in-depth half):
 * QueueRunner::assertPathSafe() must reject any username/domain component that
 * could escape the maildir/backup jail before it is substituted into a
 * filesystem path (backupDest '%d/%u', removeMaildirHome). Guards against a
 * legacy or externally-inserted row that bypassed create-time validation.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';

$m = new ReflectionMethod('ViMbAdmin_Service_QueueRunner', 'assertPathSafe');
$m->setAccessible(true);

final class TestQueuerunnerPathSafeHarnessState
{
    public static int $count = 0;
}

$fail =& TestQueuerunnerPathSafeHarnessState::$count;
function check(string $name, bool $ok): void
{

    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) { TestQueuerunnerPathSafeHarnessState::$count++; }
}

$rejects = static function (string $v) use ($m): bool {
    try { $m->invoke(null, $v); return false; }
    catch (\Throwable $e) { return true; }
};

// Traversal / separator / null shapes must be refused.
foreach (['../../../../etc/cron.d/x@d.com', 'a/b@d.com', '..', 'a..b@d.com', "x\0y", ''] as $v) {
    check('rejects ' . json_encode($v), $rejects($v));
}
// A legitimate username (no '/', no '..') passes through unchanged.
foreach (['john.doe@example.com', 'a+b@sub.example.org'] as $v) {
    check('accepts ' . json_encode($v), !$rejects($v) && $m->invoke(null, $v) === $v);
}

$runner = (new ReflectionClass('ViMbAdmin_Service_QueueRunner'))->newInstanceWithoutConstructor();
$options = new ReflectionProperty($runner, 'options');
$options->setValue($runner, ['doveadm' => ['backup' => ['dest' => 'maildir:/backups/%d/%u']]]);
$backupDest = new ReflectionMethod($runner, 'backupDest');
$withoutDomain = (new \Entities\MailboxTask())->setUsername('user@example.test');
check('absent optional domain uses the validated username suffix',
    $backupDest->invoke($runner, $withoutDomain) === 'maildir:/backups/example.test/user@example.test');
$namedDomain = (new \Entities\Domain())->setDomain('domain.example');
$withDomain = (new \Entities\MailboxTask())->setUsername('user@example.test')->setDomain($namedDomain);
check('present domain uses its required initialized name',
    $backupDest->invoke($runner, $withDomain) === 'maildir:/backups/domain.example/user@example.test');
$unnamedDomain = new \Entities\Domain();
$malformed = (new \Entities\MailboxTask())->setUsername('user@example.test')->setDomain($unnamedDomain);
$malformedError = null;
try { $backupDest->invoke($runner, $malformed); }
catch (\Throwable $e) { $malformedError = $e->getPrevious()?->getMessage() ?? $e->getMessage(); }
check('present malformed domain never falls back to the username suffix',
    $malformedError === 'Domain name cannot be null.');

if ($fail === 0) {
    echo "OK: all QueueRunner path-safety assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAILED: {$fail} assertion(s)\n";
exit(1);
