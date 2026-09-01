#!/usr/bin/env php
<?php

declare(strict_types=1);

const FIELDS = [
    'head', 'baseline_sha', 'path', 'line', 'class', 'method', 'property',
    'id', 'count', 'old', 'new', 'status', 'reject_reason', 'file_sha',
];

/** @return never */
function fail(string $message, int $code = 2): void
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @param list<string> $argv
 * @return array{apply:bool,format:string,repo:string,head:?string,sites:?int,diagnostics:?int,allows:list<string>}
 */
function options(array $argv): array
{
    $result = [
        'apply' => false,
        'format' => 'json',
        'repo' => getcwd() ?: '.',
        'head' => null,
        'sites' => null,
        'diagnostics' => null,
        'allows' => [],
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') {
            $result['apply'] = true;
            continue;
        }
        if ($argument === '--help') {
            echo "usage: tools/phpstan-codemod.php [--apply] --repo=DIR --format=json|tsv\n";
            echo "       --expect-head=SHA --allow=FILE:METHOD@FILE_SHA [--allow=...]\n";
            echo "       --apply also requires --expect-sites=N --expect-diagnostics=N\n";
            exit(0);
        }
        $pairs = [
            '--format=' => 'format', '--repo=' => 'repo', '--expect-head=' => 'head',
            '--expect-sites=' => 'sites', '--expect-diagnostics=' => 'diagnostics',
        ];
        $matched = false;
        foreach ($pairs as $prefix => $key) {
            if (str_starts_with($argument, $prefix)) {
                $value = substr($argument, strlen($prefix));
                if ($key === 'sites' || $key === 'diagnostics') {
                    if ($value === '' || !ctype_digit($value)) {
                        fail("invalid $prefix value");
                    }
                    $result[$key] = (int) $value;
                } else {
                    $result[$key] = $value;
                }
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }
        if (str_starts_with($argument, '--allow=')) {
            $result['allows'][] = substr($argument, strlen('--allow='));
            continue;
        }
        fail("unknown argument: $argument");
    }
    if (!in_array($result['format'], ['json', 'tsv'], true)) {
        fail('format must be json or tsv');
    }
    if ($result['head'] === null || $result['head'] === '') {
        fail('--expect-head is required');
    }
    if ($result['allows'] === []) {
        fail('at least one --allow=FILE:METHOD@FILE_SHA is required');
    }
    if ($result['apply'] && ($result['sites'] === null || $result['diagnostics'] === null)) {
        fail('--apply requires --expect-sites and --expect-diagnostics');
    }
    return $result;
}

function gitHead(string $repo): string
{
    $pipes = [];
    $process = proc_open(
        ['git', '-C', $repo, 'rev-parse', 'HEAD'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) { fail("cannot start Git for $repo"); }
    $head = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !preg_match('/^[0-9a-f]{40}$/', $head)) {
        fail("cannot resolve Git HEAD for $repo");
    }
    return $head;
}

/** @return array{path:string,method:string,sha:string} */
function parseAllow(string $allow): array
{
    $at = strrpos($allow, '@');
    $colon = $at === false ? false : strrpos(substr($allow, 0, $at), ':');
    if ($at === false || $colon === false) {
        fail("invalid allowlist entry: $allow");
    }
    $path = substr($allow, 0, $colon);
    $method = substr($allow, $colon + 1, $at - $colon - 1);
    $sha = substr($allow, $at + 1);
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')
        || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)
        || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
        fail("invalid allowlist entry: $allow");
    }
    return ['path' => $path, 'method' => $method, 'sha' => $sha];
}

/** @return list<array{path:string,id:string,count:int,message:string,class:?string,method:?string,atom:?string}> */
function baselineEntries(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fail("cannot read baseline: $file");
    }
    $entries = [];
    $current = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        foreach (['message', 'identifier', 'count', 'path'] as $key) {
            $prefix = $key . ':';
            if (str_starts_with($trimmed, $prefix)) {
                $current[$key] = trim(substr($trimmed, strlen($prefix)));
            }
        }
        if (isset($current['message'], $current['identifier'], $current['count'], $current['path'])) {
            $message = trim($current['message'], "'\"");
            $plain = str_replace(['#^', '$#'], '', $message);
            $plain = preg_replace('/\\\\([:\\(\\)\\.\\|])/', '$1', $plain) ?? $plain;
            $plain = str_replace('\\\\', '\\', $plain);
            $class = $method = $atom = null;
            if (preg_match('/^Method ([A-Za-z0-9_\\\\]+)::([A-Za-z_][A-Za-z0-9_]*)\(\) should return ([A-Za-z0-9_\\\\]+) but returns \\3\|null\.$/', $plain, $match)) {
                [, $class, $method, $atom] = $match;
            }
            $entries[] = [
                'path' => $current['path'],
                'id' => $current['identifier'],
                'count' => ctype_digit($current['count']) ? (int) $current['count'] : -1,
                'message' => $plain,
                'class' => $class,
                'method' => $method,
                'atom' => $atom,
            ];
            $current = [];
        }
    }
    return $entries;
}

