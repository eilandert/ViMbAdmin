<?php

declare(strict_types=1);

/**
 * MariaDB integration regression for atomic mailbox-task deduplication.
 *
 * Two independent transactions enqueue the same open task. The first keeps
 * its insert uncommitted while the second attempts its insert, proving the
 * database constraint serializes the race rather than a process-local guard.
 */

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use ViMbAdmin\Kernel\Doctrine\EntityManagerFactory;
use ViMbAdmin\Kernel\Doctrine\Type\LegacyObjectType;

$applicationDir = __DIR__ . '/../application';
EntityManagerFactory::registerEntityAutoloaders(['resources' => ['doctrine2' => [
    'models_path' => $applicationDir,
    'repositories_path' => $applicationDir,
]]]);

function mailboxQueueEntityManager(): EntityManager
{
    $entityDir = realpath(__DIR__ . '/../application/Entities');
    if ($entityDir === false) {
        throw new RuntimeException('application/Entities directory not found');
    }

    $config = ORMSetup::createAttributeMetadataConfiguration([$entityDir], true);
    $config->enableNativeLazyObjects(true);
    if (!Type::hasType(LegacyObjectType::NAME)) {
        Type::addType(LegacyObjectType::NAME, LegacyObjectType::class);
    }

    $connection = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'dbname' => getenv('DB_NAME') ?: 'vimbadmin',
        'user' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8',
    ], $config);

    return new EntityManager($connection, $config);
}

function mailboxQueueFixture(string $username): \Entities\Mailbox
{
    $mailbox = new \Entities\Mailbox();
    $mailbox->setUsername($username);
    return $mailbox;
}

function waitForPath(string $path, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('timed out waiting for synchronization path: ' . basename($path));
        }
        usleep(10_000);
    }
}

function runMailboxQueueWorker(string $role, string $syncDir): int
{
    $em = mailboxQueueEntityManager();
    $connection = $em->getConnection();
    $connection->beginTransaction();

    try {
        if ($role === 'B') {
            $connectionId = mailboxQueueRequiredCount($connection->fetchOne('SELECT CONNECTION_ID()'));
            if ($connectionId === 0) {
                throw new UnexpectedValueException('MariaDB returned an invalid connection identifier');
            }
            $temporaryPath = $syncDir . '/b-connection.tmp';
            if (file_put_contents($temporaryPath, (string) $connectionId) === false
                || !rename($temporaryPath, $syncDir . '/b-connection')) {
                throw new RuntimeException('failed to publish the MariaDB connection identifier');
            }
        }

        $task = \ViMbAdmin_MailboxQueue::enqueue(
            $em,
            mailboxQueueFixture('atomic@example.test'),
            \Entities\MailboxTask::TYPE_REPAIR,
        );
        // Retain the historical caller contract: enqueue may schedule ORM work
        // and every production caller flushes after it returns.
        $em->flush();

        if ($role === 'A') {
            if (!$task instanceof \Entities\MailboxTask) {
                throw new RuntimeException('first transaction did not insert its task');
            }
            touch($syncDir . '/a-inserted');
            waitForPath($syncDir . '/release-a', 10.0);
        }

        $connection->commit();
        echo $role . ':' . ($task instanceof \Entities\MailboxTask ? 'inserted' : 'duplicate') . "\n";
        return 0;
    } catch (Throwable $e) {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        fwrite(STDERR, $role . ': ' . $e->getMessage() . "\n");
        return 1;
    }
}

/** @return array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} */
function startMailboxQueueWorker(string $role, string $syncDir): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker', $role, $syncDir],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__ . '/..',
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start transaction worker ' . $role);
    }
    fclose($pipes[0]);
    return ['process' => $process, 'pipes' => $pipes, 'last_status' => proc_get_status($process)];
}

/**
 * @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker
 * @return array{exit:int,stdout:string,stderr:string}
 */
