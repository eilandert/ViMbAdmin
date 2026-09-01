<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/Alias.php';
require __DIR__ . '/../application/Entities/Domain.php';
require __DIR__ . '/../application/Entities/Mailbox.php';
require __DIR__ . '/../application/Entities/MailboxPreference.php';
require __DIR__ . '/../application/Repositories/Alias.php';
require __DIR__ . '/../application/Repositories/Mailbox.php';

final class MailboxAliasIdentityState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

final class MailboxAliasIdentityAliasRepository extends \Repositories\Alias
{
    /** @var list<\Entities\Alias> */
    public array $forMailbox = [];
    /** @var list<\Entities\Alias> */
    public array $withMailbox = [];

    public function __construct() {}

    /** @return list<\Entities\Alias> */
    public function loadForMailbox($mailbox, $admin, $ima = false): array
    {
        return $this->forMailbox;
    }

    /** @return list<\Entities\Alias> */
    public function loadWithMailbox($mailbox, $admin): array
    {
        return $this->withMailbox;
    }
}

final class MailboxAliasIdentityEntityManager extends \Doctrine\ORM\Decorator\EntityManagerDecorator
{
    /** @var list<object> */
    public array $removed = [];

    public function __construct(private MailboxAliasIdentityAliasRepository $aliasRepository)
    {
        $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([], true);
        $configuration->enableNativeLazyObjects(true);
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql'], $configuration);
        parent::__construct(new \Doctrine\ORM\EntityManager($connection, $configuration));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return \Doctrine\ORM\EntityRepository<T>
     */
    public function getRepository(string $class): \Doctrine\ORM\EntityRepository
    {
        if (ltrim($class, '\\') !== \Entities\Alias::class) {
            throw new \LogicException('The mailbox identity double only serves the Alias repository.');
        }

        /** @var \Doctrine\ORM\EntityRepository<T> $repository */
        $repository = $this->aliasRepositoryForReturn();
        return $repository;
    }

    private function aliasRepositoryForReturn(): mixed
    {
        return $this->aliasRepository;
    }

    public function remove(object $object): void
    {
        $this->removed[] = $object;
    }
}

function mailboxAliasIdentityCheck(string $label, bool $condition): void
{
    MailboxAliasIdentityState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        MailboxAliasIdentityState::$failures++;
    }
}

function mailboxAliasIdentityThrows(string $message, \Closure $operation): bool
{
    try {
        $operation();
    } catch (\LogicException $exception) {
        return $exception->getMessage() === $message;
    }

    return false;
}

function mailboxAliasIdentityRepository(
    MailboxAliasIdentityEntityManager $entityManager,
): \Repositories\Mailbox {
    $metadata = new \Doctrine\ORM\Mapping\ClassMetadata(\Entities\Mailbox::class);
    return new \Repositories\Mailbox($entityManager, $metadata);
}

echo "== mailbox repository alias identity ==\n";

$domain = (new \Entities\Domain())
    ->setDomain('example.test')
    ->setAliasCount(5)
    ->setMailboxCount(2);
$mailbox = (new \Entities\Mailbox())
    ->setUsername('user@example.test')
    ->setDomain($domain);
$directAlias = (new \Entities\Alias())
    ->setAddress('user@example.test')
    ->setGoto('user@example.test')
    ->setDomain($domain);
$multiAlias = (new \Entities\Alias())
    ->setAddress('team@example.test')
    ->setGoto('user@example.test,other@example.test')
    ->setDomain($domain);
$aliasRepository = new MailboxAliasIdentityAliasRepository();
$aliasRepository->forMailbox = [$directAlias];
$aliasRepository->withMailbox = [$multiAlias];
$entityManager = new MailboxAliasIdentityEntityManager($aliasRepository);
$result = mailboxAliasIdentityRepository($entityManager)->purgeMailbox($mailbox, null, false);
mailboxAliasIdentityCheck('valid purge succeeds', $result);
mailboxAliasIdentityCheck(
    'valid purge removes the direct alias and preserves the mailbox',
    $entityManager->removed === [$directAlias],
);
mailboxAliasIdentityCheck('valid purge removes only the requested destination', $multiAlias->getGoto() === 'other@example.test');
mailboxAliasIdentityCheck(
    'valid purge preserves identity-based counters',
    $domain->getAliasCount() === 5 && $domain->getMailboxCount() === 1,
);

$malformedDomain = (new \Entities\Domain())
    ->setDomain('malformed.example')
    ->setAliasCount(7)
    ->setMailboxCount(3);
$malformedMailbox = (new \Entities\Mailbox())
    ->setUsername('user@malformed.example')
    ->setDomain($malformedDomain);
$mailboxPreference = new \Entities\MailboxPreference();
$malformedMailbox->addPreference($mailboxPreference);
$malformedAlias = (new \Entities\Alias())
    ->setAddress('team@malformed.example')
    ->setDomain($malformedDomain);
$malformedAliasRepository = new MailboxAliasIdentityAliasRepository();
$malformedAliasRepository->withMailbox = [$malformedAlias];
$malformedEntityManager = new MailboxAliasIdentityEntityManager($malformedAliasRepository);
$malformedRepository = mailboxAliasIdentityRepository($malformedEntityManager);
mailboxAliasIdentityCheck(
    'purge rejects a null alias goto',
    mailboxAliasIdentityThrows(
        'Alias goto cannot be null.',
        static fn (): mixed => $malformedRepository->purgeMailbox($malformedMailbox, null, false),
    ),
);
mailboxAliasIdentityCheck(
    'purge identity failure occurs before every side effect',
    $malformedEntityManager->removed === []
        && $malformedMailbox->getPreferences()->contains($mailboxPreference)
        && $malformedDomain->getAliasCount() === 7
        && $malformedDomain->getMailboxCount() === 3
        && $malformedAlias->getGoto() === null,
);

mailboxAliasIdentityCheck('fixed assertion count', MailboxAliasIdentityState::$checks === 6);

echo MailboxAliasIdentityState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . MailboxAliasIdentityState::$failures . " assertion(s) failed\n";
exit(MailboxAliasIdentityState::$failures === 0 ? 0 : 1);
