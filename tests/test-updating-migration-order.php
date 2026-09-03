<?php

$updating = file_get_contents(__DIR__ . '/../UPDATING');
if (!is_string($updating)) {
    fwrite(STDERR, "unable to read UPDATING\n");
    exit(1);
}
$seed = strpos($updating, 'mysql < contrib/migrations/2026-06-fork-schema.sql');
$schema = strpos($updating, './bin/vimbtool.php -a maintenance.cli-schema-update');
$ok = $seed !== false && $schema !== false && $seed < $schema
    && str_contains($updating, 'seeds `dovecot_quota` from the legacy columns before retiring them');
echo ($ok ? 'ok   ' : 'FAIL ') . "migration seeds legacy quota before schema update\n";
exit($ok ? 0 : 1);
