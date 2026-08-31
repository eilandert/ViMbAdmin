<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$php = PHP_BINARY;
$failures = 0;

$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * @param list<string> $arguments
 * @return array{int,string}
 */
function runCli(string $root, string $php, string $script, array $arguments = [], ?string $prepend = null): array
{
    $command = escapeshellarg($php);
    if ($prepend !== null) {
        $command .= ' -d auto_prepend_file=' . escapeshellarg($prepend);
    }
    $command .= ' ' . escapeshellarg($root . '/' . $script);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    exec($command . ' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

echo "== CLI entry points ==\n";

[$status, $output] = runCli($root, $php, 'bin/vimbtool.php', ['--help']);
$check('vimbtool help succeeds', $status === 0);
$check('vimbtool help retains usage', str_contains($output, 'Usage: vimbtool.php -a controller.action'));

[$status, $output] = runCli($root, $php, 'bin/vimbtool.php');
$check('vimbtool missing action fails', $status === 1);
$check('vimbtool missing action explains the error', str_contains($output, 'ERROR: no action specified'));

[$status, $output] = runCli($root, $php, 'bin/vimbtool.php', ['--action=not-a-real.action']);
$check('vimbtool unknown action fails', $status === 1);
$check('vimbtool unknown action explains the error', str_contains($output, "ERROR: unknown action 'not-a-real.action'"));

[$status, $output] = runCli($root, $php, 'bin/doctrine-cli.php', ['--database', 'reporting']);
$check('doctrine CLI rejects non-default databases', $status === 1);
$check('doctrine CLI retains its default-only message', str_contains($output, 'Only the default database connection is supported.'));

$prepend = tempnam(sys_get_temp_dir(), 'vimbadmin-cli-path-');
if ($prepend === false) {
    fwrite(STDERR, "Could not create CLI path test fixture.\n");
    exit(1);
}
file_put_contents($prepend, "<?php define('APPLICATION_PATH', '/definitely/missing/vimbadmin/application');\n");

foreach (['bin/doctrine-cli.php', 'bin/vimbtool.php'] as $script) {
    [$status, $output] = runCli($root, $php, $script, ['--help'], $prepend);
    $check("{$script} rejects an unavailable application path", $status === 1);
    $check("{$script} reports the unavailable application path", $output === 'ViMbAdmin application directory is unavailable.');
}
unlink($prepend);

$doctrineSource = (string) file_get_contents($root . '/bin/doctrine-cli.php');
$check(
    'doctrine CLI validates the container entity manager interface',
    str_contains($doctrineSource, '$entityManager instanceof \\Doctrine\\ORM\\EntityManagerInterface')
);
$check(
    'doctrine CLI gives the validated manager to its provider',
    preg_match('/SingleManagerProvider\(\s*\$entityManager\s*\)/s', $doctrineSource) === 1
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
