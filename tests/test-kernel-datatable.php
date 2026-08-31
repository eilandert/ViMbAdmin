<?php
/**
 * Unit test: ViMbAdmin\Kernel\DataTable\{DataTableQuery,DataTableResult}.
 *
 * Pure parsing + envelope logic for the DataTables server-side protocol — no
 * framework, no DB. Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/DataTable/DataTableQuery.php';
require __DIR__ . '/../src/Kernel/DataTable/DataTableResult.php';

use ViMbAdmin\Kernel\DataTable\DataTableQuery;
use ViMbAdmin\Kernel\DataTable\DataTableResult;

final class DataTableTestState
{
    public static int $failures = 0;
}

function dataTableCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) { DataTableTestState::$failures++; }
}

/**
 * @return array{
 *   sEcho:int,
 *   iTotalRecords:int,
 *   iTotalDisplayRecords:int,
 *   aaData:list<array<string,mixed>>
 * }
 */
function dataTableEnvelope(mixed $value): array
{
    if (!is_array($value)
        || !is_int($value['sEcho'] ?? null)
        || !is_int($value['iTotalRecords'] ?? null)
        || !is_int($value['iTotalDisplayRecords'] ?? null)) {
        throw new \RuntimeException('malformed DataTable envelope');
    }

    return [
        'sEcho' => $value['sEcho'],
        'iTotalRecords' => $value['iTotalRecords'],
        'iTotalDisplayRecords' => $value['iTotalDisplayRecords'],
        'aaData' => dataTableRows($value['aaData'] ?? null),
    ];
}

/** @return list<array<string,mixed>> */
function dataTableRows(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new \RuntimeException('malformed DataTable rows');
    }

    $rows = [];
    foreach ($value as $row) {
        if (!is_array($row)) {
            throw new \RuntimeException('malformed DataTable row');
        }

        $typedRow = [];
        foreach ($row as $key => $cell) {
            if (!is_string($key)) {
                throw new \RuntimeException('malformed DataTable row key');
            }
            $typedRow[$key] = $cell;
        }
        $rows[] = $typedRow;
    }

    return $rows;
}

echo "== DataTable server-side protocol ==\n";

// --- DataTableQuery::fromArray ---------------------------------------------
$q = DataTableQuery::fromArray([
    'sEcho' => '3', 'iDisplayStart' => '20', 'iDisplayLength' => '25',
    'sSearch' => '  foo ', 'iSortCol_0' => '2', 'sSortDir_0' => 'desc',
]);
dataTableCheck('echo parsed',            $q->echo === 3);
dataTableCheck('start parsed',           $q->start === 20);
dataTableCheck('length parsed',          $q->length === 25);
dataTableCheck('search trimmed',         $q->search === 'foo');
dataTableCheck('sort column parsed',     $q->sortColumn === 2);
dataTableCheck('sort dir normalised',    $q->sortDir === 'DESC');

$d = DataTableQuery::fromArray([]);
dataTableCheck('defaults: echo 1',       $d->echo === 1);
dataTableCheck('defaults: start 0',      $d->start === 0);
dataTableCheck('defaults: length 10',    $d->length === 10);
dataTableCheck('defaults: dir ASC',      $d->sortDir === 'ASC');

dataTableCheck('negative start clamped', DataTableQuery::fromArray(['iDisplayStart' => '-5'])->start === 0);
dataTableCheck('length -1 (All) capped', DataTableQuery::fromArray(['iDisplayLength' => '-1'])->length === DataTableQuery::MAX_LENGTH);
dataTableCheck('over-cap length capped', DataTableQuery::fromArray(['iDisplayLength' => '99999'])->length === DataTableQuery::MAX_LENGTH);
dataTableCheck('zero length -> 10',      DataTableQuery::fromArray(['iDisplayLength' => '0'])->length === 10);
dataTableCheck('bad sort dir -> ASC',    DataTableQuery::fromArray(['sSortDir_0' => 'nonsense'])->sortDir === 'ASC');
dataTableCheck('echo cast to int',       DataTableQuery::fromArray(['sEcho' => '7; DROP'])->echo === 7);

// --- DataTableResult::envelope ---------------------------------------------
$rows = [['id' => 1, 'username' => 'a@b.c'], ['id' => 2, 'username' => 'd@e.f']];
$env  = dataTableEnvelope(DataTableResult::envelope($q, 100, 42, $rows));
dataTableCheck('envelope echoes sEcho',           $env['sEcho'] === 3);
dataTableCheck('envelope total',                  $env['iTotalRecords'] === 100);
dataTableCheck('envelope filtered',               $env['iTotalDisplayRecords'] === 42);
dataTableCheck('envelope carries page rows',      $env['aaData'] === $rows);

$json = DataTableResult::json($q, 100, 42, $rows);
$back = dataTableEnvelope(json_decode($json, true));
dataTableCheck('json round-trips',       $back['iTotalDisplayRecords'] === 42 && $back['aaData'][1]['username'] === 'd@e.f');

echo DataTableTestState::$failures === 0
    ? "\nALL PASSED\n"
    : "\n" . DataTableTestState::$failures . " FAILED\n";
exit(DataTableTestState::$failures === 0 ? 0 : 1);
