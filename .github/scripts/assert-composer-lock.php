<?php

declare(strict_types=1);

$path = $argv[1] ?? 'composer.lock';
$contents = file_get_contents($path);
if (!is_string($contents)) {
    fwrite(STDERR, "Cannot read {$path}.\n");
    exit(1);
}

$lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($lock)) {
    throw new RuntimeException('Composer lock root must be an object.');
}
$packages = $lock['packages'] ?? null;
$packagesDev = $lock['packages-dev'] ?? null;
if (!is_array($packages) || !is_array($packagesDev)) {
    throw new RuntimeException('Composer lock package lists are missing.');
}
foreach (array_merge($packages, $packagesDev) as $package) {
    $dist = is_array($package) ? ($package['dist'] ?? null) : null;
    $shasum = is_array($dist) ? ($dist['shasum'] ?? null) : null;
    $name = is_array($package) ? ($package['name'] ?? null) : null;
    if (!is_string($name) || !is_string($shasum) || $shasum === '') {
        fwrite(STDERR, (is_string($name) ? $name : 'Unknown package') . " has no dist shasum.\n");
        exit(1);
    }
}

printf("All locked package distributions have shasums.\n");
