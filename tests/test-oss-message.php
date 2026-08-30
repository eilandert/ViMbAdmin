<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../library/OSS/Message.php';
require __DIR__ . '/../library/OSS/Message/Block.php';
require __DIR__ . '/../library/OSS/Message/Pop/Up.php';
require __DIR__ . '/../library/OSS/Smarty/functions/function.OSS_Message.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures++; }
};

final class MessageSmartyDouble extends \Smarty\Smarty
{
    /** @var array<string,mixed> */
    private array $vars;

    /** @param array<string,mixed> $vars */
    public function __construct(array $vars)
    {
        parent::__construct();
        $this->vars = $vars;
    }

    public function getTemplateVars($varName = null, $searchParents = true): mixed
    {
        if (!is_string($varName)) {
            return null;
        }

        return $this->vars[$varName] ?? null;
    }
}

$_SESSION = [];

echo "== OSS message ==\n";

$plain = new OSS_Message('<b>Saved</b>', OSS_Message::ALERT, true);
$check('ALERT normalizes to warning', $plain->getClass() === OSS_Message::WARNING);
$check('plaintext strips tags for HTML messages', $plain->getPlaintext() === 'Saved');

$block = new OSS_Message_Block('Block body', OSS_Message::SUCCESS, false);
$check('block type is set', $block->getType() === OSS_Message::TYPE_BLOCK);
$check('block actions default null', $block->getActions() === null);
$block->addAction('<a href="/undo">Undo</a>');
$block->addAction('<a href="/retry">Retry</a>');
$check('block actions preserve order', $block->getActions() === ['<a href="/undo">Undo</a>', '<a href="/retry">Retry</a>']);

$popup = new OSS_Message_Pop_Up(['One', 'Two'], OSS_Message::INFO, false);
$check('popup type is set', $popup->getType() === OSS_Message::TYPE_POP_UP);
$check('popup actions default null', $popup->getActions() === null);

$smarty = new MessageSmartyDouble([
    'OSS_Messages' => [
        $block,
        $popup,
        new OSS_Message(['Alpha', 'Beta'], OSS_Message::ERROR, false),
    ],
]);

$rendered = smarty_function_OSS_Message([], $smarty);
$check('block render includes actions container', str_contains($rendered, 'alert-actions') && str_contains($rendered, 'Undo') && str_contains($rendered, 'Retry'));
$check('popup render emits each bootbox item', str_contains($rendered, 'bootbox.alert(') && str_contains($rendered, "'One'") && str_contains($rendered, "'Two'"));
$check('plain render emits each message item', str_contains($rendered, 'Alpha') && str_contains($rendered, 'Beta') && str_contains($rendered, 'alert-error'));

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all OSS_Message assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
