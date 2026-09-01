<?php

/**
 * Queue-runner concurrency control.
 *
 * ViMbAdmin has no daemon and never forks a runner from a web request. The
 * mailbox-task queue is drained by two triggers, both running the same engine
 * synchronously: (1) the `queue.cli-run` CLI invoked out-of-band by the
 * container cron / s6 service, and (2) the bearer-key + IP-gated remote
 * `POST /queue/trigger` endpoint (drains in-request). The Maintenance tab's
 * "Run now" button drains in-request too. This class only arbitrates how many
 * of those drains may run at once.
 *
 * Concurrency (`queue.runner.max_concurrent`, default 1) is enforced with a DB
 * lease: each active drain holds a row in `queue_runner`, heartbeats it, and
 * deletes it on exit. A new drain only starts if the count of non-stale leases
 * is below the cap. Stale leases (the process died without releasing) are reaped
 * after LEASE_TTL seconds so a slot is never lost forever.
 */
/**
 * @phpstan-type QueueRunnerOptions array{
 *     queue?: array{
 *         runner?: array{max_concurrent?: mixed, ...<string, mixed>},
 *         ...<string, mixed>
 *     },
 *     ...<string, mixed>
 * }
 */
class ViMbAdmin_QueueRunner
{
    /** Seconds after which a lease with no heartbeat is considered dead. */
    const LEASE_TTL = 1800;

    /**
     * Are we below queue.runner.max_concurrent active (non-stale) runners?
     * Reaps stale leases first so a crashed runner doesn't pin a slot.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param QueueRunnerOptions $options
     * @return bool
     */
    public static function slotAvailable( $em, array $options )
    {
        $max = self::maxConcurrent($options);
        self::reapStale( $em );
        $activeValue = $em->createQuery(
            'SELECT COUNT(r.id) FROM \Entities\QueueRunner r' )->getSingleScalarResult();
        $active = self::nonNegativeInt($activeValue, 'active runner count');
        return $active < $max;
    }

    /**
     * Acquire a runner lease (call at the start of an actual drain). Returns the
     * lease entity, or null if no slot is free (the caller must then NOT drain).
     * The count + insert race is closed by re-checking after insert and backing
     * out if we overshot the cap — cheap and correct for the small N here.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param QueueRunnerOptions $options
     * @return \Entities\QueueRunner|null
     */
    public static function acquireLease( $em, array $options )
    {
        if( !self::slotAvailable( $em, $options ) )
            return null;

        $now   = new \DateTime();
        $lease = new \Entities\QueueRunner();
        $lease->setHost( (string) gethostname() )
              ->setPid( function_exists( 'getmypid' ) ? (int) getmypid() : 0 )
              ->setStartedAt( $now )
              ->setHeartbeatAt( $now );
        $em->persist( $lease );
        $em->flush();

        // Race back-off: if our insert pushed the active count over the cap and
        // we are not among the oldest <max> leases, yield our slot.
        $max = self::maxConcurrent($options);
        $ids = $em->createQuery(
            'SELECT r.id FROM \Entities\QueueRunner r ORDER BY r.id ASC' )
            ->setMaxResults( $max )->getResult();
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \UnexpectedValueException('Runner lease query result is malformed');
        }
        $keep = [];
        foreach ($ids as $row) {
            if (!is_array($row) || !array_key_exists('id', $row)) {
                throw new \UnexpectedValueException('Runner lease row is malformed');
            }
            $keep[] = self::positiveInt($row['id'], 'runner lease id');
        }
        if( !in_array( (int) $lease->getId(), $keep, true ) )
        {
            $em->remove( $lease );
            $em->flush();
            return null;
        }

        // Record when the runner last actually started a drain (the lease row
        // is deleted on exit, so the Maintenance overview reads this marker
        // rather than the live queue_runner table).
        ViMbAdmin_Setting::stampNow( $em, ViMbAdmin_Setting::LAST_QUEUERUN );

        return $lease;
    }

    /**
     * Refresh a lease's heartbeat (call periodically during a long drain).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\QueueRunner $lease
     * @return void
     */
    public static function heartbeat( $em, \Entities\QueueRunner $lease )
    {
        $lease->setHeartbeatAt( new \DateTime() );
        $em->flush();
    }

    /**
     * Release a lease (call when the drain finishes, in a finally block).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\QueueRunner $lease
     * @return void
     */
    public static function release( $em, \Entities\QueueRunner $lease )
    {
        try
        {
            $em->remove( $lease );
            $em->flush();
        }
        catch( \Throwable $e )
        {
            // best-effort; a stale row will be reaped by reapStale().
        }
    }

    /**
     * Delete leases whose heartbeat is older than LEASE_TTL (dead runners).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @return int  rows reaped
     */
    public static function reapStale( $em )
    {
        $cutoff = ( new \DateTime() )->modify( '-' . self::LEASE_TTL . ' seconds' );
        $reaped = $em->createQuery(
            'DELETE FROM \Entities\QueueRunner r WHERE r.heartbeat_at < :cutoff' )
            ->setParameter( 'cutoff', $cutoff )
            ->execute();
        return self::nonNegativeInt($reaped, 'reaped runner count');
    }

    /** @param array<string,mixed> $options */
    private static function maxConcurrent(array $options): int
    {
        if (!array_key_exists('queue', $options)) return 1;
        if (!is_array($options['queue'])) throw new \TypeError('queue options must be an array');
        if (!array_key_exists('runner', $options['queue'])) return 1;
        if (!is_array($options['queue']['runner'])) throw new \TypeError('queue.runner options must be an array');
        if (!array_key_exists('max_concurrent', $options['queue']['runner'])) return 1;
        return max(1, self::nonNegativeInt($options['queue']['runner']['max_concurrent'], 'max_concurrent'));
    }

    private static function nonNegativeInt(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false && $parsed >= 0) return $parsed;
        }
        throw new \TypeError($name . ' must be a non-negative integer');
    }

    private static function positiveInt(mixed $value, string $name): int
    {
        $parsed = self::nonNegativeInt($value, $name);
        if ($parsed > 0) return $parsed;
        throw new \TypeError($name . ' must be positive');
    }
}
