<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';

final class AdminAuthContractState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function adminAuthCheck(string $label, bool $condition): void
{
    AdminAuthContractState::$checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        AdminAuthContractState::$failures++;
    }
}

/** @return string|null */
function adminAuthFailure(callable $operation): ?string
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    return null;
}

echo "== required admin authentication fields ==\n";

$fresh = new \Entities\Admin();
adminAuthCheck(
    'pre-hydration getters preserve null',
    $fresh->getUsername() === null
        && $fresh->getPassword() === null
        && $fresh->getSuper() === null,
);
adminAuthCheck(
    'required username rejects pre-hydration null',
    adminAuthFailure($fresh->requiredUsername(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'email identity rejects pre-hydration null',
    adminAuthFailure($fresh->getEmail(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'formatted identity rejects pre-hydration null',
    adminAuthFailure($fresh->getFormattedName(...)) === 'Admin username cannot be null.',
);
adminAuthCheck(
    'required password rejects pre-hydration null',
    adminAuthFailure($fresh->requiredPassword(...)) === 'Admin password cannot be null.',
);
adminAuthCheck('pre-hydration super check denies privilege', $fresh->isSuper() === false);

$options = ['pwhash' => 'crypt:sha512'];
$hash = \OSS_Auth_Password::hash('correct horse', $options);
$initialized = (new \Entities\Admin())
    ->setUsername('admin@example.test')
    ->setPassword($hash)
    ->setSuper(true);
adminAuthCheck(
    'initialized identity helpers preserve the username',
    $initialized->requiredUsername() === 'admin@example.test'
        && $initialized->getEmail() === 'admin@example.test'
        && $initialized->getFormattedName() === 'admin@example.test',
);
adminAuthCheck('initialized required password preserves the hash', $initialized->requiredPassword() === $hash);
adminAuthCheck('initialized super check preserves privilege', $initialized->isSuper() === true);

$adminMatcher = new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AdminController::class, 'adminPasswordMatches');
$authMatcher = new ReflectionMethod(\ViMbAdmin\Kernel\Controller\AuthController::class, 'adminPasswordMatches');
adminAuthCheck(
    'admin password form rejects an uninitialized hash',
    $adminMatcher->invoke(null, $fresh, 'correct horse', $options) === false,
);
adminAuthCheck(
    'login rejects an uninitialized hash',
    $authMatcher->invoke(null, $fresh, 'correct horse', $options) === false,
);
adminAuthCheck(
    'login rejects malformed password configuration',
    $authMatcher->invoke(null, $initialized, 'correct horse', null) === false,
);
adminAuthCheck(
    'both authentication boundaries accept the initialized matching hash',
    $adminMatcher->invoke(null, $initialized, 'correct horse', $options) === true
        && $authMatcher->invoke(null, $initialized, 'correct horse', $options) === true,
);
adminAuthCheck(
    'both authentication boundaries reject an incorrect password',
    $adminMatcher->invoke(null, $initialized, 'wrong horse', $options) === false
        && $authMatcher->invoke(null, $initialized, 'wrong horse', $options) === false,
);
adminAuthCheck('fixed assertion count', AdminAuthContractState::$checks === 14);

echo AdminAuthContractState::$failures === 0
    ? "ALL PASSED\n"
    : 'FAIL: ' . AdminAuthContractState::$failures . " assertion(s) failed\n";
exit(AdminAuthContractState::$failures === 0 ? 0 : 1);
