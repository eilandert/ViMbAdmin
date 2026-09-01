<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Mailbox.php';
require_once __DIR__ . '/../application/Entities/MailboxPreference.php';
require_once __DIR__ . '/../application/Entities/Domain.php';
require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';
require_once __DIR__ . '/../application/Repositories/Mailbox.php';

use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class AuthMailboxPasswordSession implements SessionStorage
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = []) {}

    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function __get(string $key): mixed { return $this->get($key); }
    public function __set(string $key, mixed $value): void { $this->set($key, $value); }
    public function __isset(string $key): bool { return $this->has($key); }
    public function __unset(string $key): void { $this->remove($key); }
}

#[AllowDynamicProperties]
final class AuthMailboxPasswordView
{
    public function render(string $script): string { return 'rendered:' . $script; }
}

final class AuthMailboxPasswordRepository extends \Repositories\Mailbox
{
    public static ?\Entities\Mailbox $mailbox = null;

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return self::$mailbox;
    }
}

final class AuthMailboxPasswordFlushListener
{
    public int $flushes = 0;

    public function onFlush(): void
    {
        $this->flushes++;
    }
}

final class AuthMailboxPasswordBootstrap
{
    /** @param array<string, mixed> $options */
    public function __construct(
        private \Doctrine\ORM\EntityManager $entityManager,
        private AuthMailboxPasswordSession $session,
        private AuthMailboxPasswordView $view,
        private array $options,
    ) {}

    public function getResource(string $name): mixed
    {
        return match ($name) {
            'doctrine2' => $this->entityManager,
            'namespace' => $this->session,
            'smarty' => $this->view,
            default => throw new LogicException('Unexpected resource: ' . $name),
        };
    }

    /** @return array<string, mixed> */
    public function getOptions(): array { return $this->options; }
}

final class AuthMailboxPasswordState
{
    public static int $failures = 0;
}

function authMailboxPasswordCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { AuthMailboxPasswordState::$failures++; }
}

/**
 * @return array{mailbox:Entities\Mailbox,flushes:int,status:int,location:string|null,flashes:mixed}
 */
function authMailboxPasswordRun(?string $storedPassword, string $submittedPassword): array
{
    $options = ['defaults' => ['mailbox' => [
        'min_password_length' => 8,
        'password_scheme' => 'crypt:sha512',
    ]]];
    $session = new AuthMailboxPasswordSession(['csrfToken' => 'csrf-sentinel']);
    $mailbox = (new \Entities\Mailbox())->setUsername('user@example.test');
    if ($storedPassword !== null) {
        $mailbox->setPassword($storedPassword);
    }
    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = \Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new \Doctrine\ORM\EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Mailbox::class)
        ->setCustomRepositoryClass('\\AuthMailboxPasswordRepository');
    AuthMailboxPasswordRepository::$mailbox = $mailbox;
    $flushListener = new AuthMailboxPasswordFlushListener();
    $entityManager->getEventManager()->addEventListener([\Doctrine\ORM\Events::onFlush], $flushListener);
    $bootstrap = new AuthMailboxPasswordBootstrap(
        $entityManager,
        $session,
        new AuthMailboxPasswordView(),
        $options,
    );
    $controller = new AuthController(
        new Container($bootstrap, new Auth($session, static fn(int $id): ?object => null)),
        new RouteMatch('auth', 'change-password', AuthController::class, 'changePasswordAction', []),
    );

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'csrf' => 'csrf-sentinel',
        'username' => 'user@example.test',
        'current_password' => $submittedPassword,
        'new_password' => 'new-password-456',
        'confirm_new_password' => 'new-password-456',
    ];
    $response = $controller->changePasswordAction();

    return [
        'mailbox' => $mailbox,
        'flushes' => $flushListener->flushes,
        'status' => $response->status,
        'location' => $response->headers['Location'] ?? null,
        'flashes' => $session->get('flashMessages'),
    ];
}

echo "== mailbox password action boundary ==\n";

$options = ['pwhash' => 'crypt:sha512'];
$storedHash = \OSS_Auth_Password::hash('current-password-123', $options);
$wrong = authMailboxPasswordRun($storedHash, 'wrong-password-123');
$missing = authMailboxPasswordRun(null, 'current-password-123');
$correct = authMailboxPasswordRun($storedHash, 'current-password-123');

$generic = [[
    'text' => 'Invalid username or password.',
    'level' => 'error',
    'isHtml' => true,
]];
authMailboxPasswordCheck('wrong credential uses the generic failure',
    $wrong['status'] === 200 && $wrong['flashes'] === $generic);
authMailboxPasswordCheck('wrong credential performs no update or flush',
    $wrong['flushes'] === 0 && $wrong['mailbox']->requiredPassword() === $storedHash);
authMailboxPasswordCheck('null stored credential uses the identical generic failure',
    $missing['status'] === 200 && $missing['flashes'] === $generic);
authMailboxPasswordCheck('null stored credential performs no update or flush',
    $missing['flushes'] === 0 && $missing['mailbox']->getPassword() === null);
authMailboxPasswordCheck('correct credential alone updates and flushes once',
    $correct['status'] === 302 && $correct['flushes'] === 1
        && $correct['location'] === '/auth/change-password'
        && $correct['mailbox']->requiredPassword() !== $storedHash);
authMailboxPasswordCheck('successful update preserves configured hashing semantics',
    \OSS_Auth_Password::verify('new-password-456', $correct['mailbox']->requiredPassword(), $options));

echo AuthMailboxPasswordState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . AuthMailboxPasswordState::$failures . " FAILED\n";
exit(AuthMailboxPasswordState::$failures === 0 ? 0 : 1);
