#!/usr/bin/env php
<?php

error_reporting(E_ALL & ~E_DEPRECATED);

require_once dirname(__FILE__) . '/../vendor/autoload.php';

$applicationPath = defined('APPLICATION_PATH')
    ? constant('APPLICATION_PATH')
    : realpath(dirname(__FILE__) . '/../application');
if (!is_string($applicationPath) || !is_dir($applicationPath)) {
    fwrite(STDERR, "ViMbAdmin application directory is unavailable.\n");
    exit(1);
}
defined('APPLICATION_PATH') || define('APPLICATION_PATH', $applicationPath);
defined('APPLICATION_ENV') || define('APPLICATION_ENV', getenv('APPLICATION_ENV') ?: 'development');

set_include_path(implode(PATH_SEPARATOR, [
    realpath(APPLICATION_PATH . '/../library'),
    get_include_path(),
]));

if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === '--database') {
    if (($_SERVER['argv'][2] ?? 'default') !== 'default') {
        fwrite(STDERR, "Only the default database connection is supported.\n");
        exit(1);
    }
    array_splice($_SERVER['argv'], 1, 2);
}

$container = \ViMbAdmin\Kernel\Bootstrap::boot($applicationPath, APPLICATION_ENV, 'cli');
$entityManager = $container->entityManager();
if (!$entityManager instanceof \Doctrine\ORM\EntityManagerInterface) {
    fwrite(STDERR, "ViMbAdmin did not provide a Doctrine entity manager.\n");
    exit(1);
}
$provider = new \Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider(
    $entityManager
);

\Doctrine\ORM\Tools\Console\ConsoleRunner::run($provider);
