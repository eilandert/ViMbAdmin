<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/AliasPreference.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxTask.php';
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
    public bool $purged = false;

    public function __construct(private readonly ?\Entities\Mailbox $mailbox) {}

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        return $this->mailbox;
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<\Entities\Mailbox>
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->mailbox === null ? [] : [$this->mailbox];
    }

    /**
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->mailbox;
    }

    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function purgeMailbox($mailbox, $admin, $removeMailbox = true)
    {
        $domain = $mailbox->getDomain();
        if ($domain === null) {
            throw new LogicException('Mailbox domain cannot be null.');
        }

        $this->purged = true;
        $domain->decreaseMailboxCount();
        return true;
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

/** @param array<string,mixed> $options */
function controllerAliasIdentityContainer(
    EntityManager $entityManager,
    ControllerAliasIdentitySession $session,
    ControllerAliasIdentityView $view,
    array $options = [],
): Container {
    $admin = new ControllerAliasIdentityAdmin();
    return new Container(
        new ControllerAliasIdentityResources($entityManager, $session, $view, $options),
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

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpMailboxes(McpController $controller, array $params): array
{
    $method = new ReflectionMethod($controller, '_mailboxesList');
    $result = $method->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP mailboxes result is not an array');
    }
    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP mailboxes result has a non-string key');
        }
        $typed[$key] = $value;
    }
    return $typed;
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function controllerAliasIdentityMcpDelete(McpController $controller, array $params): array
{
    $result = (new ReflectionMethod($controller, '_mailboxDelete'))->invoke($controller, $params);
    if (!is_array($result)) {
        throw new RuntimeException('MCP mailbox delete result is not an array');
    }

    $typed = [];
    foreach ($result as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('MCP mailbox delete result has a non-string key');
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

$invalidMidController = new MailboxController(
    controllerAliasIdentityContainer($mailboxEntityManager, $mailboxSession, $mailboxView),
    new RouteMatch('mailbox', 'add', MailboxController::class, 'addAction', ['mid' => '7junk']),
);
$invalidMidResponse = $invalidMidController->addAction();
controllerAliasIdentityCheck('malformed add-with-mid fails closed before auth, lookup, or mutation',
    $invalidMidResponse->status === 302
        && ($invalidMidResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $mailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $mailboxSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

$orphanAlias = (new \Entities\Alias())
    ->setAddress('orphan@example.test')
    ->setGoto('user@example.test');
$orphanSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$orphanEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([$orphanAlias]),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$orphanController = new MailboxController(
    controllerAliasIdentityContainer($orphanEntityManager, $orphanSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'delete-alias', MailboxController::class, 'deleteAliasAction', [
        'mid' => '3',
        'alid' => '7',
        'csrf' => 'test-token',
    ]),
);
$orphanResponse = $orphanController->deleteAliasAction();
controllerAliasIdentityCheck('mailbox delete fails closed on a missing alias domain before mutation',
    $orphanResponse->status === 302
        && ($orphanResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $orphanAlias->getGoto() === 'user@example.test'
        && $domain->getAliasCount() === 4
        && $orphanEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $orphanEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []
        && $orphanSession->values() === ['identity' => ['id' => 1], 'csrfToken' => 'test-token']);

// A persisted mailbox without its required domain must never reach
// authorisation context construction or mutation.
$orphanMailbox = (new \Entities\Mailbox())->setUsername('orphan@example.test');
$wrongSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$wrongView = new ControllerAliasIdentityView();
$wrongEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($orphanMailbox),
]);
$wrongContainer = controllerAliasIdentityContainer($wrongEntityManager, $wrongSession, $wrongView);
$wrongActions = [
    'ajax-toggle-active' => ['ajaxToggleActiveAction', [], 'ko'],
    'purge' => ['purgeAction', ['csrf' => 'test-token'], '/mailbox/list'],
    'password' => ['passwordAction', [], '/mailbox/list'],
    'queue-repair' => ['queueRepairAction', ['csrf' => 'test-token'], '/mailbox/list'],
    'email-settings' => ['emailSettingsAction', [], 'error'],
];
foreach ($wrongActions as $route => [$method, $extra, $expected]) {
    $controller = new MailboxController(
        $wrongContainer,
        new RouteMatch('mailbox', $route, MailboxController::class, $method, ['mid' => '3'] + $extra),
    );
    $response = $controller->{$method}();
    $actual = $response->status === 302 ? ($response->headers['Location'] ?? null) : $response->body;
    controllerAliasIdentityCheck("{$route} rejects an orphan mailbox without writes",
        $actual === $expected
            && $wrongEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
            && $wrongEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);
}

$rememberedSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'domain' => new stdClass(),
]);
$rememberedEntityManager = controllerAliasIdentityEntityManager([]);
$rememberedController = new MailboxController(
    controllerAliasIdentityContainer($rememberedEntityManager, $rememberedSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'list', MailboxController::class, 'listAction', []),
);
$rememberedResponse = $rememberedController->listAction();
controllerAliasIdentityCheck('list removes malformed remembered domain before fail-closed redirect',
    $rememberedResponse->status === 302
        && ($rememberedResponse->headers['Location'] ?? null) === '/auth/login'
        && !$rememberedSession->has('domain'));

$invalidDidSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$invalidDidEntityManager = controllerAliasIdentityEntityManager([]);
$invalidDidController = new MailboxController(
    controllerAliasIdentityContainer($invalidDidEntityManager, $invalidDidSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'list', MailboxController::class, 'listAction', ['did' => '7junk']),
);
$invalidDidResponse = $invalidDidController->listAction();
controllerAliasIdentityCheck('present malformed list domain cannot widen into an unfiltered list',
    $invalidDidResponse->status === 302
        && ($invalidDidResponse->headers['Location'] ?? null) === '/auth/login'
        && $invalidDidEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []);

$oldGet = $_GET;
$_GET = ['sEcho' => ['2']];
$listDataController = new MailboxController(
    $wrongContainer,
    new RouteMatch('mailbox', 'list-data', MailboxController::class, 'listDataAction', []),
);
$listDataResponse = $listDataController->listDataAction();
$_GET = $oldGet;
controllerAliasIdentityCheck('DataTables container input returns ko before repository access',
    $listDataResponse->body === 'ko'
        && $wrongEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []);

$mailbox->setAltEmail(null);
$formSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$formEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$oldServer = $_SERVER;
$oldPost = $_POST;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'test-token', 'type' => ['username'], 'email' => ['attacker@example.test']];
$formController = new MailboxController(
    controllerAliasIdentityContainer($formEntityManager, $formSession, new ControllerAliasIdentityView()),
    new RouteMatch('mailbox', 'email-settings', MailboxController::class, 'emailSettingsAction', [
        'mid' => '3',
        'send' => '1',
    ]),
);
$formResponse = $formController->emailSettingsAction();
$_SERVER = $oldServer;
$_POST = $oldPost;
controllerAliasIdentityCheck('email settings container input safely re-renders without Array disclosure or writes',
    $formResponse->status === 200
        && !str_contains($formResponse->body, 'Array')
        && $formEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $formEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$badQueueSession = new ControllerAliasIdentitySession([
    'identity' => ['id' => 1],
    'csrfToken' => 'test-token',
]);
$badQueueEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($mailbox),
]);
$badQueueController = new MailboxController(
    controllerAliasIdentityContainer(
        $badQueueEntityManager,
        $badQueueSession,
        new ControllerAliasIdentityView(),
        ['queue' => ['runner' => ['key' => ['bad']]]],
    ),
    new RouteMatch('mailbox', 'queue-repair', MailboxController::class, 'queueRepairAction', [
        'mid' => '3',
        'csrf' => 'test-token',
    ]),
);
$badQueueResponse = $badQueueController->queueRepairAction();
controllerAliasIdentityCheck('malformed present queue key is rejected before enqueue or flush',
    $badQueueResponse->status === 302
        && ($badQueueResponse->headers['Location'] ?? null) === '/mailbox/list'
        && $badQueueEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $badQueueEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

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

$deleteDomain = (new \Entities\Domain())->setDomain('delete.example.test')->setMailboxCount(3);
$deleteMailbox = (new \Entities\Mailbox())
    ->setUsername('user@delete.example.test')
    ->setDomain($deleteDomain);
$deleteRepository = new ControllerAliasIdentityMailboxRepository($deleteMailbox);
$deleteEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => $deleteRepository,
]);
$deleteMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($deleteEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$deleteResult = controllerAliasIdentityMcpDelete($deleteMcp, ['username' => 'user@delete.example.test']);
controllerAliasIdentityCheck('MCP mailbox delete delegates the counter exactly once',
    $deleteResult === ['deleted' => true, 'username' => 'user@delete.example.test']
        && $deleteRepository->purged
        && $deleteDomain->getMailboxCount() === 2);

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

$unnamedDomain = new \Entities\Domain();
$unnamedDomainEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($unnamedDomain),
    'Entities\\Alias' => new ControllerAliasIdentityAliasRepository([]),
]);
$unnamedDomainMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($unnamedDomainEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$unnamedDomainError = null;
ob_start();
try {
    controllerAliasIdentityMcpAliases($unnamedDomainMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $unnamedDomainError = $e->getMessage();
}
$unnamedDomainOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects a null domain name without output or mutation',
    $unnamedDomainError === 'Domain name cannot be null.'
        && $unnamedDomainOutput === ''
        && $unnamedDomainEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $unnamedDomainEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$unnamedMailbox = (new \Entities\Mailbox())->setDomain($domain);
$unnamedMailboxEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Domain' => new ControllerAliasIdentityDomainRepository($domain),
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($unnamedMailbox),
]);
$unnamedMailboxMcp = new McpController(
    new Container(
        new ControllerAliasIdentityResources($unnamedMailboxEntityManager, $mcpSession, $mcpView),
        new Auth($mcpSession, static fn(int $id): null => null),
    ),
    new RouteMatch('mcp', 'index', McpController::class, 'indexAction', []),
);
$unnamedMailboxError = null;
ob_start();
try {
    controllerAliasIdentityMcpMailboxes($unnamedMailboxMcp, ['domain' => 'example.test']);
} catch (LogicException $e) {
    $unnamedMailboxError = $e->getMessage();
}
$unnamedMailboxOutput = ob_get_clean();
controllerAliasIdentityCheck('MCP rejects a null mailbox username without output or mutation',
    $unnamedMailboxError === 'Mailbox username cannot be null.'
        && $unnamedMailboxOutput === ''
        && $unnamedMailboxEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $unnamedMailboxEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

$emailSession = new ControllerAliasIdentitySession(['identity' => ['id' => 1]]);
$emailView = new ControllerAliasIdentityView();
$emailEntityManager = controllerAliasIdentityEntityManager([
    'Entities\\Mailbox' => new ControllerAliasIdentityMailboxRepository($unnamedMailbox),
]);
$emailController = new MailboxController(
    controllerAliasIdentityContainer($emailEntityManager, $emailSession, $emailView),
    new RouteMatch('mailbox', 'email-settings', MailboxController::class, 'emailSettingsAction', ['mid' => '3']),
);
$emailError = null;
try {
    $emailController->emailSettingsAction();
} catch (LogicException $e) {
    $emailError = $e->getMessage();
}
controllerAliasIdentityCheck('mail settings reject a null username before render or mutation',
    $emailError === 'Mailbox username cannot be null.'
        && $emailView->renders === 0
        && $emailEntityManager->getUnitOfWork()->getScheduledEntityInsertions() === []
        && $emailEntityManager->getUnitOfWork()->getScheduledEntityDeletions() === []);

echo ControllerAliasIdentityState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . ControllerAliasIdentityState::$failures . " FAILED\n";
exit(ControllerAliasIdentityState::$failures === 0 ? 0 : 1);
