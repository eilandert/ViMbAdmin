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

const AUTH_INPUT_TEST_CREDENTIAL = 'correct horse';

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
    public int $findOneByCalls = 0;
    public static ?\Entities\Admin $admin = null;
    public static int $count = 1;

    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object
    {
        $this->findCalls++;
        return self::$admin;
    }

    /** @param array<string,mixed> $criteria */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $this->findOneByCalls++;
        return self::$admin;
    }

    public function getCount(): int
    {
        return self::$count;
    }
}

final class AuthInputShapeAdmin extends \Entities\Admin
{
    /** @var array<string,string> */
    private array $testPreferences = [];
    private bool $deactivateDuringTwoFactorEnable = false;

    public function deactivateDuringTwoFactorEnable(): void
    {
        $this->deactivateDuringTwoFactorEnable = true;
    }

    public function getPreference($attribute, $index = 0, $includeExpired = false)
    {
        return $this->testPreferences[$attribute] ?? false;
    }

    public function setPreference($attribute, $value, $operator = '=', $expires = 0, $index = 0)
    {
        $this->testPreferences[$attribute] = $value;
        if ($this->deactivateDuringTwoFactorEnable && $attribute === \ViMbAdmin_TwoFactor::PREF_BACKUP) {
            $this->setActive(false);
        }
        return $this;
    }

    public function deletePreference($attribute, $index = null)
    {
        $removed = array_key_exists($attribute, $this->testPreferences) ? 1 : 0;
        unset($this->testPreferences[$attribute]);
        return $removed;
    }

    public function hasTestPreference(string $attribute): bool
    {
        return array_key_exists($attribute, $this->testPreferences);
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

/**
 * @param array<string,mixed>|null $options
 * @return array{controller:AuthController,session:AuthInputShapeSession,listener:AuthInputShapeFlushListener,repository:AuthInputShapeAdminRepository}
 */
function authInputShapeController(
    mixed $pendingId,
    ?\Entities\Admin $admin = null,
    string $action = 'totp',
    ?array $options = null,
    int $adminCount = 1,
): array
{
    AuthInputShapeAdminRepository::$admin = $admin
        ?? (new \Entities\Admin())
            ->setUsername('admin@example.test')
            ->setActive(true);
    AuthInputShapeAdminRepository::$count = $adminCount;
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
        'totp_pending_via' => 'auth',
    ]);
    $view = new AuthInputShapeView();
    $options ??= ['securitysalt' => str_repeat('s', 64)];
    $container = new Container(
        new AuthInputShapeBootstrap($entityManager, $session, $view, $options),
        new Auth($session, static fn(int $id): null => null),
    );

    $method = match ($action) {
        'login' => 'loginAction',
        'totp-setup' => 'totpSetupAction',
        default => 'totpAction',
    };

    return [
        'controller' => new AuthController(
            $container,
            new RouteMatch('auth', $action, AuthController::class, $method, []),
        ),
        'session' => $session,
        'listener' => $listener,
        'repository' => $repository,
    ];
}

/** @param array<string,string> $preferences */
function authInputShapeAdmin(bool $active, array $preferences = []): AuthInputShapeAdmin
{
    $passwordOptions = ['pwhash' => 'crypt:sha512'];
    $admin = new AuthInputShapeAdmin();
    $admin->setUsername('admin@example.test');
    $admin->setPassword(\OSS_Auth_Password::hash(AUTH_INPUT_TEST_CREDENTIAL, $passwordOptions));
    $admin->setSuper(true);
    $admin->setActive($active);
    (new ReflectionMethod($admin, 'assignGeneratedId'))->invoke($admin, 41);
    foreach ($preferences as $key => $value) {
        $admin->setPreference($key, $value);
    }

    return $admin;
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function authInputShapeLoginOptions(string $stateDirectory, array $extra = []): array
{
    return $extra + [
        'securitysalt' => str_repeat('s', 64),
        'resources' => ['auth' => ['oss' => ['pwhash' => 'crypt:sha512']]],
        'bruteforce' => [
            'enabled' => '1',
            'max_attempts' => '20',
            'statedir' => $stateDirectory,
        ],
    ];
}

function authInputShapeBruteForceAttempts(string $stateDirectory, string $ip): ?int
{
    $path = $stateDirectory . '/' . hash('sha256', $ip) . '.json';
    $contents = is_file($path) ? file_get_contents($path) : false;
    $state = is_string($contents) ? json_decode($contents, true) : null;

    return is_array($state) && is_int($state['attempts'] ?? null)
        ? $state['attempts']
        : null;
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

$testIp = '192.0.2.41';
$_SERVER['REMOTE_ADDR'] = $testIp;
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

$inactiveState = __DIR__ . '/../var/tmp/test-inactive-admin-' . getmypid();
$wrongState = __DIR__ . '/../var/tmp/test-wrong-admin-' . getmypid();

$inactive = authInputShapeController(
    null,
    authInputShapeAdmin(false, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState),
);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'csrf' => 'csrf-sentinel',
    'username' => 'admin@example.test',
    'password' => AUTH_INPUT_TEST_CREDENTIAL,
];
$inactiveResponse = $inactive['controller']->loginAction();
$inactiveMessages = $inactive['session']->get('flashMessages');

$wrongPassword = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($wrongState),
);
$_POST['password'] = 'wrong horse';
$wrongResponse = $wrongPassword['controller']->loginAction();
$wrongMessages = $wrongPassword['session']->get('flashMessages');

