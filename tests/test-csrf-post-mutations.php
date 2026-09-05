<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Archive.php';
require_once __DIR__ . '/../application/Entities/Alias.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/Log.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Repositories/Admin.php';
require_once __DIR__ . '/../application/Repositories/Archive.php';
require_once __DIR__ . '/../application/Repositories/Alias.php';
require_once __DIR__ . '/../application/Repositories/Domain.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AdminController;
use ViMbAdmin\Kernel\Controller\AliasController;
use ViMbAdmin\Kernel\Controller\ArchiveController;
use ViMbAdmin\Kernel\Controller\DomainController;
use ViMbAdmin\Kernel\Controller\MailboxController;
use ViMbAdmin\Kernel\Http\Response;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class CsrfMutationSession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __set(string $key, mixed $value): void { $this->set($key, $value); }
    public function __isset(string $key): bool { return $this->has($key); }
    public function __unset(string $key): void { $this->remove($key); }
}

final class CsrfMutationAdmin extends Entities\Admin
{
    public function __construct(private readonly int $testId)
    {
        parent::__construct();
        $this->setUsername("admin{$testId}@example.test")->setActive(true)->setSuper(true);
    }

    public function getId(): int { return $this->testId; }
}

final class CsrfMutationAlias extends Entities\Alias
{
    public function __construct()
    {
        parent::__construct();
        $this->setAddress('sales@example.test')->setGoto('user@example.test')->setActive(true);
    }
}

final class CsrfMutationMailbox extends Entities\Mailbox
{
    public function __construct()
    {
        parent::__construct();
        $this->setUsername('user@example.test')->setActive(true);
    }
}

final class CsrfMutationAdminRepository extends Repositories\Admin
{
    public int $lookups = 0;
    public function __construct(private readonly ?Entities\Admin $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->lookups++;
        return $this->result;
    }
}

final class CsrfMutationDomainRepository extends Repositories\Domain
{
    public int $lookups = 0;
    public function __construct(private readonly ?Entities\Domain $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->lookups++;
        return $this->result;
    }
}

final class CsrfMutationAliasRepository extends Repositories\Alias
{
    public int $lookups = 0;
    public function __construct(private readonly ?Entities\Alias $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->lookups++;
        return $this->result;
    }
}

final class CsrfMutationMailboxRepository extends Repositories\Mailbox
{
    public int $lookups = 0;
    public function __construct(private readonly ?Entities\Mailbox $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->lookups++;
        return $this->result;
    }
}

final class CsrfMutationArchive extends Entities\Archive
{
    public function __construct()
    {
        $this->setUsername('user@example.test');
        $this->setStatus(\Entities\Archive::STATUS_ARCHIVED);
    }
}

final class CsrfMutationArchiveRepository extends Repositories\Archive
{
    public int $lookups = 0;
    public function __construct(private readonly ?Entities\Archive $result) {}
    /** @SuppressWarnings("PHPMD.UnusedFormalParameter") */
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->lookups++;
        return $this->result;
    }
}

final class CsrfMutationRepositoryFactory implements RepositoryFactory
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
            ?? throw new LogicException("Unexpected repository {$entityName}");
        /** @var EntityRepository<T> $repository */
        return $repository;
    }
}

final class CsrfMutationEntityManager extends EntityManagerDecorator
{
    public int $flushes = 0;
    /** @var list<object> */
    public array $persisted = [];

    /** @param array<string,EntityRepository<covariant object>> $repositories */
    public function __construct(array $repositories)
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([]);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setRepositoryFactory(new CsrfMutationRepositoryFactory($repositories));
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], $configuration);
        parent::__construct(new EntityManager($connection, $configuration));
    }

    /** @var list<object> */
    public array $removed = [];

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
}

