<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Helper for enqueueing mailbox-task queue jobs.
 *
 * Lives in the library (autoloaded via the ViMbAdmin_ prefix) rather than on
 * QueueController, because ZF1 does not autoload controller classes by name —
 * calling QueueController::enqueue() from another controller throws
 * "Class QueueController not found". Controllers call this instead.
 */
class ViMbAdmin_MailboxQueue
{
    /**
     * Queue a task for a mailbox. Refuses to stack a second open
     * (PENDING/RUNNING) task of the same type for the same username.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param \Entities\Mailbox $mailbox
     * @param string $type    One of MailboxTask::TYPE_*
     * @param \Entities\Admin|null $by
     * @param int $priority
     * @return \Entities\MailboxTask|null  null if an open task already exists
     */
    public static function enqueue( $em, \Entities\Mailbox $mailbox, $type, ?\Entities\Admin $by = null, $priority = 0 )
    {
        $username = $mailbox->requiredUsername();
        $domain = $mailbox->getDomain();
        $domainId = $domain !== null ? $domain->getId() : null;
        $requestedById = $by !== null ? $by->getId() : null;
        if( ( $domain !== null && $domainId === null ) || ( $by !== null && $requestedById === null ) )
            throw new \LogicException( 'Mailbox task associations must be persisted before enqueue.' );

        $connection = $em->getConnection();

        try
        {
            $affected = $connection->insert( 'mailbox_task', [
                'type'       => $type,
                'username'   => $username,
                'status'     => \Entities\MailboxTask::STATUS_PENDING,
                'priority'   => (int) $priority,
                'created_at' => ( new \DateTime() )->format( 'Y-m-d H:i:s' ),
                'Domain_id'  => $domainId,
                'Admin_id'   => $requestedById,
            ] );
        }
        catch( \Doctrine\DBAL\Exception\UniqueConstraintViolationException $e )
        {
            // The generated open_task discriminator makes the database the
            // race arbiter. If the winner commits before the wait timeout, a
            // concurrent identical enqueue reaches this duplicate-key path;
            // lock timeouts and deadlocks deliberately remain operational errors.
            return null;
        }

        if( $affected !== 1 )
            throw new \RuntimeException( 'Mailbox task insert affected an unexpected number of rows.' );

        $id = filter_var( $connection->lastInsertId(), FILTER_VALIDATE_INT, [
            'options' => [ 'min_range' => 1 ],
        ] );
        if( $id === false )
            throw new \UnexpectedValueException( 'Mailbox task insert returned an invalid identifier.' );

        $task = $em->find( '\\Entities\\MailboxTask', $id );
        if( !$task instanceof \Entities\MailboxTask || !$task->isOpen() )
            throw new \UnexpectedValueException( 'Inserted mailbox task could not be reloaded.' );

        return $task;
    }
}
