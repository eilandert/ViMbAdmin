<?php

declare(strict_types=1);

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { $failures++; }
};
$source = static function (string $relative): string {
    $contents = file_get_contents(__DIR__ . '/../' . $relative);
    if ($contents === false) {
        throw new RuntimeException('Cannot read ' . $relative);
    }
    return $contents;
};

echo "== mailbox queue set-based bulk enqueue contract ==\n";
$queue = $source('library/ViMbAdmin/MailboxQueue.php');
$controller = $source('src/Kernel/Controller/MaintenanceController.php');
$entity = $source('application/Entities/MailboxTask.php');
$migration = $source('contrib/migrations/2026-06-fork-schema.sql');

$check('bulk enqueue uses one active-mailbox insert-select',
    substr_count($queue, 'public static function enqueueAllActive') === 1
    && str_contains($queue, 'SELECT :type, m.username, :status, :priority, CURRENT_TIMESTAMP, m.Domain_id, :admin')
    && str_contains($queue, 'FROM mailbox m WHERE m.active = 1'));
$check('open-task conflicts use the existing unique invariant as no-ops',
    str_contains($queue, 'ON DUPLICATE KEY UPDATE id = mailbox_task.id'));
$check('maintenance bulk actions avoid mailbox hydration and UnitOfWork flushes',
    str_contains($controller, 'MailboxQueue::enqueueAllActive($this->em(), $type, $guard)')
    && !str_contains($controller, "getRepository('\\\\Entities\\\\Mailbox')->findBy(['active' => 1])"));
$index = 'mailbox_task_username_type_status_idx';
$check('entity and idempotent migration declare the compatible lookup index',
    str_contains($entity, "#[ORM\\Index(name: '{$index}', columns: ['username', 'type', 'status'])]")
    && substr_count($migration, "INDEX `{$index}`") === 1
    && str_contains($migration, '(`username`, `type`, `status`)'));
$check('the unique open-task invariant remains declared',
    str_contains($entity, "mailbox_task_open_unique', columns: ['username', 'type', 'open_task']")
    && str_contains($migration, 'UNIQUE INDEX `mailbox_task_open_unique`'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
