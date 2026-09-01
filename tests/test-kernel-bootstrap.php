<?php
/**
 * Unit test: the framework-free bootstrap's pure pieces (WALL #2,
 * docs/ZF1-REMOVAL.md).
 *
 * Bootstrap::boot() itself needs a real DB + Smarty + session, so it is
 * exercised in the image when the native-bootstrap flag is wired. Here we cover
 * the parts that ARE pure: the base-URL derivation OSS_Utils::genUrl depends on,
 * and the NativeResources holder the Container reads its resources through.
 *
 * No framework, no DB. Exit 0 = pass, 1 = fail.
 */

require __DIR__ . '/../src/Kernel/NativeResources.php';
require __DIR__ . '/../src/Kernel/Config/IniConfig.php';
require __DIR__ . '/../src/Kernel/Doctrine/EntityManagerFactory.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';
require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Session/SessionNamespace.php';
require __DIR__ . '/../src/Kernel/View/SmartyView.php';
require __DIR__ . '/../src/Kernel/Bootstrap.php';

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\NativeResources;

final class BootstrapAdminRepository
{
    /** @param array<int,object> $admins */
    public function __construct(
        private array $admins,
        private ?Throwable $error = null,
        private mixed $invalid = null,
    ) {
    }

    public function find(int $id): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->invalid ?? ($this->admins[$id] ?? null);
    }
}

final class BootstrapObjectManager
{
    public ?string $requestedClass = null;

    public function __construct(private mixed $repository) {}

    public function getRepository(string $className): mixed
    {
        $this->requestedClass = $className;
        return $this->repository;
    }
}

final class TestKernelBootstrapHarnessState
{
    public static int $count = 0;
}

$failures =& TestKernelBootstrapHarnessState::$count;
function kernelBootstrapCheck(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { TestKernelBootstrapHarnessState::$count++; }
}

function bootstrapAdminLoader(object $manager): callable
{
    $loader = (new ReflectionMethod(Bootstrap::class, 'adminLoader'))->invoke(null, $manager);
    if (!is_callable($loader)) {
        throw new RuntimeException('bootstrap admin loader is not callable');
    }

    return $loader;
}

echo "== native bootstrap (pure pieces) ==\n";

// --- baseUrl() mirrors the ZF1 front controller's getBaseUrl() --------------
$_SERVER['SCRIPT_NAME'] = '/index.php';
kernelBootstrapCheck("docroot install yields '' base", Bootstrap::baseUrl() === '');

$_SERVER['SCRIPT_NAME'] = '/vimb/index.php';
kernelBootstrapCheck("sub-path install yields '/vimb'", Bootstrap::baseUrl() === '/vimb');

$_SERVER['SCRIPT_NAME'] = '/a/b/index.php';
kernelBootstrapCheck('nested sub-path preserved', Bootstrap::baseUrl() === '/a/b');

unset($_SERVER['SCRIPT_NAME']);
kernelBootstrapCheck('missing SCRIPT_NAME yields empty base', Bootstrap::baseUrl() === '');

// --- reverse-proxy sub-path: prefix is stripped before PHP, so SCRIPT_NAME
//     can't reveal it. Config (1) and X-Forwarded-Prefix (2) must win. --------
$_SERVER['SCRIPT_NAME'] = '/index.php';                       // proxy stripped /vimbadmin
$cfg = ['resources' => ['frontController' => ['baseUrl' => '/vimbadmin']]];  // ZF1 key casing (what the host ini uses)
kernelBootstrapCheck('config baseUrl overrides stripped SCRIPT_NAME', Bootstrap::baseUrl($cfg) === '/vimbadmin');
kernelBootstrapCheck('lowercase frontcontroller.baseurl also accepted', Bootstrap::baseUrl(['resources' => ['frontcontroller' => ['baseurl' => 'vimbadmin/']]]) === '/vimbadmin');

$_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/vimbadmin';
kernelBootstrapCheck('X-Forwarded-Prefix used when no config', Bootstrap::baseUrl() === '/vimbadmin');
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = "/evil\r\nSet-Cookie: x"; // header-injection attempt
kernelBootstrapCheck('malformed X-Forwarded-Prefix is rejected', Bootstrap::baseUrl() === '');
unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
kernelBootstrapCheck('config still wins over present SCRIPT_NAME dir', Bootstrap::baseUrl($cfg) === '/vimbadmin');

