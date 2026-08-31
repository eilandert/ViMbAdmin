<?php
/** Focused contract test for the framework-free mutation context. */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Session/MagicPropertyStorage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessages.php';
require __DIR__ . '/../library/ViMbAdmin/Plugin/MutationContext.php';
require __DIR__ . '/../src/Kernel/Plugin/AbstractContext.php';

use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Plugin\AbstractContext;
use ViMbAdmin\Kernel\Session\MagicPropertyStorage;

$failures = 0;
function checkMutationContext(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $GLOBALS['failures']++;
    }
}

$session = new stdClass();
$flash = new FlashMessages(new MagicPropertyStorage($session));
$em = new stdClass();
$admin = new stdClass();
$domain = new stdClass();
$callback = static fn(string $value): string => strtoupper($value);
$options = ['feature' => ['enabled' => true], 'limit' => 0, 'callback' => $callback];
$context = new class($em, $admin, $domain, $options, $flash) extends AbstractContext {};

checkMutationContext('returns the complete typed options map', $context->getOptions() === $options);
checkMutationContext('preserves configured callbacks by identity', $context->getOptions()['callback'] === $callback);
checkMutationContext('returns each mutation dependency unchanged',
    $context->getD2EM() === $em && $context->getAdmin() === $admin && $context->getDomain() === $domain);

checkMutationContext('accepts a message with the legacy default class and type', $context->addMessage('created') === null);
$message = $flash->peek()[0] ?? null;
checkMutationContext('queues default messages as success', $message !== null && $message->text === 'created' && $message->level === FlashMessages::SUCCESS);

checkMutationContext('accepts legacy error class and block type', $context->addMessage('failed', FlashMessages::ERROR, 1) === null);
$message = $flash->peek()[1] ?? null;
checkMutationContext('preserves the error class while ignoring the legacy type', $message !== null && $message->text === 'failed' && $message->level === FlashMessages::ERROR);

checkMutationContext('accepts an empty class at the boundary', $context->addMessage('empty', '') === null);
$message = $flash->peek()[2] ?? null;
checkMutationContext('uses success for an empty class', $message !== null && $message->level === FlashMessages::SUCCESS);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
