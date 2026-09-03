<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 - 2024 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Framework-free mailbox-task queue RUNNER (docs/ZF1-REMOVAL.md, Phase 4).
 *
 * The execution engine carved out of the ZF1 `QueueController` (`_execute` +
 * `_drain` + their helpers) so BOTH dispatch paths can drive the queue:
 *   - the ZF1 `cli-run` (cron) / `run-now` / `run-task` controller actions, and
 *   - the native kernel `QueueController::runNow` / `runTask`.
 *
 * It depends only on `Doctrine\Persistence\ObjectManager`, the merged options
 * array, the entities, and the (already framework-free) `ViMbAdmin_Doveadm` /
 * `ViMbAdmin_QueueRunner` / `ViMbAdmin_Setting` helpers — no framework. Internal
 * failures that ZF1 sent to its logger are written with `error_log()`.
 *
 *   drain($max)   — lease-gated batch: claim + run up to $max PENDING tasks,
 *                   marking each DONE/FAILED; returns the count, or -1 throttled.
 *   runOne($task, $complete) — lease-gate, claim and run one manual task; the
 *                   completion callback records DONE/FAILED before release.
 *
 * @package ViMbAdmin
 * @subpackage Services
 */
class ViMbAdmin_Service_QueueRunner
{
    /** Refresh often enough that an active request cannot reach the 30m TTL. */
    const LEASE_HEARTBEAT_INTERVAL = 60;
    const RUN_ONE_BUSY = -1;
    const RUN_ONE_NOT_CLAIMED = 0;
    const RUN_ONE_COMPLETED = 1;

    /** @var \Doctrine\ORM\EntityManager */
    private $em;

    /** @var array<string, mixed> */
    private $options;

