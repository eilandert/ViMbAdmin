<?php

require __DIR__ . '/../library/ViMbAdmin/Net.php';

final class NetworkAssertions
{
    public static int $failures = 0;
}

function networkCheck(string $label, bool $ok): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        NetworkAssertions::$failures++;
    }
}

echo "== trusted proxy and CIDR boundaries ==\n";

$forwarded = [
    'REMOTE_ADDR' => '10.0.0.2',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.5, 10.0.0.3',
];
networkCheck('auto mode selects the right-most untrusted valid hop', ViMbAdmin_Net::clientIp($forwarded) === '203.0.113.5');
networkCheck('off mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, 'off') === '10.0.0.2');
networkCheck('false mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, 'false') === '10.0.0.2');
networkCheck('zero mode ignores X-Forwarded-For', ViMbAdmin_Net::clientIp($forwarded, '0') === '10.0.0.2');
networkCheck(
    'auto mode ignores X-Forwarded-For from a public direct peer',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']) === '8.8.8.8'
);
networkCheck(
    'explicit mode trusts a configured proxy CIDR',
    ViMbAdmin_Net::clientIp($forwarded, 'on', ['10.0.0.0/8']) === '203.0.113.5'
);
networkCheck(
    'explicit mode ignores an unconfigured direct peer',
    ViMbAdmin_Net::clientIp($forwarded, 'on', ['192.168.0.0/16']) === '10.0.0.2'
);
networkCheck(
    'invalid forwarded hops are skipped',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8, not-an-ip']) === '203.0.113.8'
);
networkCheck(
    'an entirely trusted chain falls back to the direct peer',
    ViMbAdmin_Net::clientIp(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.3'], 'on', ['10.0.0.0/8']) === '10.0.0.2'
);

networkCheck('loopback is private', ViMbAdmin_Net::isPrivate('127.0.0.1'));
networkCheck('public IPv4 is not private', !ViMbAdmin_Net::isPrivate('8.8.8.8'));
networkCheck('IPv6 unique-local is private', ViMbAdmin_Net::isPrivate('fd00::1'));
networkCheck('malformed address is not private', !ViMbAdmin_Net::isPrivate('not-an-ip'));

networkCheck('IPv4 exact address matches', ViMbAdmin_Net::ipInCidr('192.0.2.1', '192.0.2.1'));
networkCheck('invalid exact values never match themselves', !ViMbAdmin_Net::ipInCidr('bad', 'bad'));
networkCheck('IPv4 CIDR contains an in-range address', ViMbAdmin_Net::ipInCidr('192.0.2.42', '192.0.2.0/24'));
networkCheck('IPv4 CIDR excludes an out-of-range address', !ViMbAdmin_Net::ipInCidr('192.0.3.1', '192.0.2.0/24'));
networkCheck('IPv4 zero prefix accepts any IPv4 address', ViMbAdmin_Net::ipInCidr('203.0.113.5', '0.0.0.0/0'));
networkCheck('IPv6 exact address matches', ViMbAdmin_Net::ipInCidr('2001:db8::1', '2001:db8::1'));
networkCheck('IPv6 CIDR contains an in-range address', ViMbAdmin_Net::ipInCidr('2001:db8::42', '2001:db8::/64'));
networkCheck('mixed IP families do not match', !ViMbAdmin_Net::ipInCidr('192.0.2.1', '2001:db8::/64'));

foreach (['192.0.2.0/-1', '192.0.2.0/33', '2001:db8::/129', '192.0.2.0/', '192.0.2.0/nope', '192.0.2.0/24/1'] as $invalidCidr) {
    networkCheck("invalid CIDR prefix {$invalidCidr} is rejected", !ViMbAdmin_Net::ipInCidr('192.0.2.1', $invalidCidr));
}
networkCheck('empty list does not match', !ViMbAdmin_Net::ipInList('192.0.2.1', ''));
networkCheck('malformed list entries do not match', !ViMbAdmin_Net::ipInList('192.0.2.1', 'bad, 2001:db8::/64'));
networkCheck('whitespace/comma list matches a later CIDR', ViMbAdmin_Net::ipInList('192.0.2.1', "bad,\n192.0.2.0/24"));

$failureCount = NetworkAssertions::$failures;
echo $failureCount === 0 ? "\nALL PASSED\n" : "\n{$failureCount} FAILED\n";
exit(min(1, $failureCount));