/** @return list<array{id:int|string|null,text:string,start:int,line:int}> */
function tokenSpans(string $source): array
{
    $result = [];
    $offset = 0;
    $line = 1;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (is_array($token)) {
            [$id, $text, $tokenLine] = $token;
        } else {
            $id = null;
            $text = $token;
            $tokenLine = $line;
        }
        $result[] = ['id' => $id, 'text' => $text, 'start' => $offset, 'line' => $tokenLine];
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    return $result;
}

/** @param array{id:int|string|null,text:string,start:int,line:int} $token */
function insignificant(array $token): bool
{
    return $token['id'] === T_WHITESPACE;
}

/**
 * @param list<array{id:int|string|null,text:string,start:int,line:int}> $tokens
 * @return array<string,array{nullable:bool,null:bool,type:string}>
 */
function properties(array $tokens, int $classOpen, int $classClose): array
{
    $result = [];
    $depth = 1;
    for ($i = $classOpen + 1; $i < $classClose; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '{') { $depth++; continue; }
        if ($text === '}') { $depth--; continue; }
        if ($depth !== 1 || !in_array($tokens[$i]['id'], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
            continue;
        }
        $end = $i;
        while ($end < $classClose && $tokens[$end]['text'] !== ';' && $tokens[$end]['text'] !== '{') {
            $end++;
        }
        if ($end >= $classClose || $tokens[$end]['text'] !== ';') { continue; }
        $variable = null;
        $variableIndex = null;
        for ($j = $i; $j < $end; $j++) {
            if ($tokens[$j]['id'] === T_FUNCTION) { $variable = null; break; }
            if ($tokens[$j]['id'] === T_VARIABLE) {
                $variable = substr($tokens[$j]['text'], 1);
                $variableIndex = $j;
                break;
            }
        }
        if ($variable === null || $variableIndex === null) { continue; }
        $nullable = false;
        $type = '';
        for ($j = $i; $j < $variableIndex; $j++) {
            if ($tokens[$j]['text'] === '?') { $nullable = true; }
            if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $type .= $tokens[$j]['text'];
            }
        }
        $initializedNull = false;
        for ($j = $variableIndex + 1; $j < $end; $j++) {
            if ($tokens[$j]['text'] !== '=') { continue; }
            for ($j++; $j < $end && insignificant($tokens[$j]); $j++) {}
            $initializedNull = $j < $end && $tokens[$j]['id'] === T_STRING
                && strtolower($tokens[$j]['text']) === 'null';
            break;
        }
        $result[$variable] = ['nullable' => $nullable, 'null' => $initializedNull, 'type' => $type];
        $i = $end;
    }
    return $result;
}

/**
 * @param array{id:int|string|null,text:string,start:int,line:int} $doc
 * @return array{atom:string,start:int,end:int}|array{reject:string}
 */
function docReturn(array $doc): array
{
    $text = $doc['text'];
    $positions = [];
    $offset = 0;
    while (($position = strpos($text, '@return', $offset)) !== false) {
        $positions[] = $position;
        $offset = $position + 7;
    }
    if (count($positions) !== 1) { return ['reject' => 'doc_return_count']; }
    $cursor = $positions[0] + 7;
    while (isset($text[$cursor]) && ($text[$cursor] === ' ' || $text[$cursor] === "\t")) { $cursor++; }
    $start = $cursor;
    while (isset($text[$cursor]) && !in_array($text[$cursor], [' ', "\t", "\r", "\n", '*'], true)) { $cursor++; }
    $atom = substr($text, $start, $cursor - $start);
    $editEnd = $cursor;
    while (isset($text[$editEnd]) && ($text[$editEnd] === ' ' || $text[$editEnd] === "\t")) { $editEnd++; }
    if (!isset($text[$editEnd]) || !in_array($text[$editEnd], ["\r", "\n"], true)) {
        $editEnd = $cursor;
    }
    $restEnd = strpos($text, "\n", $cursor);
    $rest = substr($text, $cursor, ($restEnd === false ? strlen($text) : $restEnd) - $cursor);
    if ($atom === '' || trim($rest, " \t\r*/") !== '') { return ['reject' => 'doc_return_not_single_atom']; }
    if (str_contains(strtolower($atom), 'null')) { return ['reject' => 'already_nullable']; }
    return [
        'atom' => $atom,
        'start' => $doc['start'] + $start,
        'end' => $doc['start'] + $editEnd,
    ];
}

