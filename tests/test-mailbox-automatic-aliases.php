<?php
/**
 * Focused behaviour test for the MailboxAutomaticAliases plugin. It uses the
 * native mutation-context contracts and a small Doctrine double: no database
 * is needed to prove that configured aliases are persisted and domain aliases
 * suppress redundant automatic aliases.
 */

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/../application/' . $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/../library/OSS/Plugin/Observer.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MutationContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/AliasContext.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MailboxContext.php';
require __DIR__ . '/../application/plugins/MailboxAutomaticAliases.php';

if (!function_exists('_')) {
    function _(string $message): string { return $message; }
}

final class AutomaticAliasRepository extends \Repositories\Alias
{
    /** @var array<string, array{id: int|string, address: string, goto: string, active: bool, domain: string}> */
    public array $aliases = [];

    public function __construct() {}

    /**
     * @param bool $ima
     * @return array<int, array{id: int|string, address: string, goto: string, active: bool, domain: string}>
     */
    public function filterForAliasList($filter, $admin, $domain = null, $ima = false): array
    {
        return isset($this->aliases[$filter]) ? [$this->aliases[$filter]] : [];
    }
}

final class AutomaticAliasEntityManager extends \Doctrine\ORM\Decorator\EntityManagerDecorator
{
    /** @var list<object> */
    public array $persisted = [];
    public int $flushes = 0;

    public function __construct(private AutomaticAliasRepository $repository)
    {
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([], true);
        $config->enableNativeLazyObjects(true);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql'], $config);
        parent::__construct(new \Doctrine\ORM\EntityManager($connection, $config));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return \Doctrine\ORM\EntityRepository<T>
     */
    public function getRepository(string $class): \Doctrine\ORM\EntityRepository
    {
        if (ltrim($class, '\\') !== \Entities\Alias::class) {
            throw new \LogicException('The automatic-alias double only serves the Alias repository.');
        }

        /** @var \Doctrine\ORM\EntityRepository<T> $repository */
        $repository = $this->repositoryForAlias();
        return $repository;
    }

    private function repositoryForAlias(): mixed { return $this->repository; }
    public function persist(object $entity): void { $this->persisted[] = $entity; }
    public function flush(): void { $this->flushes++; }
}

final class AutomaticAliasMailboxContext implements ViMbAdmin_Plugin_MailboxContext
{
    /** @var list<mixed> */
    public array $messages = [];

    /** @param array<string, mixed> $options */
    public function __construct(
        private array $options,
        private AutomaticAliasEntityManager $entityManager,
        private \Entities\Admin $admin,
        private \Entities\Domain $domain,
        private \Entities\Mailbox $mailbox,
    ) {}

    /** @return array<string, mixed> */
    public function getOptions(): array { return $this->options; }
    public function getD2EM(): AutomaticAliasEntityManager { return $this->entityManager; }
    public function getAdmin(): \Entities\Admin { return $this->admin; }
    public function getDomain(): \Entities\Domain { return $this->domain; }
    public function getMailbox(): \Entities\Mailbox { return $this->mailbox; }
    public function addMessage(mixed $message, mixed $class = null, mixed $type = null): void { $this->messages[] = $message; }
}

final class AutomaticAliasAliasContext implements ViMbAdmin_Plugin_AliasContext
{
    /** @var list<mixed> */
    public array $messages = [];

    /** @param array<string, mixed> $options */
    public function __construct(
        private array $options,
        private AutomaticAliasEntityManager $entityManager,
        private \Entities\Admin $admin,
        private \Entities\Domain $domain,
        private \Entities\Alias $alias,
    ) {}

    /** @return array<string, mixed> */
    public function getOptions(): array { return $this->options; }
    public function getD2EM(): AutomaticAliasEntityManager { return $this->entityManager; }
    public function getAdmin(): \Entities\Admin { return $this->admin; }
    public function getDomain(): \Entities\Domain { return $this->domain; }
    public function getAlias(): \Entities\Alias { return $this->alias; }
    public function addMessage(mixed $message, mixed $class = null, mixed $type = null): void { $this->messages[] = $message; }
}

$failures = 0;
function checkAutomaticAlias(string $label, bool $ok): int {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    return $ok ? 0 : 1;
}

function automaticAliasThrows(string $message, \Closure $operation): bool {
    try {
        $operation();
    } catch (\LogicException $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
}

function automaticAliasDomain(bool $initialized = true, bool $named = true): \Entities\Domain {
    $domain = new \Entities\Domain();
    if ($named) {
        $domain->setDomain('example.test');
    }
    if ($initialized) {
        (new ReflectionMethod($domain, 'assignGeneratedId'))->invoke($domain, 17);
    }
    return $domain;
}

function makeContext(AutomaticAliasRepository $repository, bool $initialized = true, bool $named = true): AutomaticAliasMailboxContext {
    $domain = automaticAliasDomain($initialized, $named);
    $mailbox = (new \Entities\Mailbox())->setUsername('user@example.test');
    return new AutomaticAliasMailboxContext(
        ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
            'defaultAliases' => ['postmaster'],
            'defaultMapping' => ['postmaster' => 'root@example.test'],
        ]]],
        new AutomaticAliasEntityManager($repository),
        new \Entities\Admin(),
        $domain,
        $mailbox,
    );
}

