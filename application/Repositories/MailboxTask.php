<?php

namespace Repositories;

use DateTime;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * MailboxTask repository.
 *
 * Holds the queue-claim logic: a single atomic UPDATE flips a task from
 * PENDING to RUNNING and returns whether *this* runner won the race, so two
 * concurrent runners never process the same task.
 *
 * @extends EntityRepository<\Entities\MailboxTask>
 */
class MailboxTask extends EntityRepository
{
    /** @return array<string,int> */
    private static function requiredStatusCounts(mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('Mailbox task status query result must be an array.');
        }

        $counts = [];
        foreach ($rows as $key => $row) {
            if (!is_int($key) || !is_array($row) || count($row) !== 2
                || !isset($row['status']) || !is_string($row['status'])
                || !array_key_exists('cnt', $row)) {
                throw new \UnexpectedValueException('Mailbox task status query row has an invalid shape.');
            }

            $rawCount = $row['cnt'];
            if (is_int($rawCount) && $rawCount >= 0) {
                $count = $rawCount;
            } elseif (is_string($rawCount) && preg_match('/^(0|[1-9][0-9]*)$/D', $rawCount) === 1) {
                $count = filter_var($rawCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count === false) {
                    throw new \UnexpectedValueException('Mailbox task status query row has an invalid count.');
                }
            } else {
                throw new \UnexpectedValueException('Mailbox task status query row has an invalid count.');
            }
            if (array_key_exists($row['status'], $counts)) {
                throw new \UnexpectedValueException('Mailbox task status query returned a duplicate status.');
            }
            $counts[$row['status']] = $count;
        }
        return $counts;
    }

    /**
     * Fetch the oldest PENDING tasks (highest priority first), up to $limit.
     *
     * @param int $limit
     * @return \Entities\MailboxTask[]
     */
    public function pending( $limit = 5 )
    {
        return \ViMbAdmin\Kernel\Doctrine\ResultValidator::entityList(
            $this->pendingQuery( (int) $limit )->getQuery()->getResult(),
            \Entities\MailboxTask::class,
            'Pending mailbox task query'
        );
    }

    private function pendingQuery( int $limit ): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select( 't' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->where( 't.status = :s' )
            ->setParameter( 's', \Entities\MailboxTask::STATUS_PENDING )
            ->orderBy( 't.priority', 'DESC' )
            ->addOrderBy( 't.id', 'ASC' )
            ->setMaxResults( $limit );
    }

    /**
     * Atomically claim a task: PENDING -> RUNNING. Returns true only if this
     * call won the row (affected exactly one row). Mirrors ArchiveController's
     * _archiveStateChange guard but at the SQL level so it is race-safe.
     *
     * @param \Entities\MailboxTask $task
     * @return bool
     */
    public function claim( \Entities\MailboxTask $task )
    {
        $conn = $this->getEntityManager()->getConnection();
        $affected = $conn->executeStatement(
            'UPDATE mailbox_task SET status = :running, started_at = :now'
            . ' WHERE id = :id AND status = :pending',
            [
                'running' => \Entities\MailboxTask::STATUS_RUNNING,
                'now'     => ( new DateTime() )->format( 'Y-m-d H:i:s' ),
                'id'      => $task->getId(),
                'pending' => \Entities\MailboxTask::STATUS_PENDING,
            ]
        );

        if( $affected === 1 )
        {
            // Refresh the managed entity so in-memory state matches the DB.
            $this->getEntityManager()->refresh( $task );
            return true;
        }
        return false;
    }

    /**
     * Counts of tasks grouped by status (for the queue tab summary).
     *
     * @return array<string,int>
     */
    public function statusCounts()
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select( 't.status as status, COUNT(t.id) as cnt' )
            ->from( '\\Entities\\MailboxTask', 't' )
            ->groupBy( 't.status' )
            ->getQuery()->getArrayResult();

        return self::requiredStatusCounts($rows);
    }
}
