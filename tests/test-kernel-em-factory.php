<?php
/**
 * Regression smoke test: the native Doctrine EM factory (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * Builds an entity manager from a synthetic options array the way the native
 * bootstrap will, against the REAL shipped XML mapping dir, and asserts the
 * factory reproduces the OSS_Resource_Doctrine2 wiring: an EntityManager whose
 * Configuration carries the XML metadata driver, the cache, and the proxy
 * settings. The EM is connection-lazy so this needs no database — the same
 * approach test-cache-bootstrap.php uses for the ORM call shape.
 *
 * Runs in the cache-wiring CI job (vendor + doctrine/orm present).
 * Exit 0 = all passed, non-zero = a failure.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing — run composer install first\n");
    exit(2);
}
require $autoload;

require __DIR__ . '/../src/Kernel/Doctrine/EntityManagerFactory.php';

use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\QueryBuilder;
use Entities\Admin as AdminEntity;
use Entities\DirectoryEntry as DirectoryEntryEntity;
use Entities\Domain as DomainEntity;
use Entities\Log as LogEntity;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionMethod as CoreReflectionMethod;
use Repositories\DirectoryEntry as DirectoryEntryRepository;
use Repositories\Log as LogRepository;
use UnexpectedValueException as QueryProbeFailure;

$appPath = realpath(__DIR__ . '/../application');

$options = [
    'resources' => [
        'doctrine2' => [
            'connection' => [
                // pdo_sqlite :memory: — never connected (lazy), just a valid driver.
                'options' => [
                    'driver'        => 'pdo_sqlite',
                    'memory'        => true,
                    'driverOptions' => [PDO::ATTR_TIMEOUT => 1],
                ],
            ],
            'proxies_path'           => $appPath . '/Proxies',
            'proxies_namespace'      => 'Proxies',
            'models_path'            => $appPath,
            'models_namespace'       => 'Entities',
            'repositories_path'      => $appPath,
            'repositories_namespace' => 'Repositories',
            'autogen_proxies'        => '0',
        ],
        'doctrine2cache' => [
            'type'      => 'ArrayCache',
            'namespace' => 'ViMbAdmin3',
        ],
    ],
];

$failures = 0;
function check(string $label, callable $fn): void {
    global $failures;
    try {
        $fn();
        echo "OK   $label\n";
    } catch (\Throwable $e) {
        $failures++;
        printf("FAIL %s :: %s: %s\n", $label, get_class($e), $e->getMessage());
    }
}

// Register the Entities/Repositories autoloaders up front: repository probes
// below extend the real classes, and metadata loading reflects their entities.
EntityManagerFactory::registerEntityAutoloaders($options);

function checkDirectoryEntryRepositoryContract(mixed $entityManager): void {
    global $failures;

    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL DirectoryEntry metadata resolves its entity-specific repository\n";
        $failures++;
        return;
    }
    $metadata = $entityManager->getClassMetadata(DirectoryEntryEntity::class);
    $repository = $entityManager->getRepository(DirectoryEntryEntity::class);
    $ok = $metadata->customRepositoryClassName === DirectoryEntryRepository::class
        && $repository instanceof DirectoryEntryRepository
        && $repository->getClassName() === DirectoryEntryEntity::class;
    echo ($ok ? 'OK   ' : 'FAIL ')
        . "DirectoryEntry metadata resolves its entity-specific repository\n";
    if (!$ok) {
        $failures++;
    }
}

/** @return array<int|string,mixed> */
function logQueryParameters(QueryBuilder $query): array
{
    $parameters = [];
    foreach ($query->getParameters() as $parameter) {
        $parameters[$parameter->getName()] = $parameter->getValue();
    }
    return $parameters;
}

/** @param list<mixed> $arguments */
function invokeLogQuery(LogRepository $repository, string $method, array $arguments): QueryBuilder
{
    $query = (new CoreReflectionMethod($repository, $method))->invokeArgs($repository, $arguments);
    if (!$query instanceof QueryBuilder) {
        throw new QueryProbeFailure('Log repository query probe did not return a QueryBuilder');
    }
    return $query;
}

function recordLogRepositoryCheck(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function logMetadataContract(EntityManagerInterface $entityManager, LogRepository $repository): bool
{
    $metadata = $entityManager->getClassMetadata(LogEntity::class);
    return $metadata->customRepositoryClassName === LogRepository::class
        && $repository->getClassName() === LogEntity::class;
}

function logListHydrationContract(string $dql): bool
{
    return str_contains($dql, 'l.id as id')
        && str_contains($dql, 'l.timestamp as timestamp')
        && str_contains($dql, 'a.username as admin')
        && str_contains($dql, 'd.domain as domain');
}

/** @param array<int|string,mixed> $parameters */
function logListScopeContract(string $dql, array $parameters, AdminEntity $admin, DomainEntity $domain): bool
{
    return str_contains($dql, 'JOIN d.Admins d2a')
        && str_contains($dql, 'd2a = ?1')
        && str_contains($dql, 'l.Domain = ?2')
        && ($parameters[1] ?? null) === $admin
        && ($parameters[2] ?? null) === $domain;
}

function logCountContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'COUNT(DISTINCT l.id)')
        && str_contains($query->getDQL(), 'l.action LIKE :s')
        && (logQueryParameters($query)['s'] ?? null) === '%mail%';
}

function logSelectedPageContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'ORDER BY l.action ASC')
        && $query->getFirstResult() === 0
        && $query->getMaxResults() === 1
        && (logQueryParameters($query)['s'] ?? null) === '%50\\%\\_\\\\off%';
}

