<?php
/**
 * Focused QueueRunner lease tests. The small EntityManager double models the
 * observable SQL operations without a database: stale reaping, active-count
 * slot checks, and the post-insert race back-off.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../application/Entities/QueueRunner.php';
require __DIR__ . '/../library/ViMbAdmin/Setting.php';
require __DIR__ . '/../library/ViMbAdmin/QueueRunner.php';

final class QueueRunnerLeaseQuery
{
    private ?int $maxResults = null;

    public function __construct(private QueueRunnerLeaseEntityManager $em) {}
    public function setParameter(string $name, mixed $value): self { return $this; }
    public function setMaxResults(int $max): self { $this->maxResults = $max; return $this; }
    public function getSingleScalarResult(): int { return count($this->em->leases); }

    /** @return list<array{id: int}> */
    public function getResult(): array
    {
        $ids = array_keys($this->em->leases);
        sort($ids, SORT_NUMERIC);
        return array_map(static fn(int $id): array => ['id' => $id], array_slice($ids, 0, $this->maxResults));
    }

    public function execute(): int
    {
        $stale = 0;
        foreach ($this->em->leases as $id => $lease) {
            $heartbeat = $lease->getHeartbeatAt();
            if ($heartbeat !== null && $heartbeat->getTimestamp() < time() - ViMbAdmin_QueueRunner::LEASE_TTL) {
                unset($this->em->leases[$id]);
                $stale++;
            }
        }
        return $stale;
    }
}

final class QueueRunnerLeaseEntityManager
{
    /** @var array<int, \Entities\QueueRunner> */
    public array $leases = [];
    public int $flushes = 0;
    public bool $contendOnPersist = false;
    private int $nextId = 1;

    public function createQuery(string $dql): QueueRunnerLeaseQuery { return new QueueRunnerLeaseQuery($this); }
    public function persist(object $lease): void
    {
        if ($this->contendOnPersist) {
            $this->contendOnPersist = false;
            $this->add(new \Entities\QueueRunner());
        }
        $this->add($lease);
    }
    public function remove(object $lease): void
    {
        if ($lease instanceof \Entities\QueueRunner && $lease->getId() !== null) {
            unset($this->leases[$lease->getId()]);
        }
    }
    public function flush(): void { $this->flushes++; }
    public function getConnection(): object { throw new RuntimeException('settings store intentionally absent'); }

    public function add(object $lease): void
    {
        if (!$lease instanceof \Entities\QueueRunner) { throw new RuntimeException('unexpected lease type'); }
        $property = new ReflectionProperty($lease, 'id');
        $property->setValue($lease, $this->nextId);
        $this->leases[$this->nextId++] = $lease;
    }
}

final class QueueRunnerLeaseAssertions { public static int $failures = 0; }
function queueRunnerCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { QueueRunnerLeaseAssertions::$failures++; }
}

/**
 * @param mixed $max
 * @return array{queue: array{runner: array{max_concurrent: mixed}}}
 */
function queueRunnerOptions(mixed $max): array { return ['queue' => ['runner' => ['max_concurrent' => $max]]]; }

/**
 * @param array{queue?: array{runner?: array{max_concurrent?: mixed, ...<string, mixed>}, ...<string, mixed>}, ...<string, mixed>} $options
 */
function queueRunnerCall(string $method, object $em, array $options): mixed
{
    return (new ReflectionMethod(ViMbAdmin_QueueRunner::class, $method))->invoke(null, $em, $options);
}

function queueRunnerRelease(object $em, \Entities\QueueRunner $lease): void
{
    (new ReflectionMethod(ViMbAdmin_QueueRunner::class, 'release'))->invoke(null, $em, $lease);
}

echo "== QueueRunner leases ==\n";

$available = new QueueRunnerLeaseEntityManager();
queueRunnerCheck('default option leaves an empty slot available', queueRunnerCall('slotAvailable', $available, []) === true);
$first = queueRunnerCall('acquireLease', $available, queueRunnerOptions('1'));
queueRunnerCheck('acquires a lease when a slot is available', $first instanceof \Entities\QueueRunner && count($available->leases) === 1);
queueRunnerCheck('full cap rejects a second runner before insertion', queueRunnerCall('acquireLease', $available, queueRunnerOptions(1)) === null && count($available->leases) === 1);
if (!$first instanceof \Entities\QueueRunner) { throw new RuntimeException('first lease was not acquired'); }
queueRunnerRelease($available, $first);
queueRunnerCheck('release frees the slot', queueRunnerCall('slotAvailable', $available, queueRunnerOptions(1)) === true && count($available->leases) === 0);

$boundary = new QueueRunnerLeaseEntityManager();
foreach ([0, -4, 'not-a-number', null, []] as $max) {
    queueRunnerCheck('malformed or boundary max remains clamped to one: ' . get_debug_type($max), queueRunnerCall('slotAvailable', $boundary, queueRunnerOptions($max)) === true);
    $lease = queueRunnerCall('acquireLease', $boundary, queueRunnerOptions($max));
    queueRunnerCheck('clamped cap still blocks a second lease: ' . get_debug_type($max), $lease instanceof \Entities\QueueRunner && queueRunnerCall('acquireLease', $boundary, queueRunnerOptions($max)) === null);
    if (!$lease instanceof \Entities\QueueRunner) { throw new RuntimeException('boundary lease was not acquired'); }
    queueRunnerRelease($boundary, $lease);
}

$stale = new QueueRunnerLeaseEntityManager();
$old = new \Entities\QueueRunner();
$old->setHeartbeatAt((new DateTime())->modify('-' . (ViMbAdmin_QueueRunner::LEASE_TTL + 1) . ' seconds'));
$stale->add($old);
queueRunnerCheck('stale lease is reaped before checking availability', queueRunnerCall('slotAvailable', $stale, queueRunnerOptions(1)) === true && count($stale->leases) === 0);

$contended = new QueueRunnerLeaseEntityManager();
$contended->contendOnPersist = true;
queueRunnerCheck('post-insert contention yields the newer lease', queueRunnerCall('acquireLease', $contended, queueRunnerOptions(1)) === null && count($contended->leases) === 1);
queueRunnerCheck('negative control retains the older contending lease', isset($contended->leases[1]));

$exitCode = QueueRunnerLeaseAssertions::$failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all QueueRunner lease assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: " . QueueRunnerLeaseAssertions::$failures . " assertion(s)\n";
exit($exitCode);
