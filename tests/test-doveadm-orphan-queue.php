<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../library/ViMbAdmin/Doveadm.php';

final class ScriptedDoveadm extends ViMbAdmin_Doveadm
{
    /** @param list<array{0:int,1:string}> $responses */
    public function __construct(private array $responses)
    {
        parent::__construct('http://doveadm.invalid/doveadm/v1', 'test-key', 1);
    }

    protected function _post($payload)
    {
        if (!is_string($payload) || $this->responses === []) {
            throw new RuntimeException('Unexpected scripted Doveadm request.');
        }
        return array_shift($this->responses);
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};
$sourceRoot = getenv('DOVEADM_ORPHAN_CONTRACT_ROOT') ?: dirname(__DIR__);
$source = static function (string $path) use ($sourceRoot): string {
    $text = file_get_contents($sourceRoot . '/' . $path);
    if (!is_string($text)) { throw new RuntimeException("Cannot read {$path}"); }
    return $text;
};

echo "== Doveadm lifecycle and queued orphan discovery ==\n";
$client = new ScriptedDoveadm([
    [200, '[["doveadmResponse",[{"ok":true}],"tag"]]'],
    [200, '[["error",{"type":"failed","exitCode":75},"tag"]]'],
]);
$check('successful responses retain their decoded row contract', $client->run('ping') === [['ok' => true]]);
$error = null;
try { $client->run('ping'); } catch (ViMbAdmin_Exception $e) { $error = $e->getMessage(); }
$check('reused instances retain command error semantics', $error === "doveadm 'ping' failed: failed (exit 75)");

$doveadm = $source('library/ViMbAdmin/Doveadm.php');
$check('one easy handle is reset per request and closed at lifecycle end',
    substr_count($doveadm, '$this->_handle = curl_init();') === 1
    && str_contains($doveadm, 'curl_reset( $ch );')
    && str_contains($doveadm, 'curl_close( $this->_handle );'));

$controller = $source('src/Kernel/Controller/MaintenanceController.php');
$check('both orphan request handlers use queued or cached discovery',
    str_contains($controller, 'TYPE_SCAN_ORPHANS')
    && substr_count($controller, '$this->cachedOrphans()') === 2
    && !str_contains($controller, 'private function scanOrphans()'));

$runner = $source('library/ViMbAdmin/Service/QueueRunner.php');
$check('orphan discovery executes as a bounded queue task and caches its result',
    str_contains($runner, 'case \\Entities\\MailboxTask::TYPE_SCAN_ORPHANS:')
    && str_contains($runner, 'ORPHAN_SCAN_MAX = 500')
    && str_contains($runner, "['orphans' => \$orphans]")
    && str_contains($runner, 'WHERE LOWER(m.username) IN (:candidates)'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