function waitMailboxQueueWorker(array &$worker, float $timeoutSeconds): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            break;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    if ($worker['last_status']['running']) {
        proc_terminate($worker['process']);
        throw new RuntimeException('transaction worker timed out');
    }

    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);

    return [
        'exit' => (int) $worker['last_status']['exitcode'],
        'stdout' => trim($stdout === false ? '' : $stdout),
        'stderr' => trim($stderr === false ? '' : $stderr),
    ];
}

if (($argv[1] ?? '') === '--worker') {
    exit(runMailboxQueueWorker($argv[2] ?? '', $argv[3] ?? ''));
}

if (getenv('VIMBADMIN_DESTRUCTIVE_DB_TESTS') !== '1') {
    fwrite(STDERR, "Refusing to run: this test deletes mailbox_task rows and alters its schema.\n"
        . "Set VIMBADMIN_DESTRUCTIVE_DB_TESTS=1 only for a disposable database.\n");
    exit(2);
}

final class MailboxQueueAtomicState
{
    public static int $failures = 0;
}

function mailboxQueueRequiredCount(mixed $value): int
{
    if (is_int($value) && $value >= 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($count !== false) {
            return $count;
        }
    }
    throw new UnexpectedValueException('MariaDB returned an invalid mailbox-task count');
}

/** @param array{process:resource,pipes:array<int,resource>,last_status:array<string,mixed>} $worker */
function mailboxQueueWaitsOnTransaction(Connection $observer, int $connectionId, array &$worker, float $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $waits = mailboxQueueRequiredCount($observer->fetchOne(
            'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w'
            . ' INNER JOIN information_schema.INNODB_TRX requesting'
            . ' ON requesting.trx_id = w.requesting_trx_id'
            . ' WHERE requesting.trx_mysql_thread_id = ?',
            [$connectionId],
        ));
        if ($waits > 0) {
            return true;
        }

        $worker['last_status'] = proc_get_status($worker['process']);
        if (!$worker['last_status']['running']) {
            return false;
        }
        usleep(10_000);
    } while (microtime(true) < $deadline);

    return false;
}

function mailboxQueueAtomicCheck(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) {
        MailboxQueueAtomicState::$failures++;
    }
}

echo "== MariaDB mailbox-task atomic deduplication ==\n";
$em = mailboxQueueEntityManager();
$connection = $em->getConnection();
$connection->executeStatement('DELETE FROM mailbox_task');

$syncDir = sys_get_temp_dir() . '/vimbadmin-mailbox-atomic-' . bin2hex(random_bytes(8));
if (!mkdir($syncDir, 0700)) {
    throw new RuntimeException('failed to create synchronization directory');
}

