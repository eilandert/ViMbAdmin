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
foreach (glob(__DIR__ . '/../src/Kernel/Cli/Command/*.php') as $cmd) {
    require $cmd;
}
require __DIR__ . '/../src/Kernel/Cli/CliKernel.php';

// CliKernel pulls in Bootstrap via a `use` import only for run(); canHandle()
// touches none of it. Provide the class only if not already autoloaded.
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenListCommand;
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

    /** @param list<\Entities\McpToken> $tokens */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function find(mixed $id): ?object { return null; }
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
    public function findOneBy(array $criteria): ?object { return null; }
    public function getClassName(): string { return \Entities\McpToken::class; }
}

final class CliMcpObjectManager
{
    public function __construct(public CliMcpTokenRepository $repository) {}
    public function getRepository(string $className): CliMcpTokenRepository
    {
        if ($className !== '\\Entities\\McpToken') {
            throw new RuntimeException("unexpected repository {$className}");
        }
        return $this->repository;
    }
}

final class CliTestResources
{
    public function __construct(private object $entityManager) {}
    /** @return array<string,mixed> */
    public function getOptions(): array { return []; }
    public function getResource(string $name): object
    {
        if ($name !== 'doctrine2') {
            throw new RuntimeException("unexpected resource {$name}");
        }
        return $this->entityManager;
    }
}

function cliContainer(object $entityManager): Container
{
    return new Container(
        new CliTestResources($entityManager),
        new Auth(new CliTestSession(), static fn(int $id): null => null),
    );
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

echo $failures === 0 ? "ALL PASSED\n" : "FAILED ($failures)\n";
exit($failures === 0 ? 0 : 1);
