<?php

require __DIR__ . '/../library/ViMbAdmin/Mcp/Exception.php';
require __DIR__ . '/../library/ViMbAdmin/Mcp/RateLimit.php';

final class McpRateLimitAssertions
{
    public static int $failures = 0;
}

function mcpRateLimitCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        McpRateLimitAssertions::$failures++;
    }
}

/** @return list<int> */
function mcpRateLimitState(string $file): array
{
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return [];
    }
    $state = [];
    foreach ($decoded as $value) {
        if (!is_int($value)) {
            return [];
        }
        $state[] = $value;
    }
    return $state;
}

function mcpRateLimitCleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}

echo "== MCP destructive rate limit ==\n";

$stateDir = sys_get_temp_dir() . '/vimbadmin-rate-limit-' . bin2hex(random_bytes(8));
$limiter = new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 2, 'window' => 3600]);
$limiter->hit(42, 'archive/restore');
$limiter->hit(42, 'archive/restore');
$stateFile = $stateDir . '/42-archiverestore.json';
mcpRateLimitCheck('bucket name is sanitized in the per-token filename', is_file($stateFile));
mcpRateLimitCheck('hits are recorded as a JSON timestamp list', count(mcpRateLimitState($stateFile)) === 2);

try {
    $limiter->hit(42, 'archive/restore');
    mcpRateLimitCheck('third in-window hit is denied', false);
} catch (ViMbAdmin_Mcp_Exception $e) {
    mcpRateLimitCheck('third in-window hit is denied with 429', $e->getCode() === 429);
}
mcpRateLimitCheck('denied hit does not alter state', count(mcpRateLimitState($stateFile)) === 2);

$oldFile = $stateDir . '/7-destructive.json';
file_put_contents($oldFile, json_encode([time() - 10]));
$pruning = new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 1, 'window' => 2]);
$pruning->hit(7);
mcpRateLimitCheck('timestamps outside the window are pruned before counting', count(mcpRateLimitState($oldFile)) === 1);

$malformedFile = $stateDir . '/8-destructive.json';
file_put_contents($malformedFile, '{not-json');
(new ViMbAdmin_Mcp_RateLimit(['statedir' => $stateDir, 'max' => 1, 'window' => 60]))->hit(8);
mcpRateLimitCheck('malformed JSON is treated as empty state', count(mcpRateLimitState($malformedFile)) === 1);

$disabledDir = $stateDir . '/disabled';
(new ViMbAdmin_Mcp_RateLimit(['statedir' => $disabledDir, 'max' => 0]))->hit(9);
mcpRateLimitCheck('zero maximum disables the limiter without creating state', !file_exists($disabledDir));

$notDirectory = $stateDir . '/not-a-directory';
file_put_contents($notDirectory, 'occupied');
$errorLog = $stateDir . '/error.log';
$previousLog = ini_set('error_log', $errorLog);
(new ViMbAdmin_Mcp_RateLimit(['statedir' => $notDirectory, 'max' => 1]))->hit(10);
if ($previousLog !== false) {
    ini_set('error_log', $previousLog);
}
$logged = (string) file_get_contents($errorLog);
mcpRateLimitCheck('state open failure remains fail-open and is logged', str_contains($logged, 'rate limit NOT enforced for token 10'));

mcpRateLimitCleanup($stateDir);
$failureCount = McpRateLimitAssertions::$failures;
echo $failureCount === 0 ? "\nALL PASSED\n" : "\n{$failureCount} FAILED\n";
exit(min(1, $failureCount));
