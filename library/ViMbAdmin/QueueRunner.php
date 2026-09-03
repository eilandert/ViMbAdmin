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
 * lease: each active drain atomically claims a uniquely indexed slot in
 * `queue_runner`, heartbeats it, and deletes it on exit. Stale leases (the
 * process died without releasing) are reaped after LEASE_TTL seconds so a slot
 * is never lost forever.
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
    public static function slotAvailable( $em, array $options, ?\DateTime $now = null )
    {
        $max = self::maxConcurrent($options);
        self::reapStale( $em, $now );
        $activeValue = $em->createQuery(
            'SELECT COUNT(r.id) FROM \Entities\QueueRunner r' )->getSingleScalarResult();
        $active = self::nonNegativeInt($activeValue, 'active runner count');
        return $active < $max;
    }

    /**
     * Acquire a runner lease (call at the start of an actual drain). Returns the
     * lease entity, or null if no slot is free (the caller must then NOT drain).
     * Each slot is protected by a database UNIQUE constraint, so concurrent
     * callers cannot both acquire it. Duplicate-key is ordinary contention;
     * every other database failure remains an operational error.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param QueueRunnerOptions $options
     * @return \Entities\QueueRunner|null
     */
    public static function acquireLease( $em, array $options, ?\DateTime $now = null )
    {
        $max = self::maxConcurrent($options);
        self::reapStale( $em, $now );
        $now = $now === null ? new \DateTime() : clone $now;
        $connection = $em->getConnection();

        for( $slot = 1; $slot <= $max; $slot++ )
        {
            try
            {
                $affected = $connection->insert( 'queue_runner', [
                    'slot'         => $slot,
                    'host'         => (string) gethostname(),
                    'pid'          => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
                    'started_at'   => $now->format( 'Y-m-d H:i:s' ),
                    'heartbeat_at' => $now->format( 'Y-m-d H:i:s' ),
                ] );
            }
            catch( \Doctrine\DBAL\Exception\UniqueConstraintViolationException $e )
            {
                continue;
            }

            if( $affected !== 1 )
                throw new \RuntimeException( 'Runner lease insert affected an unexpected number of rows.' );

            $id = filter_var( $connection->lastInsertId(), FILTER_VALIDATE_INT, [
                'options' => [ 'min_range' => 1 ],
            ] );
            if( $id === false )
                throw new \UnexpectedValueException( 'Runner lease insert returned an invalid identifier.' );

            $lease = $em->find( '\Entities\QueueRunner', $id );
            if( !$lease instanceof \Entities\QueueRunner || $lease->getSlot() !== $slot )
                throw new \UnexpectedValueException( 'Inserted runner lease could not be reloaded.' );

            // The lease row is deleted on exit, so the overview reads this
            // durable marker rather than the live lease table.
            ViMbAdmin_Setting::stampNow( $em, ViMbAdmin_Setting::LAST_QUEUERUN );

            return $lease;
        }

        return null;
    }

    /**
     * Refresh a lease's heartbeat (call periodically during a long drain).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\QueueRunner $lease
     * @return void
     */
    public static function heartbeat( $em, \Entities\QueueRunner $lease, ?\DateTime $now = null )
    {
        $now = $now === null ? new \DateTime() : clone $now;
        $id = self::positiveInt($lease->getId(), 'runner lease id');
        $slot = self::positiveInt($lease->getSlot(), 'runner lease slot');
        $affected = $em->createQuery(
            'UPDATE \Entities\QueueRunner r SET r.heartbeat_at = :heartbeat'
            . ' WHERE r.id = :id AND r.slot = :slot' )
            ->setParameter( 'heartbeat', $now )
            ->setParameter( 'id', $id )
            ->setParameter( 'slot', $slot )
            ->execute();
        if( $affected !== 1 )
            throw new \RuntimeException( 'Runner lease was lost before its heartbeat.' );
        $lease->setHeartbeatAt( $now );
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
    public static function reapStale( $em, ?\DateTime $now = null )
    {
        $cutoff = ( $now === null ? new \DateTime() : clone $now )
            ->modify( '-' . self::LEASE_TTL . ' seconds' );
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
