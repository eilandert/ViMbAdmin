<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}
require_once __DIR__ . '/../application/Repositories/Admin.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Controller\AuthController;
use ViMbAdmin\Kernel\RouteMatch;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class AuthInputShapeSession implements SessionStorage
{
    /** @param array<string,mixed> $data */
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
final class AuthInputShapeView
{
    /** @var array<string,mixed> */
    private array $values = [];
    public function __set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function render(string $script): string
    {
        $form = $this->values['formHtml'] ?? null;
        return is_string($form) ? $form : $script;
    }
}

final class AuthInputShapeAdminRepository extends \Repositories\Admin
{
    public int $findCalls = 0;
    public static ?\Entities\Admin $admin = null;

    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->findCalls++;
        return self::$admin;
    }
}

final class AuthInputShapeFlushListener
{
    public int $flushes = 0;
    public function onFlush(): void { $this->flushes++; }
}

final class AuthInputShapeBootstrap
{
    /** @param array<string,mixed> $options */
    public function __construct(
        private EntityManager $entityManager,
        private AuthInputShapeSession $session,
        private AuthInputShapeView $view,
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

    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }
}

final class AuthInputShapeState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function authInputShapeCheck(string $label, bool $condition): void
{
    AuthInputShapeState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) { AuthInputShapeState::$failures++; }
}

/** @return array{controller:AuthController,session:AuthInputShapeSession,listener:AuthInputShapeFlushListener,repository:AuthInputShapeAdminRepository} */
function authInputShapeController(mixed $pendingId): array
{
    AuthInputShapeAdminRepository::$admin = (new \Entities\Admin())->setUsername('admin@example.test');
    $configuration = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([
        __DIR__ . '/../application/Entities',
    ]);
    $configuration->enableNativeLazyObjects(true);
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0',
    ], $configuration);
    $entityManager = new EntityManager($connection, $configuration);
    $entityManager->getClassMetadata(\Entities\Admin::class)
        ->setCustomRepositoryClass('\\' . AuthInputShapeAdminRepository::class);
    $repository = $entityManager->getRepository(\Entities\Admin::class);
    if (!$repository instanceof AuthInputShapeAdminRepository) {
        throw new LogicException('Unexpected Admin repository type');
    }
    $listener = new AuthInputShapeFlushListener();
    $entityManager->getEventManager()->addEventListener([Events::onFlush], $listener);

    $session = new AuthInputShapeSession([
        'csrfToken' => 'csrf-sentinel',
        'totp_pending_admin_id' => $pendingId,
    ]);
    $view = new AuthInputShapeView();
    $options = ['securitysalt' => str_repeat('s', 64)];
    $container = new Container(
        new AuthInputShapeBootstrap($entityManager, $session, $view, $options),
        new Auth($session, static fn(int $id): null => null),
    );

    return [
        'controller' => new AuthController(
            $container,
            new RouteMatch('auth', 'totp', AuthController::class, 'totpAction', []),
        ),
        'session' => $session,
        'listener' => $listener,
        'repository' => $repository,
    ];
}

echo "== auth controller input shapes ==\n";

$malformedPending = authInputShapeController('1junk');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$response = $malformedPending['controller']->totpAction();
authInputShapeCheck('non-canonical pending admin id redirects to login',
    $response->status === 302 && ($response->headers['Location'] ?? null) === '/auth/login');
authInputShapeCheck('non-canonical pending id is cleared before repository lookup',
    $malformedPending['session']->get('totp_pending_admin_id') === null
        && $malformedPending['repository']->findCalls === 0);

$missingCsrf = authInputShapeController(1);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['code' => '123456'];
$response = $missingCsrf['controller']->totpAction();
authInputShapeCheck('TOTP POST rejects a missing CSRF token in the rendered form',
    $response->status === 200
        && str_contains($response->body, 'Invalid or missing security token.'));
authInputShapeCheck('missing CSRF reaches no TOTP mutation or flush',
    $missingCsrf['listener']->flushes === 0
        && $missingCsrf['session']->get('totp_pending_admin_id') === 1
        && $missingCsrf['repository']->findCalls === 1);

$containerCode = authInputShapeController('1');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['csrf' => 'csrf-sentinel', 'code' => ['123456']];
$response = $containerCode['controller']->totpAction();
authInputShapeCheck('TOTP form rejects a container-shaped authentication code',
    $response->status === 200 && str_contains($response->body, 'This field has an invalid type.'));
authInputShapeCheck('container-shaped code reaches no TOTP mutation or flush',
    $containerCode['listener']->flushes === 0
        && $containerCode['session']->get('totp_pending_admin_id') === '1'
        && $containerCode['repository']->findCalls === 1);

authInputShapeCheck('fixed assertion count', AuthInputShapeState::$checks === 6);

echo AuthInputShapeState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . AuthInputShapeState::$failures . " assertion(s) failed\n";
exit(AuthInputShapeState::$failures === 0 ? 0 : 1);
