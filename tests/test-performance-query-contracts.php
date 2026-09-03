<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};
$root = getenv('PERFORMANCE_CONTRACT_ROOT') ?: dirname(__DIR__);
$source = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) { throw new RuntimeException("Cannot read {$path}"); }
    return $value;
};

echo "== bounded query and filesystem contracts ==\n";

$captcha = $source('library/OSS/Captcha/Image.php');
$check('captcha cleanup stats candidates once before sorting',
    substr_count($captcha, '@filemtime($file)') === 1
    && !str_contains($captcha, '@filemtime($a)')
    && str_contains($captcha, '$mtimes[$a] <=> $mtimes[$b]'));

$domain = $source('application/Repositories/Domain.php');
$check('domain choices use scalar hydration',
    str_contains($domain, "SELECT d.id AS id, d.domain AS domain")
    && str_contains($domain, '$query->getArrayResult()'));

$mcp = $source('application/controllers/McpController.php');
$check('all MCP list abilities use validated bounded repository queries',
    substr_count($mcp, '$this->_listBounds( $params )') === 3
    && substr_count($mcp, ', $limit, $offset )') === 2
    && str_contains($mcp, '->setFirstResult($offset)->setMaxResults($limit)')
    && str_contains($mcp, 'LOWER(d.domain) IN (:allowed)')
    && str_contains($mcp, 'LIST_MAX_OFFSET = 10000'));

$aliases = $source('application/plugins/MailboxAutomaticAliases.php');
$check('automatic aliases flush once per created group',
    substr_count($aliases, 'if( $created )') === 2
    && substr_count($aliases, '$controller->getD2EM()->flush();') === 2);

$runner = $source('library/ViMbAdmin/Service/QueueRunner.php');
$sweepStart = strpos($runner, 'private function autopruneSweep()');
$sweepEnd = strpos($runner, 'private function initializedAutopruneArchives');
if ($sweepStart === false || $sweepEnd === false || $sweepEnd <= $sweepStart) {
    throw new RuntimeException('Cannot isolate the autoprune sweep source.');
}
$sweep = substr($runner, $sweepStart, $sweepEnd - $sweepStart);
$check('autoprune detects open tasks with one set-based query',
    str_contains($sweep, 'LOWER(t.username) IN (:users)')
    && str_contains($sweep, 'array_chunk($users, 500)')
    && !str_contains($sweep, 'SELECT COUNT(t.id)'));

$maintenance = $source('src/Kernel/Controller/MaintenanceController.php');
$check('schema confirmation reuses its already-computed pending count',
    str_contains($maintenance, "['schemaSql' => \$sql, 'dbPending' => count(\$sql)]")
    && str_contains($maintenance, "array_key_exists('dbPending', \$extra)"));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