function makeAliasContext(AutomaticAliasRepository $repository, \Entities\Alias $alias): AutomaticAliasAliasContext {
    $domain = automaticAliasDomain()->setAliasCount(0);
    return new AutomaticAliasAliasContext(
        ['vimbadmin_plugins' => ['MailboxAutomaticAliases' => [
            'defaultAliases' => ['postmaster'],
            'defaultMapping' => ['postmaster' => 'root@example.test'],
        ]]],
        new AutomaticAliasEntityManager($repository),
        new \Entities\Admin(),
        $domain,
        $alias,
    );
}

echo "== MailboxAutomaticAliases ==\n";

$repository = new AutomaticAliasRepository();
$context = makeContext($repository);
$plugin = new ViMbAdminPlugin_MailboxAutomaticAliases($context);
$plugin->mailbox_add_addPostflush($context, ['options' => []]);
$entityManager = $context->getD2EM();
$alias = $entityManager->persisted[0] ?? null;
$failures += checkAutomaticAlias('creates the configured automatic alias', $alias instanceof \Entities\Alias);
$failures += checkAutomaticAlias('uses the configured goto mapping', $alias instanceof \Entities\Alias && $alias->getGoto() === 'root@example.test');
$failures += checkAutomaticAlias('creates active aliases and flushes once', $alias instanceof \Entities\Alias && $alias->getActive() === true && $entityManager->flushes === 1);
$failures += checkAutomaticAlias('reports the created alias', count($context->messages) === 1);

$unpersistedContext = makeContext(new AutomaticAliasRepository(), false);
$failures += checkAutomaticAlias(
    'automatic alias lookup rejects a null domain id',
    automaticAliasThrows(
        'Domain id cannot be null.',
        static function () use ($unpersistedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unpersistedContext))
                ->mailbox_add_addPostflush($unpersistedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'null domain id fails before alias persistence or flush',
    $unpersistedContext->getD2EM()->persisted === []
        && $unpersistedContext->getD2EM()->flushes === 0
        && $unpersistedContext->messages === [],
);

$unnamedContext = makeContext(new AutomaticAliasRepository(), true, false);
$failures += checkAutomaticAlias(
    'automatic alias construction rejects a null domain name',
    automaticAliasThrows(
        'Domain name cannot be null.',
        static function () use ($unnamedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($unnamedContext))
                ->mailbox_add_addPostflush($unnamedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'null domain name fails before alias persistence or flush',
    $unnamedContext->getD2EM()->persisted === []
        && $unnamedContext->getD2EM()->flushes === 0
        && $unnamedContext->messages === [],
);

$repository = new AutomaticAliasRepository();
$sourceAlias = (new \Entities\Alias())
    ->setAddress('source@example.test')
    ->setGoto('destination@example.test');
$aliasContext = makeAliasContext($repository, $sourceAlias);
(new ViMbAdminPlugin_MailboxAutomaticAliases($aliasContext))->alias_add_addPostflush($aliasContext, ['options' => []]);
$createdFromAlias = $aliasContext->getD2EM()->persisted[0] ?? null;
$failures += checkAutomaticAlias(
    'alias add creates the configured automatic alias',
    $createdFromAlias instanceof \Entities\Alias
        && $createdFromAlias->getAddress() === 'postmaster@example.test'
        && $createdFromAlias->getGoto() === 'root@example.test',
);

$repository = new AutomaticAliasRepository();
$malformedAlias = (new \Entities\Alias())->setGoto('destination@example.test');
$malformedContext = makeAliasContext($repository, $malformedAlias);
$malformedDomainCount = $malformedContext->getDomain()->getAliasCount();
$failures += checkAutomaticAlias(
    'alias add rejects a null source address',
    automaticAliasThrows(
        'Alias address cannot be null.',
        static function () use ($malformedContext): void {
            (new ViMbAdminPlugin_MailboxAutomaticAliases($malformedContext))
                ->alias_add_addPostflush($malformedContext, ['options' => []]);
        },
    ),
);
$failures += checkAutomaticAlias(
    'alias add identity failure has no side effects',
    $malformedContext->getD2EM()->persisted === []
        && $malformedContext->getD2EM()->flushes === 0
        && $malformedContext->getDomain()->getAliasCount() === $malformedDomainCount
        && $malformedContext->messages === [],
);

$repository = new AutomaticAliasRepository();
$repository->aliases['@example.test'] = [
    'id' => 1,
    'address' => '@example.test',
    'goto' => 'catchall@example.test',
    'active' => true,
    'domain' => 'example.test',
];
$context = makeContext($repository);
(new ViMbAdminPlugin_MailboxAutomaticAliases($context))->mailbox_add_addPostflush($context, ['options' => []]);
$failures += checkAutomaticAlias('does not create aliases when an active domain alias exists', $context->getD2EM()->persisted === [] && $context->messages === []);

echo "\n";
if ($failures === 0) {
    echo "OK: all MailboxAutomaticAliases assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