authInputShapeCheck('inactive correct credentials retain the generic login response',
    $inactiveResponse->status === 200
        && $inactiveResponse->body === $wrongResponse->body
        && $inactiveMessages === $wrongMessages
        && $inactiveMessages === [[
            'text' => 'Invalid username or password. Please try again.',
            'level' => 'error',
            'isHtml' => true,
        ]]);
authInputShapeCheck('inactive login retains failed-attempt accounting',
    authInputShapeBruteForceAttempts($inactiveState, $testIp) === 1
        && authInputShapeBruteForceAttempts($wrongState, $testIp) === 1);
authInputShapeCheck('inactive password login grants no identity or second-factor state',
    $inactive['session']->get('identity') === null
        && $inactive['session']->get('totp_pending_admin_id') === null
        && $inactive['listener']->flushes === 0);

$enabledInactiveAdmin = authInputShapeAdmin(false, [
    \ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret',
]);
$enabledInactive = authInputShapeController(
    null,
    $enabledInactiveAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$_POST['password'] = AUTH_INPUT_TEST_CREDENTIAL;
$enabledInactiveResponse = $enabledInactive['controller']->loginAction();
authInputShapeCheck('inactive enrolled-2FA admin is denied before a TOTP challenge',
    $enabledInactiveResponse->status === 200
        && $enabledInactive['session']->get('identity') === null
        && $enabledInactive['session']->get('totp_pending_admin_id') === null
        && $enabledInactiveAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_SECRET));

$forcedInactiveAdmin = authInputShapeAdmin(false, [
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$forcedInactive = authInputShapeController(
    null,
    $forcedInactiveAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$forcedInactiveResponse = $forcedInactive['controller']->loginAction();
authInputShapeCheck('inactive forced-2FA admin is denied before enrolment',
    $forcedInactiveResponse->status === 200
        && $forcedInactive['session']->get('identity') === null
        && $forcedInactive['session']->get('totp_pending_admin_id') === null
        && $forcedInactiveAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_FORCE));

$active = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$activeResponse = $active['controller']->loginAction();
$activeIdentity = $active['session']->get('identity');
authInputShapeCheck('active password login retains normal session establishment',
    $activeResponse->status === 302
        && ($activeResponse->headers['Location'] ?? null) === '/'
        && is_array($activeIdentity)
        && ($activeIdentity['id'] ?? null) === 41
        && $active['session']->get('logged_in_via') === 'auth'
        && $active['listener']->flushes === 1);

$enabledActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$enabledActiveResponse = $enabledActive['controller']->loginAction();
authInputShapeCheck('active enrolled-2FA login still parks for TOTP',
    $enabledActiveResponse->status === 302
        && ($enabledActiveResponse->headers['Location'] ?? null) === '/auth/totp'
        && $enabledActive['session']->get('identity') === null
        && $enabledActive['session']->get('totp_pending_admin_id') === 41
        && $enabledActive['session']->get('totp_pending_via') === 'auth');

$forcedActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_FORCE => '1']),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
);
$forcedActiveResponse = $forcedActive['controller']->loginAction();
authInputShapeCheck('active forced-2FA login still parks for enrolment',
    $forcedActiveResponse->status === 302
        && ($forcedActiveResponse->headers['Location'] ?? null) === '/auth/totp-setup'
        && $forcedActive['session']->get('identity') === null
        && $forcedActive['session']->get('totp_pending_admin_id') === 41);