final class CsrfMutationBootstrap
{
    public int $doctrineReads = 0;
    public function __construct(private readonly CsrfMutationEntityManager $em, private readonly CsrfMutationSession $session) {}
    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->doctrine(),
            'namespace' => $this->session,
            default => throw new LogicException("Unexpected resource {$name}"),
        };
    }
    /** @return array<string,mixed> */
    public function getOptions(): array { return []; }
    private function doctrine(): CsrfMutationEntityManager { $this->doctrineReads++; return $this->em; }
}

/**
 * @param class-string<\ViMbAdmin\Kernel\Controller\AdminController|\ViMbAdmin\Kernel\Controller\AliasController|\ViMbAdmin\Kernel\Controller\ArchiveController|\ViMbAdmin\Kernel\Controller\DomainController|\ViMbAdmin\Kernel\Controller\MailboxController> $controller
 * @param array<string,EntityRepository<covariant object>> $repositories
 * @param array<string,string> $params
 * @return array{AdminController|AliasController|ArchiveController|DomainController|MailboxController,CsrfMutationBootstrap,CsrfMutationEntityManager}
 */
function csrfMutationController(string $controller, string $action, array $repositories, array $params): array
{
    $session = new CsrfMutationSession(['identity' => ['id' => 1], 'csrfToken' => 'csrf-token']);
    $em = new CsrfMutationEntityManager($repositories);
    $bootstrap = new CsrfMutationBootstrap($em, $session);
    $actor = new CsrfMutationAdmin(1);
    $container = new Container($bootstrap, new Auth($session, static fn(int $id): object => $actor));
    $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action)))) . 'Action';
    return [new $controller($container, new RouteMatch('test', $action, $controller, $method, $params)), $bootstrap, $em];
}

function csrfMutationResponse(
    AdminController|AliasController|ArchiveController|DomainController|MailboxController $controller,
    string $action,
): Response {
    if ($controller instanceof AdminController) {
        return match ($action) {
            'remove-domain' => $controller->removeDomainAction(),
            'ajax-toggle-active' => $controller->ajaxToggleActiveAction(),
            'ajax-toggle-super' => $controller->ajaxToggleSuperAction(),
            'purge' => $controller->purgeAction(),
            default => throw new LogicException("Unexpected admin action {$action}"),
        };
    }
    if ($controller instanceof AliasController) {
        return match ($action) {
            'ajax-toggle-active' => $controller->ajaxToggleActiveAction(),
            'delete' => $controller->deleteAction(),
            default => throw new LogicException("Unexpected alias action {$action}"),
        };
    }
    if ($controller instanceof ArchiveController) {
        return match ($action) {
            'toggle-autoprune' => $controller->toggleAutopruneAction(),
            'delete' => $controller->deleteAction(),
            'restore' => $controller->restoreAction(),
            default => throw new LogicException("Unexpected archive action {$action}"),
        };
    }
    if ($controller instanceof DomainController) {
        return match ($action) {
            'ajax-toggle-active' => $controller->ajaxToggleActiveAction(),
            'purge' => $controller->purgeAction(),
            'remove-admin' => $controller->removeAdminAction(),
            default => throw new LogicException("Unexpected domain action {$action}"),
        };
    }
    return match ($action) {
        'ajax-toggle-active' => $controller->ajaxToggleActiveAction(),
        'delete-alias' => $controller->deleteAliasAction(),
        'queue-repair' => $controller->queueRepairAction(),
        'queue-archive' => $controller->queueArchiveAction(),
        'queue-delete' => $controller->queueDeleteAction(),
        default => throw new LogicException("Unexpected mailbox action {$action}"),
    };
}

/**
 * @param array<string,string> $post
 * @param array<string,string> $get
 */
function csrfMutationRequest(string $method, array $post = [], array $get = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    $_GET = $get;
}

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) { $failures++; }
};

echo "== POST CSRF mutation contract ==\n";