function logFallbackPageContract(QueryBuilder $query): bool
{
    return str_contains($query->getDQL(), 'ORDER BY l.timestamp DESC')
        && !str_contains($query->getDQL(), 'LIKE :s')
        && $query->getFirstResult() === 4
        && $query->getMaxResults() === 9;
}

function checkLogRepositoryQueryContract(mixed $entityManager): void
{
    global $failures;

    if (!$entityManager instanceof EntityManagerInterface) {
        echo "FAIL Log repository query contract has an entity manager\n";
        $failures++;
        return;
    }

    $admin = new AdminEntity();
    $domain = new DomainEntity();
    $repository = $entityManager->getRepository(LogEntity::class);
    if (!$repository instanceof LogRepository) {
        echo "FAIL Log metadata resolves its entity-specific repository\n";
        $failures++;
        return;
    }

    $listQuery = invokeLogQuery($repository, 'logListQuery', [$admin, $domain]);
    $pageQuery = invokeLogQuery($repository, 'pagedLogRowsQuery', [$admin, $domain, '50%_\\off', 'action', 'ASC', -5, 0]);
    $fallbackQuery = invokeLogQuery($repository, 'pagedLogRowsQuery', [null, null, '', 'unsupported', 'sideways', 4, 9]);
    $countQuery = invokeLogQuery($repository, 'logCountQuery', [$admin, $domain, 'mail']);

    recordLogRepositoryCheck('Log metadata resolves its entity-specific repository', logMetadataContract($entityManager, $repository));
    recordLogRepositoryCheck('log list retains all array-hydration fields', logListHydrationContract($listQuery->getDQL()));
    recordLogRepositoryCheck('log list preserves admin/domain joins and positional parameters', logListScopeContract($listQuery->getDQL(), logQueryParameters($listQuery), $admin, $domain));
    recordLogRepositoryCheck('paged logs preserve search escaping and scoped count semantics', logCountContract($countQuery));
    recordLogRepositoryCheck('paged logs preserve selected sorting and clamp pagination bounds', logSelectedPageContract($pageQuery));
    recordLogRepositoryCheck('paged logs fall back to timestamp descending without a search predicate', logFallbackPageContract($fallbackQuery));
}

$em = null;

// The attribute metadata driver needs no extra extension (reflection only), so
// these asserts always run.
check('factory builds an EntityManager', function () use ($options, &$em) {
    $em = EntityManagerFactory::create($options);
    if (!$em instanceof EntityManagerInterface) {
        throw new RuntimeException('not an EntityManagerInterface: ' . get_class($em));
    }
});

check('metadata driver is the attribute driver over the Entities dir', function () use (&$em) {
    $driver = $em->getConfiguration()->getMetadataDriverImpl();
    if (!$driver instanceof AttributeDriver) {
        throw new RuntimeException('metadata driver is ' . get_debug_type($driver));
    }
});

check('proxy namespace + autogen flag applied', function () use (&$em, $options) {
    $cfg = $em->getConfiguration();
    if ($cfg->getProxyNamespace() !== 'Proxies') {
        throw new RuntimeException('proxy namespace = ' . var_export($cfg->getProxyNamespace(), true));
    }
    // autogen_proxies '0' must map to AUTOGENERATE_NEVER (0), not a truthy mode.
    if ((int) $cfg->getAutoGenerateProxyClasses() !== 0) {
        throw new RuntimeException('autogen = ' . var_export($cfg->getAutoGenerateProxyClasses(), true));
    }

    foreach (range(0, 4) as $mode) {
        $modeOptions = $options;
        $modeOptions['resources']['doctrine2']['autogen_proxies'] = (string) $mode;
        $modeEm = EntityManagerFactory::create($modeOptions);
        if (!$modeEm instanceof EntityManagerInterface) {
            throw new RuntimeException('proxy-mode factory did not return an EntityManagerInterface');
        }
        if ($modeEm->getConfiguration()->getAutoGenerateProxyClasses() !== $mode) {
            throw new RuntimeException("proxy mode $mode was not preserved");
        }
    }

    $invalidOptions = $options;
    $invalidOptions['resources']['doctrine2']['autogen_proxies'] = '5';
    try {
        EntityManagerFactory::create($invalidOptions);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('invalid proxy mode was accepted');
});

check('metadata cache is wired (no exception fetching it)', function () use (&$em) {
    $cfg = $em->getConfiguration();
    foreach (['metadata' => $cfg->getMetadataCache(), 'query' => $cfg->getQueryCache(), 'result' => $cfg->getResultCache()] as $name => $cache) {
        if (!$cache instanceof CacheItemPoolInterface) {
            throw new RuntimeException("$name cache is " . get_debug_type($cache));
        }
    }
});

check('a known entity attribute mapping loads through the driver', function () use (&$em) {
    // Proves the driver reads the #[ORM\...] attributes on Entities\Admin and
    // produces class metadata (no DB needed).
    $meta = $em->getClassMetadata('Entities\\Admin');
    if ($meta->getTableName() === '') {
        throw new RuntimeException('Admin metadata has no table name');
    }
});

checkDirectoryEntryRepositoryContract($em);
checkLogRepositoryQueryContract($em);

check('registerEntityAutoloaders loads an Entities class', function () use ($options) {
    EntityManagerFactory::registerEntityAutoloaders($options);
    if (!class_exists('Entities\\Admin')) {
        throw new RuntimeException('Entities\\Admin did not autoload');
    }
});

echo 'PHP ' . PHP_VERSION . "\n";
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