function normalizeAtom(string $atom): string
{
    $atom = strtolower(ltrim($atom, '\\'));
    return ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'][$atom] ?? $atom;
}

/**
 * @return array{reject:string}|array{line:int,class:string,property:string,old:string,new:string,start:int,end:int}
 */
function inspectMethod(string $source, string $method, string $expectedClass, string $expectedAtom): array
{
    try {
        $tokens = tokenSpans($source);
    } catch (ParseError) {
        return ['reject' => 'source_parse_error'];
    }
    $namespace = '';
    for ($i = 0; $i < count($tokens); $i++) {
        if ($tokens[$i]['id'] !== T_NAMESPACE) { continue; }
        for ($j = $i + 1; $j < count($tokens) && !in_array($tokens[$j]['text'], [';', '{'], true); $j++) {
            if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)
                || $tokens[$j]['id'] === T_NS_SEPARATOR) {
                $namespace .= $tokens[$j]['text'];
            }
        }
        break;
    }
    $className = null;
    $classOpen = $classClose = null;
    for ($i = 0; $i < count($tokens); $i++) {
        if ($tokens[$i]['id'] !== T_CLASS) { continue; }
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j]['id'] === T_STRING) { $className = $tokens[$j]['text']; }
            if ($tokens[$j]['text'] === '{') { $classOpen = $j; break; }
        }
        if ($classOpen !== null) {
            $depth = 1;
            for ($j = $classOpen + 1; $j < count($tokens); $j++) {
                if ($tokens[$j]['text'] === '{') { $depth++; }
                if ($tokens[$j]['text'] === '}' && --$depth === 0) { $classClose = $j; break; }
            }
            break;
        }
    }
    $qualifiedClass = $className === null ? null
        : ($namespace === '' ? $className : $namespace . '\\' . $className);
    if ($className === null || $classOpen === null || $classClose === null
        || ltrim($expectedClass, '\\') !== $qualifiedClass) {
        return ['reject' => 'class_mismatch'];
    }
    $propertyMap = properties($tokens, $classOpen, $classClose);
    $depth = 1;
    for ($i = $classOpen + 1; $i < $classClose; $i++) {
        if ($tokens[$i]['text'] === '{') { $depth++; continue; }
        if ($tokens[$i]['text'] === '}') { $depth--; continue; }
        if ($depth !== 1 || $tokens[$i]['id'] !== T_FUNCTION) { continue; }
        $nameIndex = null;
        for ($j = $i + 1; $j < $classClose; $j++) {
            if ($tokens[$j]['id'] === T_STRING) { $nameIndex = $j; break; }
            if ($tokens[$j]['text'] === '(') { break; }
        }
        if ($nameIndex === null || $tokens[$nameIndex]['text'] !== $method) { continue; }
        $openParen = $nameIndex + 1;
        while ($tokens[$openParen]['text'] !== '(') { $openParen++; }
        $parenDepth = 1;
        for ($closeParen = $openParen + 1; $closeParen < $classClose; $closeParen++) {
            if ($tokens[$closeParen]['text'] === '(') { $parenDepth++; }
            if ($tokens[$closeParen]['text'] === ')' && --$parenDepth === 0) { break; }
        }
        for ($j = $openParen + 1; $j < $closeParen; $j++) {
            if (!insignificant($tokens[$j])) { return ['reject' => 'method_has_parameters']; }
        }
        $bodyOpen = $closeParen + 1;
        $native = [];
        while ($bodyOpen < $classClose && $tokens[$bodyOpen]['text'] !== '{') {
            if (!insignificant($tokens[$bodyOpen])) { $native[] = $tokens[$bodyOpen]; }
            $bodyOpen++;
        }
        if ($native !== [] && $native[0]['text'] === ':' && !in_array('?', array_column($native, 'text'), true)
            && !in_array('null', array_map('strtolower', array_column($native, 'text')), true)) {
            return ['reject' => 'native_non_nullable_return'];
        }
        $bodyDepth = 1;
        for ($bodyClose = $bodyOpen + 1; $bodyClose < $classClose; $bodyClose++) {
            if ($tokens[$bodyClose]['text'] === '{') { $bodyDepth++; }
            if ($tokens[$bodyClose]['text'] === '}' && --$bodyDepth === 0) { break; }
        }
        $meaningful = [];
        for ($j = $bodyOpen + 1; $j < $bodyClose; $j++) {
            if (!insignificant($tokens[$j])) { $meaningful[] = $tokens[$j]; }
        }
        $exact = count($meaningful) === 5
            && $meaningful[0]['id'] === T_RETURN
            && $meaningful[1]['id'] === T_VARIABLE && $meaningful[1]['text'] === '$this'
            && $meaningful[2]['id'] === T_OBJECT_OPERATOR
            && $meaningful[3]['id'] === T_STRING
            && $meaningful[4]['text'] === ';';
        if (!$exact) { return ['reject' => 'getter_body_not_exact']; }
        $property = $meaningful[3]['text'];
        if (!isset($propertyMap[$property]) || !$propertyMap[$property]['nullable']
            || !$propertyMap[$property]['null'] || strtolower($propertyMap[$property]['type']) === 'mixed') {
            return ['reject' => 'property_not_explicit_nullable_null'];
        }
        $doc = null;
        for ($j = $i - 1; $j > $classOpen; $j--) {
            if ($tokens[$j]['id'] === T_DOC_COMMENT) { $doc = $tokens[$j]; break; }
            if (in_array($tokens[$j]['text'], [';', '}'], true)) { break; }
        }
        if ($doc === null) { return ['reject' => 'missing_method_doc']; }
        $return = docReturn($doc);
        if (isset($return['reject'])) { return $return; }
        if (normalizeAtom($return['atom']) !== normalizeAtom($expectedAtom)) {
            return ['reject' => 'doc_atom_diagnostic_mismatch'];
        }
        return [
            'line' => $tokens[$i]['line'], 'class' => $expectedClass, 'property' => $property,
            'old' => $return['atom'], 'new' => $return['atom'] . '|null',
            'start' => $return['start'], 'end' => $return['end'],
        ];
    }
    return ['reject' => 'method_not_found'];
}

