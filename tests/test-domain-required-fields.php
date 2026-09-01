<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/Entities/Admin.php';
require_once __DIR__ . '/../application/Entities/Domain.php';

function domainRequiredFieldsWithId(int $id): \Entities\Domain
{
    $domain = new \Entities\Domain();
    $method = new ReflectionMethod($domain, 'assignGeneratedId');
    $method->invoke($domain, $id);
    return $domain;
}

/** @return string|null */
function domainRequiredFieldsError(callable $operation): ?string
{
    try {
        $operation();
    } catch (LogicException $e) {
        return $e->getMessage();
    }
    return null;
}

final class DomainRequiredFieldsState
{
    public static int $failures = 0;
}

function domainRequiredFieldsCheck(string $label, bool $ok): void
{
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { DomainRequiredFieldsState::$failures++; }
}

echo "== required domain persistence fields ==\n";

$newDomain = new \Entities\Domain();
domainRequiredFieldsCheck('pre-hydration getters preserve null',
    $newDomain->getId() === null && $newDomain->getQuota() === null);
domainRequiredFieldsCheck('required id rejects a pre-hydration domain',
    domainRequiredFieldsError($newDomain->requiredId(...)) === 'Domain id cannot be null.');
domainRequiredFieldsCheck('required quota rejects an unset domain quota',
    domainRequiredFieldsError($newDomain->requiredQuota(...)) === 'Domain quota cannot be null.');

$initialized = domainRequiredFieldsWithId(17)->setQuota(2048);
domainRequiredFieldsCheck('required fields preserve initialized values',
    $initialized->requiredId() === 17 && $initialized->requiredQuota() === 2048);

$admin = new \Entities\Admin();
$assigned = domainRequiredFieldsWithId(17);
$admin->addDomain($assigned);
domainRequiredFieldsCheck('admin authorization matches persisted domain identity',
    $admin->canManageDomain(domainRequiredFieldsWithId(17)));
domainRequiredFieldsCheck('admin authorization rejects another persisted domain',
    !$admin->canManageDomain(domainRequiredFieldsWithId(18)));

$unpersistedAdmin = new \Entities\Admin();
$unpersistedAdmin->addDomain(new \Entities\Domain());
domainRequiredFieldsCheck('admin authorization rejects null ids instead of matching null to null',
    domainRequiredFieldsError(
        static fn(): bool => $unpersistedAdmin->canManageDomain(new \Entities\Domain()),
    ) === 'Domain id cannot be null.');

echo DomainRequiredFieldsState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . DomainRequiredFieldsState::$failures . " FAILED\n";
exit(DomainRequiredFieldsState::$failures === 0 ? 0 : 1);
