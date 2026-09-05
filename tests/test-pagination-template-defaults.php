<?php

$templates = [
    'alias' => 'application/views/alias/js/list.js',
    'archive' => 'application/views/archive/js/list.js',
    'domain' => 'application/views/domain/js/list.js',
    'log' => 'application/views/log/js/list.js',
    'mailbox' => 'application/views/mailbox/js/list.js',
];
$failed = 0;
foreach ($templates as $name => $path) {
    $source = file_get_contents(__DIR__ . '/../' . $path);
    $ok = is_string($source)
        && preg_match('/\{if !isset\(\$options\.defaults\.server_side\.pagination(?:\.' . $name . ')?\.enable\) \|\| \$options\.defaults\.server_side\.pagination(?:\.' . $name . ')?\.enable \}/', $source) === 1;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . " missing-key pagination defaults on\n";
    if (!$ok) {
        $failed++;
    }
}
if ($failed !== 0) {
    exit(1);
}
echo 'ALL PASSED (' . count($templates) . " checks)\n";
exit(0);