$surfaces = [
    'admin remove-domain' => [
        'class' => AdminController::class, 'action' => 'remove-domain', 'params' => ['aid' => '2', 'did' => '3'],
        'repositories' => static fn(): array => [
            'Entities\\Admin' => new CsrfMutationAdminRepository(new CsrfMutationAdmin(2)),
            'Entities\\Domain' => new CsrfMutationDomainRepository((new Entities\Domain())->setDomain('example.test')),
        ], 'body' => '',
    ],
    'admin toggle-active' => [
        'class' => AdminController::class, 'action' => 'ajax-toggle-active', 'params' => ['aid' => '2'],
        'repositories' => static fn(): array => ['Entities\\Admin' => new CsrfMutationAdminRepository(new CsrfMutationAdmin(2))], 'body' => 'ko',
    ],
    'admin toggle-super' => [
        'class' => AdminController::class, 'action' => 'ajax-toggle-super', 'params' => ['aid' => '2'],
        'repositories' => static fn(): array => ['Entities\\Admin' => new CsrfMutationAdminRepository(new CsrfMutationAdmin(2))], 'body' => 'ko',
    ],
    'alias toggle-active' => [
        'class' => AliasController::class, 'action' => 'ajax-toggle-active', 'params' => ['alid' => '2'],
        'repositories' => static function (): array {
            $domain = (new Entities\Domain())->setDomain('example.test');
            return ['Entities\\Alias' => new CsrfMutationAliasRepository((new CsrfMutationAlias())->setDomain($domain))];
        }, 'body' => 'ko',
    ],
    'mailbox toggle-active' => [
        'class' => MailboxController::class, 'action' => 'ajax-toggle-active', 'params' => ['mid' => '2'],
        'repositories' => static function (): array {
            $domain = (new Entities\Domain())->setDomain('example.test');
            return ['Entities\\Mailbox' => new CsrfMutationMailboxRepository((new CsrfMutationMailbox())->setDomain($domain))];
        }, 'body' => 'ko',
    ],
    'alias delete' => [
        'class' => AliasController::class, 'action' => 'delete', 'params' => ['alid' => '2'],
        'repositories' => static function (): array {
            $domain = (new Entities\Domain())->setDomain('example.test');
            return ['Entities\\Alias' => new CsrfMutationAliasRepository((new CsrfMutationAlias())->setDomain($domain))];
        }, 'body' => '',
    ],
    'admin purge' => [
        'class' => AdminController::class, 'action' => 'purge', 'params' => ['aid' => '2'],
        'repositories' => static fn(): array => [
            'Entities\\Admin' => new CsrfMutationAdminRepository(new CsrfMutationAdmin(2)),
        ], 'body' => '',
    ],
    'domain purge' => [
        'class' => DomainController::class, 'action' => 'purge', 'params' => ['did' => '3'],
        'repositories' => static fn(): array => [
            'Entities\\Domain' => new CsrfMutationDomainRepository((new Entities\Domain())->setDomain('example.test')),
        ], 'body' => '',
    ],
    'domain remove-admin' => [
        'class' => DomainController::class, 'action' => 'remove-admin', 'params' => ['aid' => '2', 'did' => '3'],
        'repositories' => static fn(): array => [
            'Entities\\Admin' => new CsrfMutationAdminRepository(new CsrfMutationAdmin(2)),
            'Entities\\Domain' => new CsrfMutationDomainRepository((new Entities\Domain())->setDomain('example.test')),
        ], 'body' => '',
    ],
    'domain toggle-active' => [
        'class' => DomainController::class, 'action' => 'ajax-toggle-active', 'params' => ['did' => '3'],
        'repositories' => static fn(): array => [
            'Entities\\Domain' => new CsrfMutationDomainRepository((new Entities\Domain())->setDomain('example.test')),
        ], 'body' => 'ko',
    ],
    'mailbox delete-alias' => [
        'class' => MailboxController::class, 'action' => 'delete-alias', 'params' => ['mid' => '2', 'alid' => '4'],
        'repositories' => static function (): array {
            $domain = (new Entities\Domain())->setDomain('example.test');
            return [
                'Entities\\Mailbox' => new CsrfMutationMailboxRepository((new CsrfMutationMailbox())->setDomain($domain)),
                'Entities\\Alias' => new CsrfMutationAliasRepository((new CsrfMutationAlias())->setDomain($domain)),
            ];
        }, 'body' => '',
    ],
    'archive toggle-autoprune' => [
        'class' => ArchiveController::class, 'action' => 'toggle-autoprune', 'params' => ['arid' => '5'],
        'repositories' => static fn(): array => ['Entities\\Archive' => new CsrfMutationArchiveRepository(new CsrfMutationArchive())], 'body' => '',
    ],
    'archive delete' => [
        'class' => ArchiveController::class, 'action' => 'delete', 'params' => ['arid' => '5'],
        'repositories' => static fn(): array => ['Entities\\Archive' => new CsrfMutationArchiveRepository(new CsrfMutationArchive())], 'body' => '',
    ],
    'archive restore' => [
        'class' => ArchiveController::class, 'action' => 'restore', 'params' => ['arid' => '5'],
        'repositories' => static fn(): array => ['Entities\\Archive' => new CsrfMutationArchiveRepository(new CsrfMutationArchive())], 'body' => '',
    ],
];