$workerA = null;
$workerB = null;
try {
    $workerA = startMailboxQueueWorker('A', $syncDir);
    waitForPath($syncDir . '/a-inserted', 10.0);
    $workerB = startMailboxQueueWorker('B', $syncDir);
    $connectionPath = $syncDir . '/b-connection';
    waitForPath($connectionPath, 10.0);
    $rawConnectionId = file_get_contents($connectionPath);
    $connectionId = mailboxQueueRequiredCount($rawConnectionId === false ? null : trim($rawConnectionId));

    // With the unique open-task invariant, B must wait on A's uncommitted
    // unique-key record. Without it, B commits a second row immediately.
    mailboxQueueAtomicCheck(
        'second transaction waits on the first uncommitted open task',
        $connectionId > 0 && mailboxQueueWaitsOnTransaction($connection, $connectionId, $workerB, 5.0),
    );

    touch($syncDir . '/release-a');
    $resultA = waitMailboxQueueWorker($workerA, 10.0);
    $resultB = waitMailboxQueueWorker($workerB, 10.0);
    mailboxQueueAtomicCheck('first transaction inserts', $resultA['exit'] === 0 && $resultA['stdout'] === 'A:inserted', $resultA['stderr']);
    mailboxQueueAtomicCheck('second transaction is deduplicated', $resultB['exit'] === 0 && $resultB['stdout'] === 'B:duplicate', $resultB['stderr']);

    $openRepair = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR, \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck('exactly one open task remains for one username and type', $openRepair === 1, 'count=' . $openRepair);

    $archive = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('atomic@example.test'),
        \Entities\MailboxTask::TYPE_ARCHIVE,
    );
    $em->flush();
    $openAllTypes = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck('a distinct task type remains independently queueable', $archive instanceof \Entities\MailboxTask && $openAllTypes === 2);

    $connection->executeStatement(
        'UPDATE mailbox_task SET status = ?, finished_at = CURRENT_TIMESTAMP WHERE username = ? AND type = ?',
        [\Entities\MailboxTask::STATUS_DONE, 'atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR],
    );
    $replacement = \ViMbAdmin_MailboxQueue::enqueue(
        $em,
        mailboxQueueFixture('atomic@example.test'),
        \Entities\MailboxTask::TYPE_REPAIR,
    );
    $em->flush();
    $repairHistory = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ?',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR],
    ));
    $openReplacement = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM mailbox_task WHERE username = ? AND type = ? AND status IN (?, ?)',
        ['atomic@example.test', \Entities\MailboxTask::TYPE_REPAIR, \Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING],
    ));
    mailboxQueueAtomicCheck(
        'completed history remains while a replacement task is queueable',
        $replacement instanceof \Entities\MailboxTask && $repairHistory === 2 && $openReplacement === 1,
    );

    // Exercise the production Schema helper's upgrade path from the previous
    // queue shape. It must reject existing duplicates before adding even the
    // generated column, so a failed upgrade cannot leave half the invariant.
    $connection->executeStatement('DELETE FROM mailbox_task');
    $connection->executeStatement(
        'ALTER TABLE mailbox_task DROP INDEX mailbox_task_open_unique, DROP COLUMN open_task',
    );
    $connection->executeStatement(
        'INSERT INTO mailbox_task (type, username, status, priority, created_at) VALUES'
        . ' (?, ?, ?, 0, CURRENT_TIMESTAMP), (?, ?, ?, 0, CURRENT_TIMESTAMP)',
        [
            \Entities\MailboxTask::TYPE_REPAIR,
            'duplicate@example.test',
            \Entities\MailboxTask::STATUS_PENDING,
            \Entities\MailboxTask::TYPE_REPAIR,
            'duplicate@example.test',
            \Entities\MailboxTask::STATUS_RUNNING,
        ],
    );
    $preflightMessage = '';
    try {
        (new \ViMbAdmin_Schema($em))->migrate();
    } catch (RuntimeException $e) {
        $preflightMessage = $e->getMessage();
    } finally {
        $openTaskColumns = mailboxQueueRequiredCount($connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['mailbox_task', 'open_task'],
        ));
        $connection->executeStatement('DELETE FROM mailbox_task');
        $schema = new \ViMbAdmin_Schema($em);
        $mailboxTaskSql = array_values(array_filter(
            $schema->pendingSql(),
            static fn(string $statement): bool => stripos($statement, 'mailbox_task') !== false,
        ));
        $schema->apply($mailboxTaskSql);
    }
    mailboxQueueAtomicCheck(
        'schema migration rejects duplicate open identities before any DDL',
        str_contains($preflightMessage, 'resolve the duplicate PENDING/RUNNING tasks') && $openTaskColumns === 0,
        $preflightMessage,
    );
    $openTaskIndexColumns = mailboxQueueRequiredCount($connection->fetchOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
        ['mailbox_task', 'mailbox_task_open_unique'],
    ));
    mailboxQueueAtomicCheck('schema migration restores the complete open-task invariant', $openTaskIndexColumns === 3);
} catch (Throwable $e) {
    mailboxQueueAtomicCheck('integration harness completed', false, $e->getMessage());
} finally {
    touch($syncDir . '/release-a');
    foreach ([$workerA, $workerB] as $worker) {
        if (is_array($worker) && is_resource($worker['process'])) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
        }
    }
    foreach (glob($syncDir . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($syncDir);
}

$failures = MailboxQueueAtomicState::$failures;
echo $failures === 0 ? "ALL PASSED\n" : $failures . " FAILED\n";
exit($failures === 0 ? 0 : 1);
