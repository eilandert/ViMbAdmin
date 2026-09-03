<?php

declare(strict_types=1);

/** @return string */
function contractSource(string $path): string
{
    $contents = file_get_contents(__DIR__ . '/../' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException("Cannot read {$path}");
    }
    return $contents;
}

$failures = 0;
$check = static function (string $label, bool $condition) use (&$failures): void {
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$readme = contractSource('README.md');
$config = contractSource('application/configs/application.ini.dist');
$cli = contractSource('src/Kernel/Cli/Command/QueueRunCommand.php');
$http = contractSource('src/Kernel/Controller/QueueController.php');
$mcp = contractSource('docs/mcp-auth.md');

echo "== queue and MCP documentation contracts ==\n";
$check('CLI documentation matches repeated batches and the --once boundary',
    str_contains($cli, 'while (!$once && $n > 0)')
        && str_contains($readme, 'Repeats `max_per_run` batches until empty/throttled; `--once` stops after one batch')
        && str_contains($config, 'CLI repeats batches until empty unless --once'));
$check('HTTP documentation matches acknowledgement, after-send execution, and both bounds',
    str_contains($http, "json_encode(['triggered' => true])")
        && str_contains($http, '$batches < 100')
        && str_contains($http, 'microtime(true) < $deadline')
        && str_contains($readme, 'Same FPM worker, after response send; no fork/spawn')
        && str_contains($config, 'same FPM worker after the response is'));
$check('MCP archive documentation uses the real task type and status vocabulary',
    str_contains($mcp, 'an `ARCHIVE` task with initial status `PENDING`')
        && !str_contains($mcp, 'PENDING_ARCHIVE'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