foreach ($surfaces as $label => $surface) {
    foreach ([
        'GET' => ['GET', [], [], []],
        'route-token GET' => ['GET', [], [], ['csrf' => 'csrf-token']],
        'query-token GET' => ['GET', [], ['csrf' => 'csrf-token'], []],
        'missing-token POST' => ['POST', [], [], []],
        'bad-token POST' => ['POST', ['csrf' => 'wrong'], [], []],
        'query-token POST' => ['POST', [], ['csrf' => 'csrf-token'], []],
    ] as $case => [$method, $post, $get, $routeParams]) {
        csrfMutationRequest($method, $post, $get);
        [$controller, $bootstrap, $em] = csrfMutationController($surface['class'], $surface['action'], ($surface['repositories'])(), array_merge($surface['params'], $routeParams));
        $response = csrfMutationResponse($controller, $surface['action']);
        $check("{$label}: {$case} is inert before lookup, service, or flush",
            $response->body === $surface['body'] && $bootstrap->doctrineReads === 0 && $em->flushes === 0);
    }
}

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'aid' => '2']);
$target = new CsrfMutationAdmin(2);
$adminRepository = new CsrfMutationAdminRepository($target);
[$controller, $bootstrap, $em] = csrfMutationController(AdminController::class, 'ajax-toggle-active', ['Entities\\Admin' => $adminRepository], []);
$response = csrfMutationResponse($controller, 'ajax-toggle-active');
$check('valid admin toggle POST performs exactly one authorized mutation',
    $response->body === 'ok' && $target->getActive() === false && $adminRepository->lookups === 1 && $em->flushes === 1);

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'aid' => '2']);
$target = new CsrfMutationAdmin(2);
$adminRepository = new CsrfMutationAdminRepository($target);
[$controller, $bootstrap, $em] = csrfMutationController(AdminController::class, 'ajax-toggle-super', ['Entities\\Admin' => $adminRepository], []);
$response = csrfMutationResponse($controller, 'ajax-toggle-super');
$check('valid admin super-toggle POST performs exactly one authorized mutation',
    $response->body === 'ok' && $target->getSuper() === false && $adminRepository->lookups === 1 && $em->flushes === 1);

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'alid' => '2']);
$domain = (new Entities\Domain())->setDomain('example.test');
$alias = (new CsrfMutationAlias())->setDomain($domain);
$aliasRepository = new CsrfMutationAliasRepository($alias);
[$controller, $bootstrap, $em] = csrfMutationController(AliasController::class, 'ajax-toggle-active', ['Entities\\Alias' => $aliasRepository], []);
$response = csrfMutationResponse($controller, 'ajax-toggle-active');
$check('valid alias toggle POST performs exactly one authorized mutation',
    $response->body === 'ok' && $alias->getActive() === false && $aliasRepository->lookups === 1 && $em->flushes === 1);

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'mid' => '2']);
$domain = (new Entities\Domain())->setDomain('example.test');
$mailbox = (new CsrfMutationMailbox())->setDomain($domain);
$mailboxRepository = new CsrfMutationMailboxRepository($mailbox);
[$controller, $bootstrap, $em] = csrfMutationController(MailboxController::class, 'ajax-toggle-active', ['Entities\\Mailbox' => $mailboxRepository], []);
$response = csrfMutationResponse($controller, 'ajax-toggle-active');
$check('valid mailbox toggle POST performs exactly one authorized mutation',
    $response->body === 'ok' && $mailbox->getActive() === false && $mailboxRepository->lookups === 1 && $em->flushes === 1);

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'aid' => '2', 'did' => '3']);
$target = new CsrfMutationAdmin(2);
$domain = (new Entities\Domain())->setDomain('example.test');
$target->addDomain($domain);
$adminRepository = new CsrfMutationAdminRepository($target);
$domainRepository = new CsrfMutationDomainRepository($domain);
[$controller, $bootstrap, $em] = csrfMutationController(AdminController::class, 'remove-domain', [
    'Entities\\Admin' => $adminRepository, 'Entities\\Domain' => $domainRepository,
], []);
$response = csrfMutationResponse($controller, 'remove-domain');
$check('valid domain-removal POST performs exactly one authorized mutation',
    $response->status === 302 && !$target->getDomains()->contains($domain)
        && $adminRepository->lookups === 1 && $domainRepository->lookups === 1 && $em->flushes === 1);

