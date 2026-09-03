<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\DataTable;

/**
 * Parsed DataTables (legacy "server-side processing" protocol, as shipped by
 * the bundled DataTables 1.11.5 via `fnServerData`) request parameters.
 *
 * The list pages render server-side paged: the table is configured with
 * `bServerProcessing` + an AJAX source, and DataTables sends the draw counter
 * (`sEcho`), the window (`iDisplayStart` / `iDisplayLength`), the global filter
 * (`sSearch`) and the active sort column/direction (`iSortCol_0` / `sSortDir_0`)
 * on every interaction. A controller turns these into a scoped, paged Doctrine
 * query and answers with {@see DataTableResult::envelope()}.
 *
 * Pure value object (no superglobals, no framework) so it is unit-testable; the
 * controller passes `$_GET`. `length` is clamped to a sane maximum so a crafted
 * `iDisplayLength` cannot ask for an unbounded result set.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DataTableQuery
{
    public const MAX_LENGTH = 500;

    private function __construct(
        public readonly int $echo,
        public readonly int $start,
        public readonly int $length,
        public readonly string $search,
        public readonly int $sortColumn,
        public readonly string $sortDir,
    ) {
    }

    /**
     * Build from a DataTables request array (typically `$_GET`).
     *
     * @param array<string,mixed> $p
     * @param int $minimumSearchLength Nonempty searches shorter than this are
     *        rejected; zero explicitly disables the minimum.
     * @throws \LengthException when a nonempty search is below the minimum
     * @throws \TypeError when a request parameter has the wrong scalar type
     */
    public static function fromArray(array $p, int $minimumSearchLength = 0): self
    {
        if ($minimumSearchLength < 0) {
            throw new \LogicException('Minimum search length must be non-negative');
        }
        $echo   = self::integer($p['sEcho'] ?? null, 1, 'sEcho');
        $start  = max(0, self::integer($p['iDisplayStart'] ?? null, 0, 'iDisplayStart'));

        $length = self::integer($p['iDisplayLength'] ?? null, 10, 'iDisplayLength');
        // -1 ("All") and anything over the cap collapse to the cap; <=0 to 10.
        if ($length <= 0 || $length > self::MAX_LENGTH) {
            $length = $length === -1 ? self::MAX_LENGTH : ($length <= 0 ? 10 : self::MAX_LENGTH);
        }

        $searchValue = $p['sSearch'] ?? null;
        if ($searchValue !== null && !is_string($searchValue)) {
            throw new \TypeError('sSearch must be a string');
        }
        $search = trim($searchValue ?? '');
        if ($search !== '' && mb_strlen($search, 'UTF-8') < $minimumSearchLength) {
            throw new \LengthException("Search must be empty or at least {$minimumSearchLength} characters");
        }
        $sortCol = max(0, self::integer($p['iSortCol_0'] ?? null, 0, 'iSortCol_0'));
        $sortDirection = $p['sSortDir_0'] ?? null;
        if ($sortDirection !== null && !is_string($sortDirection)) {
            throw new \TypeError('sSortDir_0 must be a string');
        }
        $sortDir = strtoupper($sortDirection ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';

        return new self($echo, $start, $length, $search, $sortCol, $sortDir);
    }

    private static function integer(mixed $value, int $default, string $name): int
    {
        if ($value === null) return $default;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false) return $parsed;
        }
        throw new \TypeError($name . ' must be an integer');
    }
}
