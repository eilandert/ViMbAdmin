<?php

require __DIR__ . '/../library/ViMbAdmin/Version.php';

$failures = 0;
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    $ok = $actual === $expected;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
        echo '         expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . "\n";
    }
};

echo "== ViMbAdmin version metadata ==\n";

$commit = '0123456789abcdef0123456789abcdef01234567';
$shortCommit = new ReflectionMethod(ViMbAdmin_Version::class, '_shortCommit');
$check('build commit is shortened to twelve characters', $shortCommit->invoke(null, $commit), '0123456789ab');
$check('short build commits are not padded', $shortCommit->invoke(null, 'abcdef0'), 'abcdef0');
$check('missing build commit remains null', $shortCommit->invoke(null, null), null);

$decode = new ReflectionMethod(ViMbAdmin_Version::class, '_decodeGithubResponse');
$objectPayload = ['tag_name' => 'v4.0.1', 'metadata' => ['channel' => 'stable']];
$check(
    'GitHub object payload shape is retained',
    $decode->invoke(null, json_encode($objectPayload), ['HTTP/1.1 200 OK']),
    $objectPayload
);
$listPayload = [['name' => 'v4.0.1'], ['name' => 'v4.0.0']];
$check(
    'GitHub list payload shape is retained',
    $decode->invoke(null, json_encode($listPayload), ['HTTP/2 200 OK']),
    $listPayload
);
$check(
    'GitHub non-success status remains a failure',
    $decode->invoke(null, json_encode($objectPayload), ['HTTP/1.1 403 Forbidden']),
    null
);
$check(
    'malformed GitHub JSON remains a failure',
    $decode->invoke(null, '{', ['HTTP/1.1 200 OK']),
    null
);

final class FailingHttpsStream
{
    public mixed $context;

    /** @var list<array{path: string, mode: string, options: int, openedPath: string|null}> */
    public static array $calls = [];

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$calls[] = [
            'path' => $path,
            'mode' => $mode,
            'options' => $options,
            'openedPath' => $openedPath,
        ];
        return false;
    }
}

$github = new ReflectionMethod(ViMbAdmin_Version::class, '_github');
if (!stream_wrapper_unregister('https') || !stream_wrapper_register('https', FailingHttpsStream::class)) {
    fwrite(STDERR, "FAIL: could not install isolated HTTPS test wrapper\n");
    exit(1);
}
try {
    $check('network failure remains fail-soft', $github->invoke(null, 'releases/latest'), null);
    $check('network failure receives exactly one retry', count(FailingHttpsStream::$calls), 2);
    $check(
        'GitHub request path remains unchanged',
        FailingHttpsStream::$calls[0]['path'] ?? null,
        'https://api.github.com/repos/' . ViMbAdmin_Version::GITHUB_REPO . '/releases/latest'
    );
} finally {
    stream_wrapper_restore('https');
}

echo $failures === 0
    ? "OK: all ViMbAdmin version assertions passed\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