// --- positive controls: a valid body token DOES mutate -----------------------

csrfMutationRequest('POST', ['csrf' => 'csrf-token', 'alid' => '2']);
$domain = (new Entities\Domain())->setDomain('example.test');
$alias = (new CsrfMutationAlias())->setDomain($domain);
$aliasRepository = new CsrfMutationAliasRepository($alias);
[$controller, $bootstrap, $em] = csrfMutationController(AliasController::class, 'delete', ['Entities\\Alias' => $aliasRepository], []);
$response = csrfMutationResponse($controller, 'delete');
$check('valid alias-delete POST reaches the authorized mutation',
    $response->status === 302 && $aliasRepository->lookups === 1
        && in_array($alias, $em->removed, true) && $em->flushes >= 1);

// --- view layer: every converted control is a CSRF-bearing POST form ---------

$adminJs = file_get_contents(__DIR__ . '/../application/views/admin/js/list.js');
$aliasJs = file_get_contents(__DIR__ . '/../application/views/alias/js/list.js');
$mailboxJs = file_get_contents(__DIR__ . '/../application/views/mailbox/js/list.js');
$domainsTemplate = file_get_contents(__DIR__ . '/../application/views/admin/domains.phtml');
$domainsJs = file_get_contents(__DIR__ . '/../application/views/admin/js/domains.js');
$check('administrator toggles submit the session token in their POST data',
    is_string($adminJs) && substr_count($adminJs, '"csrf": "{$csrfToken}"') === 2);
$check('alias and mailbox toggles submit the session token in their POST data',
    is_string($aliasJs) && is_string($mailboxJs)
        && str_contains($aliasJs, '"csrf": "{$csrfToken}"')
        && str_contains($mailboxJs, '"csrf": "{$csrfToken}"'));
$check('domain removal uses a CSRF-bearing POST form instead of a confirmation link',
    is_string($domainsTemplate) && is_string($domainsJs)
        && str_contains($domainsTemplate, 'id="remove_domain_form" method="post"')
        && str_contains($domainsTemplate, 'name="csrf" value="{$csrfToken|escape}"')
        && str_contains($domainsJs, "#remove_domain_form input[name=\"did\"]"));