$demoActive = authInputShapeController(
    null,
    authInputShapeAdmin(true, [\ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret']),
    'login',
    authInputShapeLoginOptions($inactiveState, [
        'bruteforce' => ['enabled' => '0'],
        'demo' => ['account' => 'admin@example.test', 'password' => AUTH_INPUT_TEST_CREDENTIAL],
    ]),
);
$demoActiveResponse = $demoActive['controller']->loginAction();
authInputShapeCheck('active demo login retains its configured 2FA bypass',
    $demoActiveResponse->status === 302
        && ($demoActiveResponse->headers['Location'] ?? null) === '/'
        && is_array($demoActive['session']->get('identity'))
        && $demoActive['session']->get('totp_pending_admin_id') === null);

$recoveryAdmin = authInputShapeAdmin(true, [
    \ViMbAdmin_TwoFactor::PREF_SECRET => 'encrypted-secret',
    \ViMbAdmin_TwoFactor::PREF_BACKUP => '[]',
    \ViMbAdmin_TwoFactor::PREF_LASTTS => '1',
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$recovery = authInputShapeController(
    null,
    $recoveryAdmin,
    'login',
    authInputShapeLoginOptions($inactiveState, [
        'bruteforce' => ['enabled' => '0'],
        'twofactor' => ['force_disable' => 'admin@example.test'],
    ]),
);
$recoveryResponse = $recovery['controller']->loginAction();
authInputShapeCheck('active force-disable recovery still clears 2FA and logs in',
    $recoveryResponse->status === 302
        && ($recoveryResponse->headers['Location'] ?? null) === '/'
        && is_array($recovery['session']->get('identity'))
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_SECRET)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_BACKUP)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_LASTTS)
        && !$recoveryAdmin->hasTestPreference(\ViMbAdmin_TwoFactor::PREF_FORCE)
        && $recovery['listener']->flushes === 2);

$firstRun = authInputShapeController(
    null,
    authInputShapeAdmin(true),
    'login',
    authInputShapeLoginOptions($inactiveState, ['bruteforce' => ['enabled' => '0']]),
    0,
);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$firstRunResponse = $firstRun['controller']->loginAction();
authInputShapeCheck('first-run login still redirects to setup before credential lookup',
    $firstRunResponse->status === 302
        && ($firstRunResponse->headers['Location'] ?? null) === '/auth/setup'
        && $firstRun['repository']->findOneByCalls === 0);

foreach (['totp', 'totp-setup'] as $pendingAction) {
    $pendingInactive = authInputShapeController(
        41,
        authInputShapeAdmin(false),
        $pendingAction,
    );
    $pendingInactive['session']->set('totp_setup_secret', 'pending-secret');
    $pendingInactive['session']->set('totp_verified', true);
    $pendingInactive['session']->set('postAuthRedirect', 'mailbox/list');
    $pendingResponse = $pendingAction === 'totp'
        ? $pendingInactive['controller']->totpAction()
        : $pendingInactive['controller']->totpSetupAction();
    authInputShapeCheck("inactive {$pendingAction} flow revokes pending authentication state",
        $pendingResponse->status === 302
            && ($pendingResponse->headers['Location'] ?? null) === '/auth/login'
            && $pendingInactive['session']->get('totp_pending_admin_id') === null
            && $pendingInactive['session']->get('totp_pending_via') === null
            && $pendingInactive['session']->get('totp_setup_secret') === null
            && $pendingInactive['session']->get('totp_verified') === null
            && $pendingInactive['session']->get('postAuthRedirect') === null
            && $pendingInactive['session']->get('flashMessages') === [[
                'text' => 'Invalid username or password. Please try again.',
                'level' => 'error',
                'isHtml' => true,
            ]]);
}

$deactivatedDuringSetupAdmin = authInputShapeAdmin(true, [
    \ViMbAdmin_TwoFactor::PREF_FORCE => '1',
]);
$deactivatedDuringSetupAdmin->deactivateDuringTwoFactorEnable();
$deactivatedDuringSetup = authInputShapeController(
    41,
    $deactivatedDuringSetupAdmin,
    'totp-setup',
);
$setupSecret = 'JBSWY3DPEHPK3PXP';
$deactivatedDuringSetup['session']->set('totp_setup_secret', $setupSecret);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'code' => (new \RobThree\Auth\TwoFactorAuth(
        new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(2, '#ffffff', '#000000', 'svg'),
        'ViMbAdmin',
    ))->getCode($setupSecret),
];
$deactivatedDuringSetupResponse = $deactivatedDuringSetup['controller']->totpSetupAction();
authInputShapeCheck('deactivation during TOTP enrolment revokes the pending login without rendering backup codes',
    $deactivatedDuringSetupResponse->status === 302
        && ($deactivatedDuringSetupResponse->headers['Location'] ?? null) === '/auth/login'
        && $deactivatedDuringSetup['session']->get('identity') === null
        && $deactivatedDuringSetup['session']->get('totp_pending_admin_id') === null
        && $deactivatedDuringSetup['session']->get('totp_pending_via') === null
        && $deactivatedDuringSetup['session']->get('totp_setup_secret') === null
        && !str_contains($deactivatedDuringSetupResponse->body, 'Two-factor is now enabled.'));

authInputShapeCheck('fixed assertion count', AuthInputShapeState::$checks === 20);

echo AuthInputShapeState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . AuthInputShapeState::$failures . " assertion(s) failed\n";
exit(AuthInputShapeState::$failures === 0 ? 0 : 1);
