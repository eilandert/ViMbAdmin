<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * QueueRunner
 *
 * A short-lived lease row representing one ACTIVE queue runner. The runner
 * concurrency cap (queue.runner.max_concurrent) is enforced by counting the
 * non-stale rows in this table before starting a new drain. Each running drain
 * inserts a row, heartbeats it, and deletes it on exit; a row whose heartbeat
 * has gone stale (the process died) is reaped so a slot is never lost forever.
 */
#[ORM\Entity]
#[ORM\Table(name: 'queue_runner')]
#[ORM\Index(name: 'queue_runner_heartbeat_idx', columns: ['heartbeat_at'])]
class QueueRunner
{
    /** @var integer */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    protected function assignGeneratedId(int $id): void
    {
        $this->id = $id;
    }

    /** @var string */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $host = null;

    /** @var integer */
    #[ORM\Column(type: 'integer')]
    private ?int $pid = null;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $started_at = null;

    /** @var \DateTime */
    #[ORM\Column(type: 'datetime')]
    private ?\DateTime $heartbeat_at = null;

    /** @return int|null */
    public function getId()              { return $this->id; }

    /** @return string|null */
    public function getHost()            { return $this->host; }
    /**
     * @param string $v
     * @return $this
     */
    public function setHost( $v )        { $this->host = $v; return $this; }

    /** @return int|null */
    public function getPid()             { return $this->pid; }
    /**
     * @param int $v
     * @return $this
     */
    public function setPid( $v )         { $this->pid = (int) $v; return $this; }

    /** @return \DateTime|null */
    public function getStartedAt()       { return $this->started_at; }
    /**
     * @param \DateTime $v
     * @return $this
     */
    public function setStartedAt( $v )   { $this->started_at = $v; return $this; }

    /** @return \DateTime|null */
    public function getHeartbeatAt()     { return $this->heartbeat_at; }
    /**
     * @param \DateTime $v
     * @return $this
     */
    public function setHeartbeatAt( $v ) { $this->heartbeat_at = $v; return $this; }
}