$aliasList     = file_get_contents(__DIR__ . '/../application/views/alias/list.phtml');
$adminList     = file_get_contents(__DIR__ . '/../application/views/admin/list.phtml');
$domainAdmins  = file_get_contents(__DIR__ . '/../application/views/domain/admins.phtml');
$domainList    = file_get_contents(__DIR__ . '/../application/views/domain/list.phtml');
$domainListJs  = file_get_contents(__DIR__ . '/../application/views/domain/js/list.js');
$mailboxList   = file_get_contents(__DIR__ . '/../application/views/mailbox/list.phtml');
$mailboxAlias  = file_get_contents(__DIR__ . '/../application/views/mailbox/aliases.phtml');
$mailboxPurge  = file_get_contents(__DIR__ . '/../application/views/mailbox/purge.phtml');
$archiveList   = file_get_contents(__DIR__ . '/../application/views/archive/list.phtml');
$archiveJs     = file_get_contents(__DIR__ . '/../application/views/archive/js/list.js');

$convertedViews = [
    'alias list'        => [$aliasList, 'delete-alias-form', 1],
    'alias list js'     => [$aliasJs, 'delete-alias-form', 1],
    'admin list'        => [$adminList, 'purge-admin-form', 1],
    'domain admins'     => [$domainAdmins, 'remove-admin-form', 1],
    'mailbox aliases'   => [$mailboxAlias, 'delete-alias-form', 1],
    'mailbox list'      => [$mailboxList, 'queue-task-form', 3],
    'mailbox list js'   => [$mailboxJs, 'queue-task-form', 3],
    'archive list'      => [$archiveList, 'archive-action-form', 4],
    'archive list js'   => [$archiveJs, 'archive-action-form', 4],
];
foreach ($convertedViews as $label => [$contents, $marker, $expected]) {
    $check("{$label}: destructive controls are POST forms carrying the body token",
        is_string($contents)
            && substr_count($contents, $marker) === $expected
            && substr_count($contents, 'name="csrf"') >= $expected
            && !str_contains($contents, 'csrf=$csrfToken')
            && !str_contains($contents, '/csrf/{$csrfToken}'));
}

$check('domain purge is a CSRF-bearing POST form driven by the confirm dialog',
    is_string($domainList) && is_string($domainListJs)
        && str_contains($domainList, 'id="purge_domain_form" method="post"')
        && str_contains($domainList, 'name="csrf" value="{$csrfToken|escape}"')
        && str_contains($domainListJs, '#purge_domain_form input[name="did"]')
        && !str_contains($domainListJs, "attr( 'href'"));

$check('mailbox purge confirmation form submits the token in its body',
    is_string($mailboxPurge)
        && str_contains($mailboxPurge, 'name="csrf" value="{$csrfToken|escape}"'));

// No controller may still accept a URL-borne token on a destructive action.
foreach ([
    'AliasController'   => ['deleteAction'],
    'AdminController'   => ['purgeAction'],
    'ArchiveController' => ['toggleAutopruneAction', 'deleteAction', 'restoreAction'],
    'DomainController'  => ['purgeAction', 'removeAdminAction', 'ajaxToggleActiveAction'],
    'MailboxController' => ['deleteAliasAction', 'queueMailboxTask'],
] as $class => $methods) {
    $source = file_get_contents(__DIR__ . "/../src/Kernel/Controller/{$class}.php");
    foreach ($methods as $method) {
        $check("{$class}::{$method} guards on the POST body token",
            is_string($source)
                && preg_match(
                    '/function ' . preg_quote($method, '/') . '\(.*?postBodyCsrfValid\(\)/s',
                    $source,
                ) === 1);
    }
}

// The confirm dialogs must hand the form off, not an href.
foreach ([
    'alias/js/list.js', 'mailbox/js/aliases.js', 'domain/js/admins.js', 'admin/js/list.js',
] as $script) {
    $contents = file_get_contents(__DIR__ . '/../application/views/' . $script);
    $check("{$script}: the confirm dialog submits the POST form rather than following a link",
        is_string($contents)
            && str_contains($contents, "element.closest( 'form' )")
            && str_contains($contents, "targetForm.get( 0 ).submit()")
            && !preg_match('/purge_dialog_delete.*attr\(\s*[\'"]href/', $contents));
}

$check('fixed assertion count', $checks === 118);
echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
