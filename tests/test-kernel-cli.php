<?php
/**
 * Unit test: the native CLI dispatcher and MCP token-list command (WALL #2,
 * docs/ZF1-REMOVAL.md). No application resource or database is booted.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/McpToken.php';
require __DIR__ . '/../src/Kernel/Cli/CliCommand.php';

final class CliQueueRunnerState
{
    /** @var list<int|Throwable> */
    public static array $results = [];
    /** @var list<array{int,bool}> */
    public static array $drains = [];
    /** @var array<string,mixed> */
    public static array $options = [];
    public static ?Doctrine\Persistence\ObjectManager $entityManager = null;
}

final class ViMbAdmin_Service_QueueRunner
{
    /** @param array<string,mixed> $options */
    public function __construct(Doctrine\Persistence\ObjectManager $entityManager, array $options)
    {
        CliQueueRunnerState::$entityManager = $entityManager;
        CliQueueRunnerState::$options = $options;
    }

    public function drain(int $max, bool $verbose): int
    {
        CliQueueRunnerState::$drains[] = [$max, $verbose];
        $result = array_shift(CliQueueRunnerState::$results);
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_int($result)) {
            throw new LogicException('Queue command drained beyond the configured test results.');
        }
        return $result;
    }
}

foreach (glob(__DIR__ . '/../src/Kernel/Cli/Command/*.php') as $cmd) {
    require $cmd;
}
require __DIR__ . '/../src/Kernel/Cli/CliKernel.php';

// CliKernel pulls in Bootstrap via a `use` import only for run(); canHandle()
// touches none of it. Provide the class only if not already autoloaded.
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenListCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenRevokeCommand;
use ViMbAdmin\Kernel\Cli\CliKernel;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Security\Auth;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class CliTestSession implements SessionStorage
{
    public function has(string $key): bool { return false; }
    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value): void {}
    public function remove(string $key): void {}
}

/** @implements \Doctrine\Persistence\ObjectRepository<\Entities\McpToken> */
final class CliMcpTokenRepository implements \Doctrine\Persistence\ObjectRepository
{
    /** @var list<\Entities\McpToken> */
    private array $tokens;
    /** @var array<string,mixed>|null */
    public ?array $criteria = null;
    /** @var array<string,string>|null */
    public ?array $orderBy = null;
    public mixed $foundId = null;
    public ?string $foundName = null;
    public ?Throwable $error = null;

    /** @param list<\Entities\McpToken> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function find(mixed $id): ?object
    {
        $this->foundId = $id;
        if ($this->error !== null) { throw $this->error; }
        foreach ($this->tokens as $token) {
            if ($token->getId() === $id) { return $token; }
        }
        return null;
    }
    private function findByName(string $name): ?\Entities\McpToken
    {
        $this->foundName = $name;
        if ($this->error !== null) { throw $this->error; }
        foreach ($this->tokens as $token) {
            if ($token->getName() === $name) { return $token; }
        }
        return null;
    }
    /** @return list<\Entities\McpToken> */
    public function findAll(): array { return $this->tokens; }
    /** @return list<\Entities\McpToken> */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $this->criteria = $criteria;
        $this->orderBy = $orderBy;
        $tokens = $this->tokens;
        if ($orderBy === ['id' => 'ASC']) {
            usort($tokens, static fn(\Entities\McpToken $a, \Entities\McpToken $b): int => $a->getId() <=> $b->getId());
        }
        return $tokens;
    }
    public function findOneBy(array $criteria): ?object
    {
        $name = $criteria['name'] ?? null;
        return is_string($name) ? $this->findByName($name) : null;
    }
    public function getClassName(): string { return \Entities\McpToken::class; }
}

final class CliMcpObjectManager
{
    public int $flushes = 0;
    public function __construct(public CliMcpTokenRepository $repository) {}
    public function getRepository(string $className): CliMcpTokenRepository
    {
        if ($className !== '\\Entities\\McpToken') {
            throw new RuntimeException("unexpected repository {$className}");
        }
        return $this->repository;
    }
    public function flush(): void { $this->flushes++; }
}

final class CliTestResources
{
    /** @param array<string,mixed> $options */
    public function __construct(private object $entityManager, private array $options = []) {}
    /** @return array<string,mixed> */
    public function getOptions(): array { return $this->options; }
    public function getResource(string $name): object
    {
        if ($name !== 'doctrine2') {
            throw new RuntimeException("unexpected resource {$name}");
        }
        return $this->entityManager;
    }
}

/** @param array<string,mixed> $options */
function cliContainer(object $entityManager, array $options = []): Container
{
    return new Container(
        new CliTestResources($entityManager, $options),
        new Auth(new CliTestSession(), static fn(int $id): null => null),
    );
}