$malformedBaseUrlRejected = false;
try {
    Bootstrap::baseUrl(['resources' => ['frontController' => ['baseUrl' => ['nested']]]]);
} catch (LogicException $e) {
    $malformedBaseUrlRejected = $e->getMessage() === 'resources.frontController.baseUrl must be a string';
}
kernelBootstrapCheck('malformed configured base URL fails closed before URL construction', $malformedBaseUrlRejected);

$malformedSkinRejected = false;
try {
    (new ReflectionMethod(Bootstrap::class, 'skinCss'))->invoke(null, __DIR__ . '/../application', [
        'resources' => ['smarty' => ['skin' => ['nested']]],
    ]);
} catch (LogicException $e) {
    $malformedSkinRejected = $e->getMessage() === 'resources.smarty.skin must be a string';
}
kernelBootstrapCheck('malformed skin configuration fails closed before filesystem lookup', $malformedSkinRejected);

// --- NativeResources presents the Container's bootstrap shape ---------------
$em      = new stdClass();
$view    = new stdClass();
$session = new stdClass();
$options = ['resources' => ['smarty' => ['skin' => '']], 'footer' => ['hide' => '1']];

$res = new NativeResources($options, $em, $view, $session);
kernelBootstrapCheck('getResource(doctrine2) returns the EM', $res->getResource('doctrine2') === $em);
kernelBootstrapCheck('getResource(smarty) returns the view',  $res->getResource('smarty') === $view);
kernelBootstrapCheck('getResource(namespace) returns session', $res->getResource('namespace') === $session);
kernelBootstrapCheck('unknown resource returns null',          $res->getResource('mailer') === null);
kernelBootstrapCheck('getOptions returns the options array',   $res->getOptions() === $options);

// --- boot-time auth wiring validates the persistence boundary --------------
$admin = new stdClass();
$manager = new BootstrapObjectManager(new BootstrapAdminRepository([7 => $admin]));
$loader = bootstrapAdminLoader($manager);
kernelBootstrapCheck('admin loader requests the production entity class', $manager->requestedClass === '\\Entities\\Admin');
kernelBootstrapCheck('admin loader returns the requested admin', $loader(7) === $admin);
kernelBootstrapCheck('admin loader preserves a missing admin result', $loader(8) === null);

$wrongManagerRejected = false;
try {
    bootstrapAdminLoader(new stdClass());
} catch (LogicException $e) {
    $wrongManagerRejected = $e->getMessage() === 'Native bootstrap requires a Doctrine object manager.';
}
kernelBootstrapCheck('bootstrap rejects a missing object-manager API', $wrongManagerRejected);

$wrongRepositoryRejected = false;
try {
    bootstrapAdminLoader(new BootstrapObjectManager(new stdClass()));
} catch (LogicException $e) {
    $wrongRepositoryRejected = $e->getMessage() === 'Native bootstrap requires an admin repository.';
}
kernelBootstrapCheck('bootstrap rejects a missing admin-repository API', $wrongRepositoryRejected);

$repositoryErrorPropagated = false;
$repositoryError = new RuntimeException('admin lookup failed');
$loader = bootstrapAdminLoader(new BootstrapObjectManager(new BootstrapAdminRepository([], $repositoryError)));
try {
    $loader(7);
} catch (RuntimeException $e) {
    $repositoryErrorPropagated = $e === $repositoryError;
}
kernelBootstrapCheck('admin repository errors propagate unchanged', $repositoryErrorPropagated);

$invalidAdminRejected = false;
$loader = bootstrapAdminLoader(new BootstrapObjectManager(new BootstrapAdminRepository([], null, 'not-an-admin')));
try {
    $loader(7);
} catch (LogicException $e) {
    $invalidAdminRejected = $e->getMessage() === 'Admin repository returned an invalid value.';
}
kernelBootstrapCheck('bootstrap rejects a non-object admin result', $invalidAdminRejected);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
