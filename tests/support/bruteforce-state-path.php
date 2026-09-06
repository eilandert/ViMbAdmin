<?php

/**
 * Shared derivation of a brute-force state file path for tests.
 *
 * ViMbAdmin_BruteForce keys state on the source *network prefix*, not the exact
 * address, so a test that wants to inspect or fault-inject one state file has
 * to apply the same masking. Several tests need this; keeping one copy means a
 * change to the keying breaks them all at once and visibly, instead of letting
 * stale copies rot into what looks like a controller bug.
 *
 * Mirrors ViMbAdmin_BruteForce::_file() / ::_key() for the shipped defaults.
 */

declare(strict_types=1);

if (!function_exists('bruteForceStateKey')) {
    /**
     * @param int $ipv4Prefix IPv4 counting width (default matches the shipped /24)
     * @param int $ipv6Prefix IPv6 counting width (default matches the shipped /64)
     */
    function bruteForceStateKey(string $ip, int $ipv4Prefix = 24, int $ipv6Prefix = 64): string
    {
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return 'raw:' . $ip;
        }

        // Unwrap IPv4-mapped addresses (::ffff:a.b.c.d) exactly as _key() does.
        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }

        $bits = min(strlen($packed) === 4 ? $ipv4Prefix : $ipv6Prefix, strlen($packed) * 8);
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        $masked = substr($packed, 0, $bytes);
        if ($remainder !== 0) {
            $masked .= chr(ord($packed[$bytes]) & (0xff << (8 - $remainder) & 0xff));
        }
        $masked = str_pad($masked, strlen($packed), "\0");

        $network = @inet_ntop($masked);

        return is_string($network) ? $network . '/' . $bits : 'raw:' . $ip;
    }

    /**
     * Path of the fixed lock shard serializing this source's record.
     *
     * Mirrors ViMbAdmin_BruteForce::_lockFile(): the first byte (two hex
     * characters) of the same sha256 the record filename uses, so the sidecar
     * alphabet is the fixed set .lock.00 .. .lock.ff.
     */
    function bruteForceLockShardPath(
        string $stateDirectory,
        string $ip,
        int $ipv4Prefix = 24,
        int $ipv6Prefix = 64,
    ): string {
        return $stateDirectory . '/.lock.'
            . substr(hash('sha256', bruteForceStateKey($ip, $ipv4Prefix, $ipv6Prefix)), 0, 2);
    }

    function bruteForceStatePath(
        string $stateDirectory,
        string $ip,
        int $ipv4Prefix = 24,
        int $ipv6Prefix = 64,
    ): string {
        return $stateDirectory . '/'
            . hash('sha256', bruteForceStateKey($ip, $ipv4Prefix, $ipv6Prefix)) . '.json';
    }
}
