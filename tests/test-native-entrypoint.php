<?php

$root = dirname(__DIR__);
$php = PHP_BINARY;

function runPath(string $path): array
{
    global $root, $php;

    $script = tempnam(sys_get_temp_dir(), 'vimbadmin-entrypoint-');
    file_put_contents($script, sprintf(
        '<?php $_SERVER["REQUEST_URI"] = %s; ob_start(); include %s; $body = ob_get_clean(); echo http_response_code(), "\\n", $body;',
        var_export($path, true),
        var_export($root . '/public/index.php', true)
    ));
    exec(escapeshellarg($php) . ' ' . escapeshellarg($script), $output, $status);
    unlink($script);

    return [$status, implode("\n", $output)];
}

/** @return array{int, string} */
function runWithoutApplicationDirectory(): array
{
    global $root, $php;

    $directory = sys_get_temp_dir() . '/vimbadmin-entrypoint-' . bin2hex(random_bytes(8));
    mkdir($directory . '/public', 0700, true);
    copy($root . '/public/index.php', $directory . '/public/index.php');
    exec(
        escapeshellarg($php) . ' ' . escapeshellarg($directory . '/public/index.php') . ' 2>&1',
        $output,
        $status
    );
    unlink($directory . '/public/index.php');
    rmdir($directory . '/public');
    rmdir($directory);

    return [$status, implode("\n", $output)];
}

final class TestNativeEntrypointHarnessState
{
    public static int $count = 0;
}

$failures =& TestNativeEntrypointHarnessState::$count;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { TestNativeEntrypointHarnessState::$count++; }
}

echo "== native entry point ==\n";

[$status, $unknown] = runPath('/totally/unknown');
check('unknown route exits successfully', $status === 0);
check('unknown route returns native 404', str_starts_with($unknown, "404\nNot found"));

[$status, $export] = runPath('/exportsettings/thunderbird/email/user@example.com');
check('removed export route exits successfully', $status === 0);
check('removed export route returns native 404', str_starts_with($export, "404\nNot found"));

[$status, $missingApplication] = runWithoutApplicationDirectory();
check('missing application directory fails fast', $status !== 0);
check(
    'missing application directory reports the bootstrap location',
    str_contains($missingApplication, 'Unable to resolve the application directory')
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