final class CliQueueObjectManager implements Doctrine\Persistence\ObjectManager
{
    public function find(string $className, mixed $id): ?object { return null; }
    public function persist(object $object): void {}
    public function remove(object $object): void {}
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function flush(): void {}
    public function getRepository(string $className): Doctrine\Persistence\ObjectRepository
    {
        throw new LogicException('Queue command test does not query repositories directly.');
    }
    public function getClassMetadata(string $className): Doctrine\Persistence\Mapping\ClassMetadata
    {
        throw new LogicException('Queue command test does not query metadata.');
    }
    public function getMetadataFactory(): Doctrine\Persistence\Mapping\ClassMetadataFactory
    {
        throw new LogicException('Queue command test does not query metadata.');
    }
    public function initializeObject(object $obj): void {}
    public function isUninitializedObject(mixed $value): bool { return false; }
    public function contains(object $object): bool { return false; }
}

/** @param list<int|Throwable> $results
 *  @param array<string,mixed> $options
 *  @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runQueueCommand(array $results, array $options = [], array $args = []): array
{
    CliQueueRunnerState::$results = $results;
    CliQueueRunnerState::$drains = [];
    CliQueueRunnerState::$options = [];
    CliQueueRunnerState::$entityManager = null;

    ob_start();
    try {
        $status = (new QueueRunCommand())->run(cliContainer(new CliQueueObjectManager(), $options), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function cliMcpToken(int $id, string $name): \Entities\McpToken
{
    $token = (new \Entities\McpToken())
        ->setName($name)
        ->setScope('read');
    $idProperty = new ReflectionProperty($token, 'id');
    $idProperty->setValue($token, $id);
    return $token;
}

/** @return array{int,string} */
function runMcpTokenList(object $entityManager): array
{
    ob_start();
    try {
        $status = (new McpTokenListCommand())->run(cliContainer($entityManager), []);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** @param array<string,mixed> $args
 *  @return array{int,string}
 */
function runMcpTokenRevoke(object $entityManager, array $args): array
{
    ob_start();
    try {
        $status = (new McpTokenRevokeCommand())->run(cliContainer($entityManager), $args);
        return [$status, (string) ob_get_clean()];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native CLI dispatcher ==\n";

$kernel = new CliKernel('/nonexistent', 'testing');

$expected = [
    'queue.cli-run',
    'admin.cli-reset-totp',
    'maintenance.cli-schema-update',
    'maintenance.cli-precompile-templates',
    'mcp.cli-token-generate',
    'mcp.cli-token-list',
    'mcp.cli-token-revoke',
];
foreach ($expected as $name) {
    check("canHandle({$name}) true", $kernel->canHandle($name));
}
check('canHandle(unknown) false',        !$kernel->canHandle('foo.bar'));
check('canHandle(empty) false',          !$kernel->canHandle(''));

$got = $kernel->commands();
sort($got);
$want = $expected;
sort($want);
check('commands() == registered set',    $got === $want);

$cmd = new QueueRunCommand();
check('QueueRunCommand name',            $cmd->name() === 'queue.cli-run');
check('CliCommand contract',             $cmd instanceof \ViMbAdmin\Kernel\Cli\CliCommand);

echo "== queue run command ==\n";

[$status, $output] = runQueueCommand([2, 1, 0]);
check('queue drains until empty and succeeds', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[5, false], [5, false], [5, false]]);
check('queue passes the validated object manager to the runner', CliQueueRunnerState::$entityManager instanceof CliQueueObjectManager);

$queueOptions = ['queue' => ['runner' => ['max_per_run' => 7]]];
[$status, $output] = runQueueCommand([3, 0], $queueOptions);
check('queue uses configured max_per_run and forwards options', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[7, false], [7, false]] && CliQueueRunnerState::$options === $queueOptions);

[$status, $output] = runQueueCommand([4, 3], [], ['once' => true]);
check('--once drains exactly one batch', $status === 0 && $output === '' && CliQueueRunnerState::$drains === [[5, false]]);

[$status, $output] = runQueueCommand([2, 1, 0], [], ['verbose' => true]);
check('verbose mode forwards the flag and prints the processed total', $status === 0 && $output === "Processed 3 task(s).\n" && CliQueueRunnerState::$drains === [[5, true], [5, true], [5, true]]);

[$status, $output] = runQueueCommand([-1], [], ['v' => true]);
check('lease throttling stops the loop and reports zero processed', $status === 0 && $output === "Processed 0 task(s).\n" && CliQueueRunnerState::$drains === [[5, true]]);

$queueErrorPropagated = false;
try {
    runQueueCommand([new RuntimeException('queue drain failed')]);
} catch (RuntimeException $e) {
    $queueErrorPropagated = $e->getMessage() === 'queue drain failed';
}
check('queue drain errors propagate', $queueErrorPropagated);

$queueBoundaryRejected = false;
try {
    (new QueueRunCommand())->run(cliContainer(new stdClass()), []);
} catch (LogicException $e) {
    $queueBoundaryRejected = $e->getMessage() === 'Queue command requires a Doctrine object manager.';
}
check('queue rejects non-Doctrine entity-manager resources locally', $queueBoundaryRejected);

echo "== MCP token list command ==\n";

$emptyRepository = new CliMcpTokenRepository([]);
[$status, $output] = runMcpTokenList(new CliMcpObjectManager($emptyRepository));
check('empty list succeeds', $status === 0);
check('empty list keeps its concise output', $output === "No MCP tokens.\n");
check('empty list queries all tokens in ascending id order', $emptyRepository->criteria === [] && $emptyRepository->orderBy === ['id' => 'ASC']);

$active = cliMcpToken(2, 'active-token');
$active->setAllowedIps('192.0.2.1')->setAllowedDomains('example.com')->setLastUsedAt(new DateTime('2026-08-31 12:34:56'));
$revoked = cliMcpToken(1, 'revoked-token')->setRevoked(true);
$expired = cliMcpToken(3, 'expired-token')->setExpiresAt(new DateTime('2000-01-01 00:00:00'));
$repository = new CliMcpTokenRepository([$expired, $active, $revoked]);
[$status, $output] = runMcpTokenList(new CliMcpObjectManager($repository));
check('populated list succeeds', $status === 0);
check('populated list retains its headings', str_starts_with($output, 'ID   NAME'));
check('tokens are emitted in ascending id order', strpos($output, 'revoked-token') < strpos($output, 'active-token') && strpos($output, 'active-token') < strpos($output, 'expired-token'));
check('revoked state wins and empty restrictions keep defaults', preg_match('/revoked-token\s+read\s+any\s+all\s+revoked\s+-/', $output) === 1);
check('active state and explicit restrictions are rendered', preg_match('/active-token\s+read\s+192\.0\.2\.1\s+example\.com\s+active\s+2026-08-31 12:34:56/', $output) === 1);
check('expired state is rendered', preg_match('/expired-token\s+read\s+any\s+all\s+expired\s+-/', $output) === 1);

$boundaryRejected = false;
try {
    runMcpTokenList(new stdClass());
} catch (LogicException $e) {
    $boundaryRejected = $e->getMessage() === 'MCP token listing requires a Doctrine object manager.';
}
check('non-Doctrine entity-manager resources fail at the local boundary', $boundaryRejected);

echo "== MCP token revoke command ==\n";

$byId = cliMcpToken(7, 'by-id');
$repository = new CliMcpTokenRepository([$byId, cliMcpToken(8, 'by-name')]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['id' => '7', 'name' => 'by-name']);
check('id lookup takes precedence over name and succeeds', $status === 0 && $repository->foundId === 7 && $repository->foundName === null);
check('id revocation is persisted once with stable output', $byId->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'by-id' (id 7).\n");

$byName = cliMcpToken(9, 'named-token');
$repository = new CliMcpTokenRepository([$byName]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['id' => '', 'name' => 'named-token']);
check('empty id falls back to name lookup', $status === 0 && $repository->foundId === null && $repository->foundName === 'named-token');
check('name revocation is persisted with stable output', $byName->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'named-token' (id 9).\n");

$alreadyRevoked = cliMcpToken(10, 'already-revoked')->setRevoked(true);
$repository = new CliMcpTokenRepository([$alreadyRevoked]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['name' => 'already-revoked']);
check('already-revoked tokens retain the successful persistence contract', $status === 0 && $alreadyRevoked->getRevoked() && $entityManager->flushes === 1 && $output === "Revoked MCP token 'already-revoked' (id 10).\n");

$repository = new CliMcpTokenRepository([]);
$entityManager = new CliMcpObjectManager($repository);
[$status, $output] = runMcpTokenRevoke($entityManager, ['name' => 'missing']);
check('missing token returns the documented error without flushing', $status === 1 && $entityManager->flushes === 0 && $output === "ERROR: token not found (use --name or --id; see mcp.cli-token-list)\n");

$repository = new CliMcpTokenRepository([]);
$repository->error = new RuntimeException('token lookup failed');
$lookupErrorPropagated = false;
try {
    runMcpTokenRevoke(new CliMcpObjectManager($repository), ['id' => '1']);
} catch (RuntimeException $e) {
    $lookupErrorPropagated = $e->getMessage() === 'token lookup failed';
}
check('repository lookup errors propagate', $lookupErrorPropagated);

$revokeBoundaryRejected = false;
try {
    runMcpTokenRevoke(new stdClass(), ['id' => '1']);
} catch (LogicException $e) {
    $revokeBoundaryRejected = $e->getMessage() === 'MCP token revocation requires a Doctrine object manager.';
}
check('revocation rejects non-Doctrine entity-manager resources locally', $revokeBoundaryRejected);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
