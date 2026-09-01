<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Repositories/Alias.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AliasController;
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ControllerAliasIdentitySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function __get(string $key): mixed { return $this->data[$key] ?? null; }
    public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function __isset(string $key): bool { return isset($this->data[$key]); }
    public function __unset(string $key): void { unset($this->data[$key]); }

    /** @return array<string,mixed> */
    public function values(): array { return $this->data; }
}

final class ControllerAliasIdentityView
{
    public int $renders = 0;
    public function __set(string $key, mixed $value): void {}
    public function render(string $script): string
    {
        $this->renders++;
        return $script;
    }
}

final class ControllerAliasIdentityResources
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly ControllerAliasIdentitySession $session,
        private readonly ControllerAliasIdentityView $view,
        private readonly array $options = [],
    ) {}

    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }
    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->session,
            'smarty' => $this->view,
            default => null,
        };
    }
}

final class ControllerAliasIdentityAdmin extends \Entities\Admin
{
    public function getId(): int { return 1; }
    public function getUsername(): string { return 'admin@example.test'; }
    public function getSuper(): bool { return true; }
    public function isSuper(): bool { return true; }
}

final class ControllerAliasIdentityAliasRepository extends \Repositories\Alias
{
    /** @param list<\Entities\Alias> $aliases */
    public function __construct(private readonly array $aliases) {}

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->aliases[0] ?? null;
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<\Entities\Alias>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->aliases;
    }
}

final class ControllerAliasIdentityMailboxRepository extends \Repositories\Mailbox
{
    public function __construct(private readonly ?\Entities\Mailbox $mailbox) {}

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->mailbox;
    }
}

final class ControllerAliasIdentityDomainRepository extends \Repositories\Domain
{
    public function __construct(private readonly ?\Entities\Domain $domain) {}

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->domain;
    }
}

final class ControllerAliasIdentityRepositoryFactory implements RepositoryFactory
{
    /** @var array<string,EntityRepository<covariant object>> */
    private array $repositories;

    /** @param array<string,EntityRepository<covariant object>> $repositories */
    public function __construct(array $repositories) { $this->repositories = $repositories; }

    /**
     * @template T of object
     * @param class-string<T> $entityName
     * @return EntityRepository<T>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $repository = $this->repositories[ltrim($entityName, '\\')]
            ?? throw new LogicException('Unexpected repository ' . $entityName);
        /** @var EntityRepository<T> $repository */
        return $repository;
    }
}

/** @param array<string,EntityRepository<covariant object>> $repositories */
function controllerAliasIdentityEntityManager(array $repositories): EntityManager
{
    $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
    $configuration->enableNativeLazyObjects(true);
    $configuration->setRepositoryFactory(new ControllerAliasIdentityRepositoryFactory($repositories));
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    return new EntityManager($connection, $configuration);
}

function controllerAliasIdentityContainer(
    EntityManager $entityManager,
    ControllerAliasIdentitySession $session,
    ControllerAliasIdentityView $view,
): Container {
    $admin = new ControllerAliasIdentityAdmin();
    return new Container(
        new ControllerAliasIdentityResources($entityManager, $session, $view),
        new Auth($session, static fn(int $id): object => $admin),
    );
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpAliases(McpController $controller, array $params): array
{
    $method = new ReflectionMethod($controller, '_aliasesList');
    $result = $method->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP aliases result is not an array');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP aliases result has a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

final class ControllerAliasIdentityState
{
    public static int $failures = 0;
}

function controllerAliasIdentityCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { ControllerAliasIdentityState::$failures++; }
}

echo "== controller alias identity boundaries ==\n";

$domain = (new \Entities\Domain())->setDomain('example.test')->setAliasCount(4);
$gotoMissing = (new \Entities\Alias())->setAddress('sales@example.test')->setDomain($domain);
$aliasSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$aliasView = new ControllerAliasIdentityView();
$aliasEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$gotoMissing]),
]);
$aliasController = new AliasController(
    controllerAliasIdentityContainer($aliasEntityManager, $aliasSession, $aliasView),
    new RouteMatch('alias', 'edit', AliasController::class, 'editAction', ['alid' => '7']),
);
$aliasError = null;
try {
    $aliasController->editAction();
} catch (LogicException $e) {
    $aliasError = $e->getMessage();
}
controllerAliasIdentityCheck('alias edit rejects missing goto before rendering',
    $aliasError === 'Alias goto cannot be null.' && $aliasView->renders === 0);
controllerAliasIdentityCheck('alias edit identity failure leaves state and flash queue untouched',
    $gotoMissing->getGoto() === null && $aliasSession->values() === ['identity' => ['id' => 1]]);

$addressMissing = (new \Entities\Alias())->setGoto('user@example.test')->setDomain($domain);
$mailbox = (new \Entities\Mailbox())->setUsername('user@example.test')->setDomain($domain);
$mailboxSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$mailboxView = new ControllerAliasIdentityView();
$mailboxEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$addressMissing]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$mailboxController = new MailboxController(
    controllerAliasIdentityContainer($mailboxEntityManager, $mailboxSession, $mailboxView),
    new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
        'mid' => '3',
        'alid' => '7',
        'csrf' => 'test-token',
    ]),
);
$mailboxError = null;
try {
    $mailboxController->deleteAliasAction();
} catch (Throwable $e) {
    $mailboxError = $e->getMessage();
}
controllerAliasIdentityCheck('mailbox delete rejects missing address before mutation',
    $mailboxError === 'Alias address cannot be null.'
        && $addressMissing->getGoto() === 'user@example.test'
        && $domain->getAliasCount() === 4
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $mailboxSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

$validAlias = (new \Entities\Alias())
    ->setAddress('sales@example.test')
    ->setGoto('user@example.test')
    ->setActive(true)
    ->setDomain($domain);
$mcpSession = new ControllerAliasIdentitySession();
$mcpView = new ControllerAliasIdentityView();
$mcpEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$validAlias]),
]);
$mcpController = new McpController(
    new Container(
        new ControllerAliasIdentityResources($mcpEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$mcpResult = controllerAliasIdentityMcpAliases($mcpController, ['domain' => 'example.test']);
controllerAliasIdentityCheck('MCP alias list emits required string identities',
    $mcpResult === [
        'domain' => 'example.test',
        'aliases' => [[
            'address' => 'sales@example.test',
            'goto' => 'user@example.test',
            'active' => true,
        ]],
    ]);

$malformedMcpAlias = (new \Entities\Alias())->setAddress('broken@example.test')->setDomain($domain);
$malformedMcpEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$malformedMcpAlias]),
]);
$malformedMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($malformedMcpEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$mcpError = null;
ob_start();
try {
    controllerAliasIdentityMcpAliases($malformedMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $mcpError = $e->getMessage();
}
$mcpOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects missing goto without partial output',
    $mcpError === 'Alias goto cannot be null.' && $mcpOutput === '');

echo ControllerAliasIdentityState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . ControllerAliasIdentityState::$failures . " FAILED\n";
exit(ControllerAliasIdentityState::$failures === 0 ? 0 : 1);