    private static function nonNegativeInteger(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($integer)) {
                return $integer;
            }
        }
        throw new \LogicException($name . ' must be a non-negative integer.');
    }

    private static function positiveInteger(mixed $value, string $name): int
    {
        $integer = self::nonNegativeInteger($value, $name);
        if ($integer < 1) {
            throw new \LogicException($name . ' must be greater than zero.');
        }
        return $integer;
    }

    private static function requiredString(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new \LogicException($name . ' must be a string.');
        }
        return $value;
    }

    /** @return array{bool,mixed} */
    private function option(string ...$path): array
    {
        $value = $this->options;
        foreach ($path as $key) {
            if (!is_array($value)) {
                throw new \LogicException('QueueRunner configuration path must be an array.');
            }
            if (!array_key_exists($key, $value)) {
                return [false, null];
            }
            $value = $value[$key];
        }
        return [true, $value];
    }

    private function optionString(string $default, string ...$path): string
    {
        [$found, $value] = $this->option(...$path);
        return $found ? self::requiredString($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    private function optionNonNegativeInteger(int $default, string ...$path): int
    {
        [$found, $value] = $this->option(...$path);
        return $found ? self::nonNegativeInteger($value, 'Configuration ' . implode('.', $path)) : $default;
    }

    private function maildirRoot(): string
    {
        $root = rtrim($this->optionString('/opt/myguard/dovecot/maildir', 'doveadm', 'maildir_root'), '/');
        if ($root === '' || $root[0] !== '/' || strpos($root, "\0") !== false) {
            throw new \LogicException('Configuration doveadm.maildir_root must be a non-root absolute path.');
        }

        return $root;
    }

    /** @return array{queue:array{runner:array{max_concurrent:int}}} */
    private function leaseOptions(): array
    {
        return ['queue' => ['runner' => ['max_concurrent' => max(1,
            $this->optionNonNegativeInteger(1, 'queue', 'runner', 'max_concurrent'))]]];
    }

    /** @param array<string, mixed> $options */
    public function __construct(\Doctrine\Persistence\ObjectManager $em, array $options)
    {
        if (!$em instanceof \Doctrine\ORM\EntityManager) {
            throw new \LogicException('QueueRunner requires Doctrine ORM EntityManager capabilities.');
        }
        $this->em      = $em;
        $this->options = $options;
    }

    /** @return \DateTime */
    protected function now()
    {
        return new \DateTime();
    }

    /**
     * Factory seam keeps deterministic blocking-call tests off the network.
     *
     * @param callable $progress
     * @return ViMbAdmin_Doveadm
     */
    protected function newDoveadm($progress)
    {
        return ViMbAdmin_Doveadm::fromOptions($this->options, $progress);
    }

    /**
     * Execute a callback while holding one runner slot.
     *
     * @param callable(\Entities\QueueRunner):void $operation
     * @return bool false when every configured slot is already occupied
     */
    public function withLease(callable $operation)
    {
        $lease = ViMbAdmin_QueueRunner::acquireLease($this->em, $this->leaseOptions(), $this->now());
        if ($lease === null) {
            return false;
        }

        try {
            $operation($lease);
        } finally {
            ViMbAdmin_QueueRunner::release($this->em, $lease);
        }

        return true;
    }

    /** @return callable():void */
    private function leaseProgress(\Entities\QueueRunner $lease)
    {
        return function() use ($lease): void {
            $heartbeat = $lease->getHeartbeatAt();
            $now = $this->now();
            if ($heartbeat === null
                || $heartbeat->getTimestamp() <= $now->getTimestamp() - self::LEASE_HEARTBEAT_INTERVAL) {
                ViMbAdmin_QueueRunner::heartbeat($this->em, $lease, $now);
            }
        };
    }

    /** @return ViMbAdmin_Doveadm */
    private function doveadmForLease(\Entities\QueueRunner $lease)
    {
        return $this->newDoveadm($this->leaseProgress($lease));
    }

    /**
     * Lease-gated batch drain: process up to $max PENDING tasks.
     *
     * Returns the number processed, or -1 if every runner slot
     * (queue.runner.max_concurrent) was busy (throttled, not "no work").
     *
     * @param mixed $max
     * @param mixed $verbose
     * @return int
     */
    public function drain($max, $verbose = false)
    {
        $max  = self::positiveInteger($max, 'Queue drain maximum');
        $em   = $this->em;
        $repo = $em->getRepository('\\Entities\\MailboxTask');
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new \LogicException('MailboxTask entity must use Repositories\\MailboxTask.');
        }

        $processed = 0;
        $acquired = $this->withLease(function(\Entities\QueueRunner $lease) use ($em, $repo, $max, $verbose, &$processed): void {
            // Periodic autoprune sweep (gated to once / 8h): enqueue a PRUNE task
            // for each expired autoprune backup.
            $this->autopruneSweep();

            foreach ($repo->pending($max) as $task) {
                // Atomic PENDING -> RUNNING; skip if another runner won the row.
                if (!$repo->claim($task)) {
                    continue;
                }

                try {
                    $doveadm = $this->doveadmForLease($lease);
                    $this->execute($task, $doveadm);
                    $task->setStatus(\Entities\MailboxTask::STATUS_DONE);
                    $task->appendLog('done');
                } catch (\Throwable $e) {
                    $task->setStatus(\Entities\MailboxTask::STATUS_FAILED);
                    $task->appendLog('FAILED: ' . $e->getMessage());
                    error_log("QueueRunner task {$task->getId()} ({$task->getType()} {$task->getUsername()}): " . $e->getMessage());
                }

                $task->setFinishedAt(new \DateTime());
                $em->flush();
                ($this->leaseProgress($lease))();

                if ($verbose) {
                    echo " - #{$task->getId()} {$task->getType()} {$task->getUsername()}: {$task->getStatus()}\n";
                }

                $processed++;
            }
        });

        if (!$acquired) {
            if ($verbose) {
                echo "All runner slots busy (queue.runner.max_concurrent) — skipping.\n";
            }
            return -1;
        }

        return $processed;
    }

    /**
     * Lease-gate, atomically claim and execute one manual task. The completion
     * callback receives the execution error (or null) and records the terminal
     * task state while the same lease is still held.
     *
     * @param callable(?\Throwable):void $complete
     * @return int one of RUN_ONE_*
     */
    public function runOne(\Entities\MailboxTask $task, callable $complete)
    {
        $repo = $this->em->getRepository('\\Entities\\MailboxTask');
        if (!$repo instanceof \Repositories\MailboxTask) {
            throw new \LogicException('MailboxTask entity must use Repositories\\MailboxTask.');
        }

        $result = self::RUN_ONE_BUSY;
        $acquired = $this->withLease(function(\Entities\QueueRunner $lease) use ($repo, $task, $complete, &$result): void {
            if (!$repo->claim($task)) {
                $result = self::RUN_ONE_NOT_CLAIMED;
                return;
            }

            $error = null;
            try {
                $this->execute($task, $this->doveadmForLease($lease));
            } catch (\Throwable $e) {
                $error = $e;
            }
            $complete($error);
            $result = self::RUN_ONE_COMPLETED;
        });

        return $acquired ? $result : self::RUN_ONE_BUSY;
    }

    // ---------------------------------------------------------------------
    //  Engine (moved verbatim from the ZF1 QueueController)
    // ---------------------------------------------------------------------

    /** @return void */
    private function execute(\Entities\MailboxTask $task, ViMbAdmin_Doveadm $doveadm)
    {
        $user = $task->requiredUsername();

        switch ($task->getType()) {
            case \Entities\MailboxTask::TYPE_REPAIR:
            case \Entities\MailboxTask::TYPE_OPTIMIZE:
                $task->appendLog('force-resync');  $doveadm->forceResync($user);
                $task->appendLog('index');          $doveadm->index($user);
                $task->appendLog('purge');          $doveadm->purge($user);
                $task->appendLog('quota recalc');   $doveadm->quotaRecalc($user);
                break;

            case \Entities\MailboxTask::TYPE_QUOTA_RECALC:
                $task->appendLog('quota recalc');   $doveadm->quotaRecalc($user);
                break;

            case \Entities\MailboxTask::TYPE_MEASURE_SIZE:
                $archive = $this->em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
                if (!$archive || !$archive->getMaildirFile()) {
                    $task->appendLog('measure-size: no archive/dest — nothing to do');
                    break;
                }
                $task->appendLog('measure-size: fs-walk ' . $archive->getMaildirFile());
                $bytes = $doveadm->fsDirSize($archive->getMaildirFile());
                if ($bytes !== null && $bytes > 0) {
                    $archive->setMaildirSize((int) $bytes);
                    $this->em->persist($archive);
                    $task->appendLog('measure-size: ' . $bytes . ' bytes');
                } else {
                    $task->appendLog('measure-size: walk returned no size (kept logical)');
                }
                break;

            case \Entities\MailboxTask::TYPE_PRUNE:
                $archive = $this->em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
                if (!$archive) {
                    $task->appendLog('prune: archive already gone — nothing to do');
                    break;
                }
                if (!$archive->getAutoprune()) {
                    $task->appendLog('prune: autoprune turned off — skipping');
                    break;
                }
                $dest = $archive->getMaildirFile();
                if ($dest) {
                    $task->appendLog('prune: fs delete ' . $dest);
                    $doveadm->fsDelete($dest);
                }
                $this->em->remove($archive);
                ViMbAdmin_Setting::stampNow($this->em, ViMbAdmin_Setting::LAST_PRUNE);
                $task->appendLog('prune: archive removed');
                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "autopruned expired archive backup for {$user}");
                break;

            case \Entities\MailboxTask::TYPE_BACKUP_ORPHAN:
                $this->backupOrphan($task, $doveadm);
                break;

            case \Entities\MailboxTask::TYPE_ARCHIVE:
                $dest = $this->backupDest($task);
                $task->appendLog("backup -> {$dest}");
                $doveadm->backup($user, $dest);
                $task->appendLog('recording archive row');
                $this->recordArchive($task, $dest, false, $doveadm);
                $task->appendLog('mailbox delete (empty store, keep account)');
                $doveadm->mailboxDelete($user);
                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "archived {$user} (backup {$dest}, store emptied, account kept)");
                break;

            case \Entities\MailboxTask::TYPE_DELETE:
                if ($this->autopruneDays() === 0) {
                    $task->appendLog('autoprune.days=0 — instant delete, no backup');
                    $doveadm->mailboxDelete($user);
                    $this->removeMaildirHome($task, $doveadm, $user);
                    $task->appendLog('removing ViMbAdmin mailbox row');
                    $this->removeMailboxRow($user);
                    $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                        "deleted {$user} (instant, autoprune.days=0 — no backup)");
                    break;
                }

                $dest = $this->backupDest($task);
                $task->appendLog("backup -> {$dest}");
                $doveadm->backup($user, $dest);
                $task->appendLog('recording archive row (autoprune on)');
                $this->recordArchive($task, $dest, true, $doveadm);
                $task->appendLog('mailbox delete (empty store)');
                $doveadm->mailboxDelete($user);
                $this->removeMaildirHome($task, $doveadm, $user);
                $task->appendLog('removing ViMbAdmin mailbox row');
                $this->removeMailboxRow($user);
                $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                    "deleted {$user} (backup {$dest}, autoprune on — prunes after queue.autoprune.days)");
                break;

            default:
                throw new ViMbAdmin_Exception('unknown task type: ' . $task->getType());
        }
    }

    /** @return string */
    private function backupDest(\Entities\MailboxTask $task)
    {
        $tpl  = $this->optionString('maildir:/backups/%d/%u', 'doveadm', 'backup', 'dest');
        $user = self::assertPathSafe($task->requiredUsername());
        $domainSuffix = strrchr($user, '@');
        $taskDomain = $task->getDomain();
        $dom  = $taskDomain ? $taskDomain->requiredDomainName() : ($domainSuffix === false ? '' : substr($domainSuffix, 1));
        $dom  = self::assertPathSafe($dom);
        return str_replace(['%d', '%u'], [$dom, $user], $tpl);
    }

    /**
     * Defence in depth against a maildir/backup path escaping its jail. A
     * username/domain is substituted into filesystem paths ('%d/%u', maildir
     * home) that doveadm then reads/writes/recursively-deletes. Creation is
     * validated (web form + MCP), but a legacy or externally-inserted row could
     * still hold a traversal-shaped value — reject any path separator or
     * parent-dir reference here rather than trusting the input.
     *
     * @throws \RuntimeException
     * @param mixed $value
     * @return string
     */
    private static function assertPathSafe(mixed $value): string
    {
        $s = self::requiredString($value, 'Mailbox task path component');
        if ($s === '' || strpos($s, '/') !== false || strpos($s, "\0") !== false
            || $s === '..' || strpos($s, '..') !== false) {
            throw new ViMbAdmin_Exception('refusing unsafe path component in mailbox task: ' . $s);
        }
        return $s;
    }

    /**
     * @param string $dest
     * @param bool $autoprune
     * @return void
     */
    private function recordArchive(
        \Entities\MailboxTask $task,
        $dest,
        $autoprune,
        ?ViMbAdmin_Doveadm $doveadm = null
    )
    {
        $em   = $this->em;
        $user = $task->requiredUsername();
        $now  = new \DateTime();

        $mbData = null;
        $mb = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $user]);
        if ($mb) {
            $mbData = [
                'username'   => $mb->requiredUsername(),
                'local_part' => $mb->getLocalPart(),
                'name'       => $mb->getName(),
                'password'   => $mb->requiredPassword(),
                'quota'      => $mb->getQuota(),
                'active'     => $mb->getActive(),
            ];
        }

        $archive = $em->getRepository('\\Entities\\Archive')->findOneBy(['username' => $user]);
        if (!$archive) {
            $archive = new \Entities\Archive();
            $archive->setUsername($user);
        }

        $origSize = null;
        try {
            $doveadm ??= ViMbAdmin_Doveadm::fromOptions($this->options);
            $doveadm->quotaRecalc($user);
            $bytes = $em->getConnection()->fetchOne('SELECT bytes FROM dovecot_quota WHERE username = ?', [$user]);
            if ($bytes !== false && $bytes !== null) {
                $origSize = self::nonNegativeInteger($bytes, 'Dovecot quota bytes');
            }
        } catch (\Throwable $e) {
            error_log("QueueRunner::recordArchive quota {$user}: " . $e->getMessage());
        }

        $archive->setStatus(\Entities\Archive::STATUS_ARCHIVED)
                ->setArchivedAt($now)
                ->setStatusChangedAt($now)
                ->setArchivedBy($task->getRequestedBy())
                ->setDomain($task->getDomain())
                ->setMaildirServer($this->optionString('', 'doveadm', 'http', 'url'))
                ->setMaildirFile($dest)
                ->setAutoprune($autoprune)
                ->setData($this->encodeTaskData([
                    'username' => $user,
                    'type'     => $task->getType(),
                    'task_id'  => $task->getId(),
                    'dest'     => $dest,
                    'mailbox'  => $mbData,
                ]));

        if ($origSize !== null) {
            $archive->setMaildirOrigSize($origSize)->setMaildirSize($origSize);
        }
        $em->persist($archive);

        $open = $em->createQuery(
            'SELECT COUNT(t.id) FROM \Entities\MailboxTask t
              WHERE t.username = :u AND t.type = :t AND t.status IN (:open)')
            ->setParameter('u', $user)
            ->setParameter('t', \Entities\MailboxTask::TYPE_MEASURE_SIZE)
            ->setParameter('open', [\Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING])
            ->getSingleScalarResult();
        if (self::nonNegativeInteger($open, 'Open measure-size task count') === 0) {
            $mt = new \Entities\MailboxTask();
            $mt->setType(\Entities\MailboxTask::TYPE_MEASURE_SIZE)
               ->setUsername($user)
               ->setStatus(\Entities\MailboxTask::STATUS_PENDING)
               ->setPriority(-10)
               ->setCreatedAt(new \DateTime())
               ->setDomain($task->getDomain())
               ->setRequestedBy($task->getRequestedBy())
               ->setData($this->encodeTaskData(['dest' => $dest]));
            $em->persist($mt);
        }
    }

    /**
     * @param ViMbAdmin_Doveadm $doveadm
     * @param string $user
     * @return void
     */
    private function removeMaildirHome(\Entities\MailboxTask $task, $doveadm, $user)
    {
        $root = $this->maildirRoot();
        $home = $root . '/' . self::assertPathSafe($user);

        try {
            if ($doveadm->maildirHasMail($home)) {
                $task->appendLog('KEEP maildir home — still contains mail (empty/backup step failed?): ' . $home);
                return;
            }
        } catch (\Throwable $e) {
            $task->appendLog('KEEP maildir home — could not verify it is empty: ' . $e->getMessage());
            return;
        }

        try {
            $task->appendLog('remove empty maildir home ' . $home);
            $doveadm->fsDelete($home);
        } catch (\Throwable $e) {
            $task->appendLog('remove maildir home warning: ' . $e->getMessage());
        }
    }

    /**
     * @param string $username
     * @return void
     */
    private function removeMailboxRow($username)
    {
        $em      = $this->em;
        $mailbox = $em->getRepository('\\Entities\\Mailbox')->findOneBy(['username' => $username]);
        if (!$mailbox) {
            return;
        }

        $repository = $em->getRepository('\\Entities\\Mailbox');
        if (!method_exists($repository, 'purgeMailbox')) {
            throw new \LogicException('Mailbox repository must implement purgeMailbox().');
        }
        $repository->purgeMailbox($mailbox, null, true);

        try {
            $em->getConnection()->executeStatement('DELETE FROM dovecot_quota WHERE username = ?', [$username]);
        } catch (\Exception $e) {
            error_log('vimbadmin: dovecot_quota cleanup failed for ' . $username . ': ' . $e->getMessage());
        }
    }

    /**
     * @param string $action
     * @param string $message
     * @return void
     */
    private function logAudit(\Entities\MailboxTask $task, $action, $message)
    {
        try {
            $log = new \Entities\Log();
            $log->setAction($action)
                ->setData($message)
                ->setTimestamp(new \DateTime());
            if ($task->getRequestedBy()) {
                $log->setAdmin($task->getRequestedBy());
            }
            if ($task->getDomain()) {
                $log->setDomain($task->getDomain());
            }
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $e) {
            error_log('QueueRunner::logAudit: ' . $e->getMessage());
        }
    }

    /** @return int */
    private function autopruneDays()
    {
        return $this->optionNonNegativeInteger(90, 'queue', 'autoprune', 'days');
    }

    /** @return void */
    private function autopruneSweep()
    {
        $em = $this->em;

        $last = ViMbAdmin_Setting::get($em, ViMbAdmin_Setting::LAST_PRUNE_SWEEP);
        if ($last !== null) {
            $lastTs = strtotime((string) $last);
            if ($lastTs !== false && (time() - $lastTs) < 8 * 3600) {
                return;
            }
        }

        ViMbAdmin_Setting::set($em, ViMbAdmin_Setting::LAST_PRUNE_SWEEP, (new \DateTime())->format('c'));

        try {
            $days   = $this->autopruneDays();
            $cutoff = (new \DateTime())->modify('-' . $days . ' days');
            $archiveRepository = $em->getRepository('\\Entities\\Archive');
            if (!$archiveRepository instanceof \Repositories\Archive) {
                throw new \LogicException('Archive entity must use Repositories\\Archive.');
            }
            $expired = $archiveRepository->findAutoprune($cutoff);

            foreach ($this->initializedAutopruneArchives($expired) as [$archive, $user]) {
                $open = (int) $em->createQuery(
                    'SELECT COUNT(t.id) FROM \Entities\MailboxTask t
                      WHERE t.username = :u AND t.type = :t AND t.status IN (:open)')
                    ->setParameter('u', $user)
                    ->setParameter('t', \Entities\MailboxTask::TYPE_PRUNE)
                    ->setParameter('open', [\Entities\MailboxTask::STATUS_PENDING, \Entities\MailboxTask::STATUS_RUNNING])
                    ->getSingleScalarResult();
                if ($open > 0) {
                    continue;
                }

                $mt = new \Entities\MailboxTask();
                $mt->setType(\Entities\MailboxTask::TYPE_PRUNE)
                   ->setUsername($user)
                   ->setStatus(\Entities\MailboxTask::STATUS_PENDING)
                   ->setPriority(-20)
                   ->setCreatedAt(new \DateTime())
                   ->setDomain($archive->getDomain())
                   ->setData($this->encodeTaskData(['dest' => $archive->getMaildirFile()]));
                $em->persist($mt);
            }
            $em->flush();
        } catch (\Throwable $e) {
            error_log('QueueRunner::autopruneSweep: ' . $e->getMessage());
        }
    }

    /**
     * Keep one corrupt archive identity from starving the remaining sweep.
     *
     * @param iterable<\Entities\Archive> $archives
     * @return \Generator<int, array{0: \Entities\Archive, 1: string}>
     */
    private function initializedAutopruneArchives(iterable $archives): \Generator
    {
        foreach ($archives as $archive) {
            try {
                $username = $archive->requiredUsername();
            } catch (\LogicException $e) {
                error_log('QueueRunner::autopruneSweep: ' . $e->getMessage());
                continue;
            }

            yield [$archive, $username];
        }
    }

    /**
     * @param ViMbAdmin_Doveadm $doveadm
     * @return void
     */
    private function backupOrphan(\Entities\MailboxTask $task, $doveadm)
    {
        $em   = $this->em;
        $user = self::assertPathSafe($task->requiredUsername());
        $root = $this->maildirRoot();
        $conn = $em->getConnection();

        $exists = (int) $em->createQuery('SELECT COUNT(m.id) FROM \Entities\Mailbox m WHERE m.username = :u')
            ->setParameter('u', $user)->getSingleScalarResult();
        if ($exists > 0) {
            $task->appendLog('backup-orphan: a real mailbox now exists — skipping');
            return;
        }

        $domainSuffix = strrchr($user, '@');
        $domainPart = $domainSuffix === false ? null : substr($domainSuffix, 1);
        if (!$domainPart) {
            $task->appendLog("backup-orphan: '{$user}' has no domain part — cannot create temp user");
            return;
        }
        $domain = $em->getRepository('\\Entities\\Domain')->findOneBy(['domain' => $domainPart]);

        // If the orphan's domain isn't in ViMbAdmin we still want to back it up
        // and reap it. The temp mailbox row below needs a NOT NULL Domain_id, so
        // create a transient INACTIVE domain row (active=0 -> postfix/dovecot SQL
        // auth filter on active=1, so no mail/routing effect). It is removed
        // again in the finally block UNLESS an Archive row ends up referencing
        // it: Archive.Domain_id has no onDelete (-> RESTRICT) and the archive is
        // EM-persisted then flushed AFTER this method, so deleting the domain
        // here would make that later flush fail with an FK error. Hence the
        // has-mail path keeps the (inactive) domain; only the empty-skeleton
        // path (no archive) drops it again.
        $tempDomainId = null;
        if (!$domain) {
            $conn->insert('domain', [
                'domain'      => $domainPart,
                'description' => 'auto-created for orphan backup',
                'active'      => 0,
                'created'     => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $tempDomainId = (int) $conn->lastInsertId();
            $domain = $em->getRepository('\\Entities\\Domain')->findOneBy(['domain' => $domainPart]);
            $task->appendLog("backup-orphan: domain '{$domainPart}' not in ViMbAdmin — created transient inactive domain row #{$tempDomainId}");
        }

        $home      = $root . '/' . $user;
        $localPart = strstr($user, '@', true) ?: $user;
        $tempId    = null;
        $keepDomain = false;   // set true once an Archive row references $domain

        try {
            if (!$domain instanceof \Entities\Domain) {
                throw new \LogicException('Backup orphan domain could not be loaded.');
            }

            $conn->insert('mailbox', [
                'username'   => $user,
                'password'   => '{PLAIN}!orphan-backup-no-login!',
                'local_part' => $localPart,
                'quota'      => 0,
                'active'     => 0,
                'created'    => (new \DateTime())->format('Y-m-d H:i:s'),
                'Domain_id'  => $domain->requiredId(),
            ]);
            $tempId = (int) $conn->lastInsertId();
            $task->appendLog("backup-orphan: temp user row #{$tempId} created");

            $task->setDomain($domain);

            $doveadm->authCacheFlush();

            $task->appendLog('orphan: repair (force-resync/index/purge)');
            try {
                $doveadm->forceResync($user);
                $doveadm->index($user);
                $doveadm->purge($user);
            } catch (\Throwable $e) {
                $task->appendLog('orphan: repair warning: ' . $e->getMessage());
            }

            if ($doveadm->maildirHasMail($home)) {
                $dest = $this->backupDest($task);
                $task->appendLog("orphan: has mail — backup -> {$dest}");
                $doveadm->backup($user, $dest);

                $task->appendLog('orphan: recording archive row');
                $this->recordArchive($task, $dest, false, $doveadm);
                // Archive row now references $domain (FK RESTRICT, flushed after
                // this method) -> a transient domain must NOT be deleted.
                $keepDomain = true;

                $task->appendLog('orphan: empty store + remove maildir home');
                try {
                    $doveadm->mailboxDelete($user);
                } catch (\Throwable $e) {
                    $task->appendLog('orphan: mailboxDelete warning: ' . $e->getMessage());
                }
                $this->removeMaildirHome($task, $doveadm, $user);

                $this->logAudit($task, \Entities\Log::ACTION_ARCHIVE_REQUEST,
                    "imported ORPHAN maildir for {$user}: backed up + removed (had mail)");
            } else {
                $task->appendLog('orphan: empty skeleton — removing maildir home (no backup)');
                $this->removeMaildirHome($task, $doveadm, $user);

                $this->logAudit($task, \Entities\Log::ACTION_MAILBOX_PURGE,
                    "removed empty ORPHAN maildir skeleton for {$user} (no mail)");
            }
        } finally {
            if ($tempId !== null) {
                try {
                    $conn->delete('mailbox', ['id' => $tempId]);
                } catch (\Throwable $e) {
                    error_log("backup-orphan temp-row cleanup {$user}: " . $e->getMessage());
                }
                $task->appendLog('backup-orphan: temp user row removed');
            }
            // Remove the transient domain (created above) AFTER the temp mailbox
            // row that FK-references it. Keep it when an archive references it.
            if ($tempDomainId !== null) {
                if ($keepDomain) {
                    $task->appendLog("backup-orphan: transient domain '{$domainPart}' kept (referenced by archive), left inactive");
                } else {
                    // Drop the in-memory reference first: the task FK is
                    // onDelete SET NULL at the DB, but the EM still holds the
                    // transient Domain entity and would re-write Domain_id on the
                    // task's later flush (-> FK error against the deleted row).
                    $task->setDomain(null);
                    if ($domain instanceof \Entities\Domain) {
                        try { $em->detach($domain); } catch (\Throwable $e) {}
                    }
                    try {
                        $conn->delete('domain', ['id' => $tempDomainId]);
                        $task->appendLog('backup-orphan: transient domain row removed');
                    } catch (\Throwable $e) {
                        error_log("backup-orphan temp-domain cleanup {$domainPart}: " . $e->getMessage());
                        $task->appendLog('backup-orphan: transient domain kept (cleanup failed: ' . $e->getMessage() . ')');
                    }
                }
            }
            try {
                $doveadm->authCacheFlush();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Encode queue/archive metadata and fail closed if the payload is not valid JSON.
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    private function encodeTaskData(array $data): string
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new \RuntimeException('failed to encode mailbox task metadata');
        }

        return $json;
    }
}
