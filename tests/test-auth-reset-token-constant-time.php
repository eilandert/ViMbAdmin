<?php

$source = file_get_contents(__DIR__ . '/../src/Kernel/Controller/AuthController.php');
if (!is_string($source)) {
    fwrite(STDERR, "unable to read AuthController\n");
    exit(1);
}

if (str_contains($source, 'in_array($token, $tokens')) {
    fwrite(STDERR, "reset token comparison still uses in_array\n");
    exit(1);
}
if (!preg_match('/foreach \(\$tokens as \$candidate\).*?hash_equals\(\$candidate, \$token\)/s', $source)) {
    fwrite(STDERR, "reset token comparison is not a constant-count hash_equals loop\n");
    exit(1);
}
$loopStart = strpos($source, 'foreach ($tokens as $candidate)');
$loopEnd = $loopStart === false ? false : strpos($source, "\n                    }", $loopStart);
if ($loopStart === false || $loopEnd === false
    || str_contains(substr($source, $loopStart, $loopEnd - $loopStart), 'break')) {
    fwrite(STDERR, "reset token loop may short-circuit\n");
    exit(1);
}
echo "ok - reset token comparison checks every candidate with hash_equals\n";