/**
 * @param list<array<string,int|string>> $records
 * @param array<string,int|string> $meta
 */
function emit(array $records, array $meta, string $format): void
{
    if ($format === 'json') {
        echo json_encode(['meta' => $meta, 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }
    echo implode("\t", FIELDS) . "\n";
    foreach ($records as $record) {
        $values = [];
        foreach (FIELDS as $field) {
            $values[] = str_replace(["\t", "\r", "\n"], ' ', (string) ($record[$field] ?? ''));
        }
        echo implode("\t", $values) . "\n";
    }
}

/** @param array<string,string> $contents */
function atomicWrite(array $contents): void
{
    $temps = $backups = [];
    try {
        foreach ($contents as $file => $content) {
            $temp = tempnam(dirname($file), '.phpstan-codemod.');
            if ($temp === false || file_put_contents($temp, $content) === false) {
                throw new RuntimeException("cannot stage $file");
            }
            $permissions = fileperms($file);
            if ($permissions === false || !chmod($temp, $permissions & 0777)) {
                throw new RuntimeException("cannot preserve mode for $file");
            }
            $temps[$file] = $temp;
            $original = file_get_contents($file);
            if ($original === false) { throw new RuntimeException("cannot back up $file"); }
            $backups[$file] = $original;
        }
        $written = [];
        foreach ($temps as $file => $temp) {
            if (!rename($temp, $file)) { throw new RuntimeException("cannot replace $file"); }
            $written[] = $file;
        }
    } catch (Throwable $error) {
        foreach ($backups as $file => $content) {
            if (isset($written) && in_array($file, $written, true)) {
                file_put_contents($file, $content);
            }
        }
        foreach ($temps as $temp) { if (is_file($temp)) { unlink($temp); } }
        fail('atomic write failed: ' . $error->getMessage(), 1);
    }
}

$options = options($argv);
$repo = realpath($options['repo']);
if ($repo === false) { fail('repository does not exist'); }
$head = gitHead($repo);
$baseline = $repo . '/phpstan-baseline.neon';
$baselineSha = is_file($baseline) ? hash_file('sha256', $baseline) : false;
if ($baselineSha === false) { fail('phpstan-baseline.neon is required'); }
$entries = baselineEntries($baseline);
$records = [];
/** @var array<string,list<array{start:int,end:int,new:string}>> $edits */
$edits = [];
$diagnosticCount = 0;
$parsedAllows = array_map('parseAllow', $options['allows']);
$siteKeys = array_map(static fn(array $allow): string => $allow['path'] . ':' . $allow['method'], $parsedAllows);
if (count($siteKeys) !== count(array_unique($siteKeys))) {
    fail('duplicate allowlist site');
}
foreach ($parsedAllows as $allow) {
    $candidateFile = $repo . '/' . $allow['path'];
    $resolvedFile = realpath($candidateFile);
    $insideRepo = $resolvedFile !== false && str_starts_with($resolvedFile, $repo . DIRECTORY_SEPARATOR);
    $file = $insideRepo ? $resolvedFile : $candidateFile;
    $fileSha = $insideRepo && !is_link($candidateFile) && is_file($file) ? hash_file('sha256', $file) : false;
    if ($fileSha === false) { $fileSha = ''; }
    $record = array_fill_keys(FIELDS, '');
    $record = array_merge($record, [
        'head' => $head, 'baseline_sha' => $baselineSha, 'path' => $allow['path'],
        'method' => $allow['method'], 'id' => 'return.type', 'count' => 0,
        'status' => 'rejected', 'file_sha' => $fileSha,
    ]);
    if ($head !== $options['head']) {
        $record['reject_reason'] = 'head_drift';
        $records[] = $record;
        continue;
    }
    if ($fileSha === '' || !hash_equals($allow['sha'], $fileSha)) {
        $record['reject_reason'] = 'file_hash_drift';
        $records[] = $record;
        continue;
    }
    $matches = array_values(array_filter($entries, static fn(array $entry): bool =>
        $entry['path'] === $allow['path'] && $entry['id'] === 'return.type'
        && $entry['method'] === $allow['method']));
    if (count($matches) !== 1) {
        $record['reject_reason'] = 'baseline_no_exact_return';
        $records[] = $record;
        continue;
    }
    $entry = $matches[0];
    $record['count'] = $entry['count'];
    $record['class'] = $entry['class'] ?? '';
    if ($entry['count'] !== 1) {
        $record['reject_reason'] = 'baseline_count_not_one';
        $records[] = $record;
        continue;
    }
    if ($entry['class'] === null || $entry['atom'] === null) {
        $record['reject_reason'] = 'baseline_no_exact_return';
        $records[] = $record;
        continue;
    }
    $source = file_get_contents($file);
    if ($source === false) {
        $record['reject_reason'] = 'file_unreadable';
        $records[] = $record;
        continue;
    }
    $inspection = inspectMethod($source, $allow['method'], $entry['class'], $entry['atom']);
    if (isset($inspection['reject'])) {
        $record['reject_reason'] = $inspection['reject'];
        $records[] = $record;
        continue;
    }
    $record = array_merge($record, $inspection);
    unset($record['start'], $record['end']);
    $record['status'] = $options['apply'] ? 'applied' : 'eligible';
    $record['reject_reason'] = '';
    $records[] = $record;
    $edits[$file][] = [
        'start' => $inspection['start'], 'end' => $inspection['end'], 'new' => $inspection['new'],
    ];
    $diagnosticCount += $entry['count'];
}
$eligible = array_sum(array_map(static fn(array $record): int => in_array($record['status'], ['eligible', 'applied'], true) ? 1 : 0, $records));
$rejected = count($records) - $eligible;
$meta = [
    'head' => $head, 'baseline_sha' => $baselineSha, 'mode' => $options['apply'] ? 'apply' : 'dry-run',
    'eligible_sites' => $eligible, 'diagnostics' => $diagnosticCount, 'rejected' => $rejected,
];
if ($options['apply']) {
    if ($rejected !== 0 || $eligible !== $options['sites'] || $diagnosticCount !== $options['diagnostics']) {
        foreach ($records as &$record) {
            if ($record['status'] === 'applied') { $record['status'] = 'eligible'; }
        }
        unset($record);
        emit($records, $meta, $options['format']);
        fail('apply preconditions failed; no files changed');
    }
    if (gitHead($repo) !== $options['head']) { fail('HEAD changed before write'); }
    $contents = [];
    foreach ($edits as $file => $fileEdits) {
        $expected = null;
        foreach ($parsedAllows as $allow) {
            if ($repo . '/' . $allow['path'] === $file) { $expected = $allow['sha']; break; }
        }
        $currentSha = hash_file('sha256', $file);
        if ($expected === null || $currentSha === false || !hash_equals($expected, $currentSha)) {
            fail('file changed before write: ' . substr($file, strlen($repo) + 1));
        }
        $content = file_get_contents($file);
        if ($content === false) { fail("cannot reread $file"); }
        usort($fileEdits, static fn(array $a, array $b): int => $b['start'] <=> $a['start']);
        foreach ($fileEdits as $edit) {
            $content = substr($content, 0, $edit['start']) . $edit['new'] . substr($content, $edit['end']);
        }
        $contents[$file] = $content;
    }
    atomicWrite($contents);
}
emit($records, $meta, $options['format']);
