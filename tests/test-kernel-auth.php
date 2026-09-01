<?php
/**
 * Unit test: ViMbAdmin\Kernel\Security\Auth (Phase 5, docs/ZF1-REMOVAL.md).
 * Pure logic over the SessionStorage port + an admin-loader callable — no
 * framework, no DB. Models the ZF1 identity (array with `id`) and the super flag.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Security/Auth.php';

use ViMbAdmin\Kernel\Session\SessionStorage;
use ViMbAdmin\Kernel\Security\Auth;

final class ArraySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

/** Stand-in for \Entities\Admin (only getSuper()/getId() are used). */
final class AdminFake
{
    public function __construct(private int $id, private bool $super) {}
    public function getId(): int { return $this->id; }
    public function getUsername(): string { return "u{$this->id}@x"; }
    public function getSuper(): bool { return $this->super; }
}

final class TestKernelAuthHarnessState
{
    public static int $count = 0;
}

$failures =& TestKernelAuthHarnessState::$count;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { TestKernelAuthHarnessState::$count++; }
}

/** Loader over a small id->admin table; records how many times it ran. */
function loaderFor(array $table, int &$calls): callable {
    return function (int $id) use ($table, &$calls): ?object { $calls++; return $table[$id] ?? null; };
}

echo "== ViMbAdmin\\Kernel\\Security\\Auth ==\n";

$normal = new AdminFake(5, false);
$super  = new AdminFake(9, true);

// --- authenticated normal admin --------------------------------------- //
$calls = 0;
$a = new Auth(new ArraySession(['identity' => ['id' => 5, 'username' => 'u@x']]), loaderFor([5 => $normal], $calls));
check('identity returns the array',     $a->identity() === ['id' => 5, 'username' => 'u@x']);
check('isAuthenticated true',           $a->isAuthenticated() === true);
check('admin() loads the entity',       $a->admin() === $normal);
check('admin() caches (1 load)',        ($a->admin() === $normal && $calls === 1));
check('isSuper false for normal',       $a->isSuper() === false);
check('isAuthorised() true',            $a->isAuthorised() === true);
check('isAuthorised(super) false',      $a->isAuthorised(true) === false);

// --- authenticated super admin ---------------------------------------- //
$calls = 0;
$s = new Auth(new ArraySession(['identity' => ['id' => 9]]), loaderFor([9 => $super], $calls));
check('super isSuper true',             $s->isSuper() === true);
check('super isAuthorised(super) true', $s->isAuthorised(true) === true);

// --- not authenticated (no identity) ---------------------------------- //
$calls = 0;
$g = new Auth(new ArraySession([]), loaderFor([5 => $normal], $calls));
check('no identity -> null',            $g->identity() === null);
check('not authenticated',              $g->isAuthenticated() === false);
check('admin() null, loader not called',$g->admin() === null && $calls === 0);
check('isSuper false',                  $g->isSuper() === false);
check('isAuthorised false',             $g->isAuthorised() === false);
check('isAuthorised(super) false',      $g->isAuthorised(true) === false);

// --- identity present but admin gone (stale session) ------------------ //
$calls = 0;
$x = new Auth(new ArraySession(['identity' => ['id' => 77]]), loaderFor([], $calls));
check('stale: authenticated by session',$x->isAuthenticated() === true);
check('stale: admin() null',            $x->admin() === null);
check('stale: isAuthorised false',      $x->isAuthorised() === false);

// --- malformed identity (no id) --------------------------------------- //
$m = new Auth(new ArraySession(['identity' => ['username' => 'no-id']]), loaderFor([5 => $normal], $calls));
check('no id -> not authenticated',     $m->isAuthenticated() === false);
$malformedCalls = 0;
$malformed = new Auth(new ArraySession(['identity' => 'not-an-array']), loaderFor([5 => $normal], $malformedCalls));
check('malformed identity -> null',      $malformed->identity() === null);
check('malformed identity is rejected before loading',
    $malformed->isAuthenticated() === false && $malformed->admin() === null && $malformedCalls === 0);

// --- repository returns the wrong object ------------------------------ //
$wrongCalls = 0;
$wrong = new Auth(new ArraySession(['identity' => ['id' => 5]]), loaderFor([5 => new stdClass()], $wrongCalls));
$wrongObjectRejected = false;
try {
    $wrong->admin();
} catch (Throwable $e) {
    $wrongObjectRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin has an invalid type';
}
check('wrong repository object fails at the authentication boundary',
    $wrongObjectRejected && $wrongCalls === 1);

// --- custom identity key ---------------------------------------------- //
$c = new Auth(new ArraySession(['Zend_Auth' => ['id' => 5]]), loaderFor([5 => $normal], $calls), 'Zend_Auth');
check('custom identity key honoured',   $c->isAuthenticated() && $c->admin() === $normal);

// --- establish() / clear() (login / logout) --------------------------- //
$sess = new ArraySession([]);
$e = new Auth($sess, loaderFor([5 => $normal], $calls));
check('anonymous before establish',     $e->isAuthenticated() === false && $e->admin() === null);
$e->establish($normal);
check('establish writes the identity',  $sess->get('identity') === ['username' => 'u5@x', 'user' => $normal, 'id' => 5]);
check('establish -> authenticated',     $e->isAuthenticated() === true);
check('establish -> admin() resolves',  $e->admin() === $normal);
$e->clear();
check('clear removes the identity',     $sess->get('identity') === null);
check('clear -> anonymous',             $e->isAuthenticated() === false && $e->admin() === null);

$invalidSession = new ArraySession([]);
$invalidEstablish = new Auth($invalidSession, loaderFor([], $calls));
$invalidEstablishRejected = false;
try {
    $invalidEstablish->establish(new stdClass());
} catch (Throwable $e) {
    $invalidEstablishRejected = $e instanceof LogicException
        && $e->getMessage() === 'Authenticated admin has an invalid type';
}
check('wrong login object fails before writing identity',
    $invalidEstablishRejected && $invalidSession->get('identity') === null);

echo "\n";
if ($failures === 0) {
    echo "OK: all Auth assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
