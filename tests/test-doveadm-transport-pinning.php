<?php

declare(strict_types=1);

/**
 * ViMbAdmin_Doveadm::_post() pins the reused cURL easy handle to http/https
 * and refuses redirects. The handle is shared across calls and curl_reset()
 * drops every option, so the pinning has to be re-applied per request — the
 * second call through the same instance is the case that matters.
 *
 * The observable is real libcurl behaviour, not source text: a file:// URL is
 * refused with "Protocol \"file\" disabled" only when the protocol allowlist
 * is actually in force on that transfer.
 */

require_once __DIR__ . '/../library/ViMbAdmin/Doveadm.php';

$failures = 0;
$assertions = 0;
$check = static function (string $label, bool $ok) use (&$failures, &$assertions): void {
    $assertions++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

if (!function_exists('curl_init')) {
    echo "SKIPPED: cURL extension unavailable\n";
    exit(0);
}

/** Exposes the protected transport for direct exercise. */
final class TransportProbeDoveadm extends ViMbAdmin_Doveadm
{
    /** @return array{0:int,1:string} */
    public function post(string $payload): array
    {
        return $this->_post($payload);
    }
}

echo "== doveadm transport protocol pinning ==\n";

$fixture = tempnam(sys_get_temp_dir(), 'vimb-doveadm-pin-');
if (!is_string($fixture)) {
    throw new RuntimeException('Cannot create the file:// fixture.');
}
file_put_contents($fixture, '[["doveadmResponse",[],"tag"]]');

/**
 * Drive one _post() through a file:// URL and return the response body.
 *
 * With the protocol allowlist in force libcurl refuses the transfer and the
 * body comes back empty; without it libcurl reads the fixture and the body
 * carries its contents. (_post() does not drain the curl_multi info queue, so
 * the refusal surfaces as an empty body rather than an exception — the
 * discriminator is the body, and the harness control below proves it.)
 */
$attempt = static function (TransportProbeDoveadm $client): string {
    try {
        [, $body] = $client->post('[["ping",{},"tag"]]');
        return $body;
    } catch (ViMbAdmin_Exception $e) {
        return 'EXCEPTION: ' . $e->getMessage();
    }
};

$client = new TransportProbeDoveadm('file://' . $fixture, 'test-key', 5);

// First request through a freshly created handle.
$check(
    'first request refuses a non-http(s) scheme',
    $attempt($client) === ''
);

// Second request through the SAME reused handle, after curl_reset() has
// wiped the previous request's options. This is the regression that matters:
// options applied once at handle creation would be gone by now.
$check(
    'second request through the reused handle still refuses a non-http(s) scheme',
    $attempt($client) === ''
);

// Third request, to prove the pinning is not merely surviving one reset.
$check(
    'third request through the reused handle still refuses a non-http(s) scheme',
    $attempt($client) === ''
);

// Negative control on the harness itself: an unpinned handle DOES read the
// fixture, so the assertions above discriminate rather than passing because
// file:// is unusable in this environment.
$bare = curl_init();
curl_setopt($bare, CURLOPT_URL, 'file://' . $fixture);
curl_setopt($bare, CURLOPT_RETURNTRANSFER, true);
$bareBody = curl_exec($bare);
$bareErrno = curl_errno($bare);
// No curl_close(): deprecated in PHP 8.5 and a no-op since 8.0. Unsetting the
// last reference is what frees the handle.
unset($bare);
$check(
    'an unpinned handle can read the file:// fixture (assertions are discriminating)',
    $bareErrno === 0 && is_string($bareBody) && str_contains($bareBody, 'doveadmResponse')
);

// Redirect following is off, and the redirect protocol allowlist is pinned
// alongside it, so a compromised endpoint cannot bounce the Authorization
// header carrying the API key to another host.
$source = file_get_contents(__DIR__ . '/../library/ViMbAdmin/Doveadm.php');
if (!is_string($source)) {
    throw new RuntimeException('Cannot read Doveadm.php');
}
$check(
    'redirects are disabled on the request handle',
    str_contains($source, 'CURLOPT_FOLLOWLOCATION, false')
);
$check(
    'the redirect protocol allowlist is pinned alongside the request allowlist',
    str_contains($source, 'CURLOPT_REDIR_PROTOCOLS_STR')
    && str_contains($source, 'CURLOPT_REDIR_PROTOCOLS,')
);
$check(
    'protocol pinning is applied after curl_reset(), not at handle creation',
    (bool) preg_match(
        '/curl_reset\(\s*\$ch\s*\);.*CURLOPT_PROTOCOLS/s',
        $source
    )
);
$check(
    'the cleartext-api-key exposure of the default http:// endpoint is documented',
    str_contains($source, 'SECURITY NOTE:') && str_contains($source, 'cleartext')
);

$ini = file_get_contents(__DIR__ . '/../application/configs/application.ini.dist');
$check(
    'application.ini.dist warns about the cleartext api_key on the default endpoint',
    is_string($ini)
    && (bool) preg_match('/SECURITY:.*cleartext.*doveadm\.http\.url/s', $ini)
);

// Setting the options must not emit a deprecation notice on the PHP versions
// this repo targets (8.4 floor, 8.5 production).
$deprecations = [];
set_error_handler(static function (int $no, string $msg) use (&$deprecations): bool {
    if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
        $deprecations[] = $msg;
    }
    return false;
});
$attempt($client);
restore_error_handler();
$check(
    'configuring the handle emits no deprecation notice on this PHP version',
    $deprecations === []
);

// $fixture is this test's own tempnam() path, never user input.
@unlink($fixture); // nosemgrep: php.lang.security.unlink-use.unlink-use

// Fixed assertion count: guards against a silently skipped block.
$expectedAssertions = 10;
$actualAssertions = $assertions;
echo ($actualAssertions === $expectedAssertions ? '  ok   ' : '  FAIL ')
    . "fixed assertion count ({$actualAssertions} of {$expectedAssertions})\n";
if ($actualAssertions !== $expectedAssertions) {
    $failures++;
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
