<?php
/**
 * ViMbAdmin brute-force login protection.
 *
 * Tracks failed-login pressure per source *network prefix* and locks that
 * prefix out for a cooldown window once it crosses a threshold. State is kept
 * as one small JSON file per prefix under a state directory (no DB coupling,
 * survives across requests, trivially clearable). Keying on the prefix rather
 * than the exact address is what stops an attacker who holds a whole IPv6 /64
 * -- the normal residential and hosting allocation -- from multiplying the
 * attempt budget by 2^64. The directory is capped and evicted, so it cannot be
 * grown without bound either.
 *
 * Model: every login POST is counted as a pending attempt in _preLogin();
 * a successful auth (password + 2FA) clears the counter. While the counter
 * for a source is at/over the threshold within the window, logins from that
 * source are refused with HTTP 429 -- unless the source IP is allowlisted.
 *
 * Configuration (application.ini, [bruteforce] -> $opts):
 *   bruteforce.enabled      = 1
 *   bruteforce.max_attempts = 5         ; failures before lock
 *   bruteforce.window       = 900       ; seconds the counter accumulates over
 *   bruteforce.lockout      = 900       ; seconds a source stays locked
 *   bruteforce.ipv4_prefix  = 24        ; counting granularity, 8..32
 *   bruteforce.ipv6_prefix  = 64        ; counting granularity, 16..128
 *   bruteforce.max_entries  = 4096      ; state files kept before eviction
 *   bruteforce.statedir     = "/opt/vimbadmin/var/bruteforce"
 *   bruteforce.whitelist[]  = "127.0.0.1"
 *   bruteforce.whitelist[]  = "10.0.0.0/8"
 *
 * @package ViMbAdmin
 */
class ViMbAdmin_BruteForce
{
    private const LOCK_TIMEOUT_NANOSECONDS = 1_000_000_000;
    private const LOCK_RETRY_MICROSECONDS = 1000;
    private const REAP_SCAN_LIMIT = 128;
    private const DEFAULT_IPV4_PREFIX = 24;
    private const DEFAULT_IPV6_PREFIX = 64;
    private const DEFAULT_MAX_ENTRIES = 4096;
    private const EVICT_SAMPLE_LIMIT = 512;
    private const REAP_INTERVAL_SECONDS = 60;

    /** @return array<string,mixed> */
    private static function stringMap( mixed $value, string $name ): array
    {
        if( !is_array( $value ) )
            throw new LogicException( $name . ' must be an array' );
        foreach( $value as $key => $_item )
            if( !is_string( $key ) )
                throw new LogicException( $name . ' must use string keys' );
        return $value;
    }

    private static function boolValue( mixed $value, string $name ): bool
    {
        if( $value === true || $value === 1 || $value === '1' ) return true;
        if( $value === false || $value === 0 || $value === '' || $value === '0' ) return false;
        throw new LogicException( $name . ' must be boolean' );
    }

    private static function stringValue( mixed $value, string $name ): string
    {
        if( !is_string( $value ) )
            throw new LogicException( $name . ' must be a string' );
        return $value;
    }

    private static function intValue( mixed $value, string $name, int $minimum = 0 ): int
    {
        if( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) {
            $normalized = ltrim( $value, '0' );
            $value = filter_var( $normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT );
        }
        if( !is_int( $value ) || $value < $minimum )
            throw new LogicException( $name . ' must be a non-negative integer' );
        return $value;
    }

    /** @return list<string> */
    private static function stringList( mixed $value, string $name ): array
    {
        $values = is_array( $value ) ? $value : [ $value ];
        $result = [];
        foreach( $values as $item ) {
            if( !is_string( $item ) )
                throw new LogicException( $name . ' must contain strings' );
            $result[] = $item;
        }
        return $result;
    }
    /** @var bool */
    private $_enabled   = true;
    /** @var int */
    private $_max       = 5;
    /** @var int */
    private $_window    = 900;
    /** @var int */
    private $_lockout   = 900;
    /** @var string */
    private $_statedir  = null;
    /** @var list<string> */
    private $_whitelist = [];
    /** @var string */
    private $_proxyMode = 'auto';
    /** @var list<string> */
    private $_proxies   = [];
    /** @var int */
    private $_v4prefix  = self::DEFAULT_IPV4_PREFIX;
    /** @var int */
    private $_v6prefix  = self::DEFAULT_IPV6_PREFIX;
    /** @var int */
    private $_maxEntries = self::DEFAULT_MAX_ENTRIES;
    /**
     * Entries observed by the last sweep in this process.
     *
     * @var int
     */
    private $_liveCount = 0;
    /**
     * State writes made since that sweep.
     *
     * @var int
     */
    private $_writesSinceSweep = 0;

    /**
     * @param mixed $em   Unused (kept for call-site compatibility).
     * @param array<string, mixed> $opts [bruteforce] options from application.ini.
     */
    public function __construct( $em = null, array $opts = [] )
    {
        // $em is accepted only for call-site compatibility with the historic
        // (EntityManager-backed) signature; this file-state implementation does
        // not use it. Explicitly discard so it isn't flagged as dead.
        unset( $em );

        $opts = self::stringMap( $opts, 'bruteforce options' );
        if( isset( $opts['enabled'] ) )      $this->_enabled = self::boolValue( $opts['enabled'], 'bruteforce.enabled' );
        if( isset( $opts['max_attempts'] ) ) $this->_max     = self::intValue( $opts['max_attempts'], 'bruteforce.max_attempts', 1 );
        if( isset( $opts['window'] ) )       $this->_window  = self::intValue( $opts['window'], 'bruteforce.window' );
        if( isset( $opts['lockout'] ) )      $this->_lockout = self::intValue( $opts['lockout'], 'bruteforce.lockout' );

        $statedir = isset( $opts['statedir'] ) ? $opts['statedir'] : null;
        if( $statedir !== null && !is_string( $statedir ) )
            throw new LogicException( 'bruteforce.statedir must be a string' );
        $this->_statedir = $statedir !== null && $statedir !== ''
            ? rtrim( $statedir, '/' )
            : sys_get_temp_dir() . '/vimbadmin-bruteforce';

        if( isset( $opts['ipv4_prefix'] ) ) {
            $this->_v4prefix = self::intValue( $opts['ipv4_prefix'], 'bruteforce.ipv4_prefix' );
            if( $this->_v4prefix < 8 || $this->_v4prefix > 32 )
                throw new LogicException( 'bruteforce.ipv4_prefix must be between 8 and 32' );
        }
        if( isset( $opts['ipv6_prefix'] ) ) {
            $this->_v6prefix = self::intValue( $opts['ipv6_prefix'], 'bruteforce.ipv6_prefix' );
            if( $this->_v6prefix < 16 || $this->_v6prefix > 128 )
                throw new LogicException( 'bruteforce.ipv6_prefix must be between 16 and 128' );
        }
        if( isset( $opts['max_entries'] ) )
            $this->_maxEntries = self::intValue( $opts['max_entries'], 'bruteforce.max_entries', 64 );

        if( isset( $opts['whitelist'] ) )
            $this->_whitelist = self::stringList( $opts['whitelist'], 'bruteforce.whitelist' );

        // Trusted-proxy policy for resolving the real client IP (see [trustedproxy]).
        if( isset( $opts['trustedproxy'] ) ) {
            $proxy = self::stringMap( $opts['trustedproxy'], 'bruteforce.trustedproxy' );
            if( isset( $proxy['mode'] ) )
                $this->_proxyMode = self::stringValue( $proxy['mode'], 'bruteforce.trustedproxy.mode' );
            if( isset( $proxy['proxies'] ) )
                $this->_proxies = self::stringList( $proxy['proxies'], 'bruteforce.trustedproxy.proxies' );
        }
    }

    // ---- public API ----------------------------------------------------

    /**
     * Abort the request with 429 if the request's source IP is currently
     * locked out. Call early in the login flow (_preLogin).
     *
     * @throws never returns when locked (sends 429 + exits)
     * @throws RuntimeException when state cannot be read or locked
     * @param mixed $request
     * @return void
     */
    public function assertNotLocked( $request )
    {
        if( !$this->_enabled )
            return;

        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return;

        $rec = $this->_withLock(
            function() use ( $ip ): array { return $this->_load( $ip ); },
            false
        );
        if( $rec['locked_until'] > time() )
        {
            header( 'HTTP/1.1 429 Too Many Requests' );
            header( 'Retry-After: ' . max( 1, $rec['locked_until'] - time() ) );
            echo 'Too many failed login attempts. Try again later.';
            exit;
        }
    }

    /**
     * Record one failed attempt for the request's source IP. Locks the source
     * when it crosses the threshold inside the window. Counting is per source
     * network prefix only; $username is accepted for call-site
     * symmetry/logging but not keyed on, so neither username rotation nor
     * address rotation inside one allocation escapes the same lock.
     *
     * @param mixed $username
     * @param mixed $request
     * @throws RuntimeException when state cannot be persisted or locked
     * @return void
     */
    public function record( $username, $request )
    {
        if( !$this->_enabled )
            return;

        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return;

        $this->_maybeReapStale();
        $this->_withLock( function() use ( $ip ): void {
            $rec = $this->_load( $ip );

            // reset the counter if the window has elapsed since the first hit
            if( $rec['first'] === 0 || ( time() - $rec['first'] ) > $this->_window )
            {
                $rec['first']    = time();
                $rec['attempts'] = 0;
            }

            $rec['attempts']++;
            $rec['last'] = time();

            if( $rec['attempts'] >= $this->_max )
                $rec['locked_until'] = time() + $this->_lockout;

            $this->_save( $ip, $rec );
        } );
    }

    /**
     * Clear the counter for a source after a fully successful login.
     *
     * @param mixed $username
     * @param mixed $request
     * @throws RuntimeException when state cannot be removed or locked
     * @return void
     */
    public function clear( $username, $request )
    {
        if( !$this->_enabled )
            return;
        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return;
        $this->_withLock( function() use ( $ip ): void {
            $this->_delete( $ip );
        } );
    }

    /**
     * Is this source currently locked? (does not change attempt state)
     *
     * @param mixed $request
     * @throws RuntimeException when state cannot be read or locked
     * @return bool
     */
    public function isLocked( $request )
    {
        if( !$this->_enabled )
            return false;
        $ip = $this->_ip( $request );
        if( $this->_isWhitelisted( $ip ) )
            return false;
        $rec = $this->_withLock(
            function() use ( $ip ): array { return $this->_load( $ip ); },
            false
        );
        return $rec['locked_until'] > time();
    }

    // ---- storage (one JSON file per IP under the state dir) ------------

    /**
     * @param string $ip
     * @return string
     */
    private function _file( $ip )
    {
        // Hash the network key so the filename is filesystem-safe and doesn't
        // leak the raw address in a directory listing.
        return $this->_statedir . '/' . hash( 'sha256', $this->_key( $ip ) ) . '.json';
    }

    /**
     * Collapse a source address onto its network prefix so that rotating
     * addresses inside one allocation cannot buy extra attempts. IPv6 hosts
     * routinely hold a whole /64 (and larger), so per-address counting made the
     * throttle a no-op there; IPv4 defaults to /24. Both widths are
     * configurable, and an unparsable address is keyed verbatim (fail closed
     * onto its own bucket rather than sharing one).
     *
     * @param string $ip
     * @return string
     */
    private function _key( $ip )
    {
        $packed = @inet_pton( $ip );
        if( !is_string( $packed ) )
            return 'raw:' . $ip;

        // An IPv4-mapped address (::ffff:a.b.c.d) is an IPv4 client wearing a
        // 16-byte representation. Masked at the IPv6 width it collapses to
        // ::/64, which would put every such client in one shared bucket and let
        // any one of them lock out all the others. Unwrap to the 4-byte form so
        // it is keyed as the IPv4 address it actually is.
        if( strlen( $packed ) === 16 && strncmp( $packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12 ) === 0 )
            $packed = substr( $packed, 12 );

        $bits = strlen( $packed ) === 4 ? $this->_v4prefix : $this->_v6prefix;
        $full = strlen( $packed ) * 8;
        if( $bits > $full )
            $bits = $full;

        $bytes = intdiv( $bits, 8 );
        $rem   = $bits % 8;
        $masked = substr( $packed, 0, $bytes );
        if( $rem !== 0 )
            $masked .= chr( ord( $packed[$bytes] ) & ( 0xff << ( 8 - $rem ) & 0xff ) );
        $masked = str_pad( $masked, strlen( $packed ), "\0" );

        $network = @inet_ntop( $masked );
        if( !is_string( $network ) )
            return 'raw:' . $ip;
        return $network . '/' . $bits;
    }

    /** @return void */
    private function _ensureDir()
    {
        if( !is_string( $this->_statedir ) )
            throw new LogicException( 'bruteforce state directory is not configured' );
        if( !is_dir( $this->_statedir )
            && ( !@mkdir( $this->_statedir, 0750, true ) && !is_dir( $this->_statedir ) ) )
            throw new RuntimeException( 'bruteforce state persistence unavailable' );
    }

    /**
     * Serialize state mutations on one stable inode. The JSON files are
     * atomically replaced, so locking them directly would leave a waiter on a
     * stale pre-rename inode. One directory lock also keeps attacker-selected
     * source addresses from creating an unbounded set of lock sidecars.
     *
     * @template T
     * @param callable():T $operation
     * @param bool $exclusive
     * @return T
     */
    private function _withLock( callable $operation, $exclusive = true )
    {
        $this->_ensureDir();
        $lockFile = $this->_statedir . '/.lock';
        $handle = @fopen( $lockFile, 'c' );
        if( $handle === false )
            throw new RuntimeException( 'bruteforce state persistence unavailable' );

        try
        {
            $mode = ( $exclusive ? LOCK_EX : LOCK_SH ) | LOCK_NB;
            $deadline = hrtime( true ) + self::LOCK_TIMEOUT_NANOSECONDS;
            while( !@flock( $handle, $mode ) )
            {
                if( hrtime( true ) >= $deadline )
                    throw new RuntimeException( 'bruteforce state persistence unavailable' );
                usleep( self::LOCK_RETRY_MICROSECONDS );
            }
            return $operation();
        }
        finally
        {
            @flock( $handle, LOCK_UN );
            fclose( $handle );
        }
    }

    /**
     * @param string $ip
     * @return array{attempts:int, first:int, last:int, locked_until:int}
     */
    private function _load( $ip )
    {
        $default = [ 'attempts' => 0, 'first' => 0, 'last' => 0, 'locked_until' => 0 ];

        $f = $this->_file( $ip );
        if( file_exists( $f ) )
        {
            $json = @file_get_contents( $f );
            if( !is_string( $json ) )
                throw new RuntimeException( 'bruteforce state persistence unavailable' );
            $d = json_decode( $json, true );
            if( is_array( $d ) ) {
                foreach( $default as $key => $_zero ) {
                    if( !isset( $d[$key] ) || !is_int( $d[$key] ) || $d[$key] < 0 ) {
                        throw new LogicException( 'bruteforce state is corrupt' );
                    }
                }
                $attempts = $d['attempts'];
                $first = $d['first'];
                $last = $d['last'];
                $lockedUntil = $d['locked_until'];
                return [ 'attempts' => $attempts, 'first' => $first, 'last' => $last, 'locked_until' => $lockedUntil ];
            }
            throw new LogicException( 'bruteforce state is corrupt' );
        }
        return $default;
    }

    /**
     * @param string $ip
     * @param array{attempts:int, first:int, last:int, locked_until:int} $rec
     * @return void
     */
    private function _save( $ip, array $rec )
    {
        $this->_ensureDir();
        $f   = $this->_file( $ip );
        $tmp = $f . '.' . getmypid() . '.tmp';
        $encoded = json_encode( $rec );
        if( !is_string( $encoded ) )
            throw new RuntimeException( 'bruteforce state persistence unavailable' );

        try
        {
            if( @file_put_contents( $tmp, $encoded, LOCK_EX ) !== strlen( $encoded ) )
                throw new RuntimeException( 'bruteforce state persistence unavailable' );
            if( !@rename( $tmp, $f ) )
                throw new RuntimeException( 'bruteforce state persistence unavailable' );
            $this->_writesSinceSweep++;
        }
        finally
        {
            if( file_exists( $tmp ) )
                @unlink( $tmp );
        }
    }

    /**
     * @param string $ip
     * @return void
     */
    private function _delete( $ip )
    {
        $file = $this->_file( $ip );
        if( file_exists( $file ) && !@unlink( $file ) )
        {
            error_log( 'ViMbAdmin_BruteForce: could not clear state file ' . $file );
            throw new RuntimeException( 'bruteforce state persistence unavailable' );
        }
    }

    /**
     * Opportunistic maintenance. It deliberately does NOT take the state lock
     * that record()/clear() serialize on: the reaper touches only records that
     * are older than max(window, lockout), it re-stats every file to confirm
     * the inode did not change underneath it, and a losing race merely leaves a
     * stale file for the next request. Holding the exclusive lock across a
     * directory scan is what let a grown state directory turn concurrent failed
     * logins into HTTP 500s.
     *
     * The maintenance lock is non-blocking: one worker sweeps, everybody else
     * moves straight on to the state write.
     *
     * @return void
     */
    private function _maybeReapStale()
    {
        try
        {
            $this->_ensureDir();
        }
        catch( RuntimeException )
        {
            // Fail closed happens in the caller's own state write, which takes
            // the lock and re-runs _ensureDir().
            return;
        }

        $handle = @fopen( $this->_statedir . '/.reap-lock', 'c' );
        if( $handle === false )
            return;
        try
        {
            if( !@flock( $handle, LOCK_EX | LOCK_NB ) )
                return;
            try
            {
                // One readdir pass is O(N) even with the name cursor, so it
                // is amortised over time rather than paid on every failed
                // login: .reap-stamp's mtime records the last sweep.
                $now = time();
                $stamp = $this->_statedir . '/.reap-stamp';
                clearstatcache( true, $stamp );
                $last = @filemtime( $stamp );
                // A missing stamp means "never swept" and is always due; a
                // stamp in the future (clock step) is likewise treated as due.
                //
                // The interval only throttles the *routine* sweep. record()
                // creates a state file per new prefix with no brake of its own,
                // so a flood can outrun a once-a-minute drain and grow the
                // directory without bound. .reap-overflow records that the last
                // sweep finished still above the cap; while it is set, every
                // failed login sweeps again until the directory is back under
                // max_entries.
                $overCap = ( $this->_liveCount + $this->_writesSinceSweep ) > $this->_maxEntries;
                if( !$overCap && is_int( $last ) && $last <= $now
                    && ( $now - $last ) < self::REAP_INTERVAL_SECONDS )
                    return;
                if( @touch( $stamp, $now ) )
                    @chmod( $stamp, 0640 );
                $this->_writesSinceSweep = 0;
                if( $this->_reapStale( $now ) )
                    // Still above the cap: one sweep evicts at most
                    // EVICT_SAMPLE_LIMIT entries, so force the next failed login
                    // to sweep again rather than wait out the interval.
                    $this->_writesSinceSweep = $this->_maxEntries + 1;
            }
            catch( RuntimeException )
            {
                // Cleanup is opportunistic; the following state write
                // independently acquires the state lock and still fails closed.
            }
            finally
            {
                @flock( $handle, LOCK_UN );
            }
        }
        finally
        {
            fclose( $handle );
        }
    }

    /**
     * Sweep a fixed-size window of the state directory, resuming from an opaque
     * *name* cursor rather than an ordinal one. `DirectoryIterator::seek($n)` is
     * O(n) readdir steps, so a full pass over N entries cost O(N^2 / 128) — the
     * behaviour that made a grown directory quadratic. Filenames are hex
     * digests, so "the entries whose name sorts after the last one I saw" is a
     * stable, O(N) resumption key: each pass reads the directory once, keeps the
     * REAP_SCAN_LIMIT smallest names above the cursor, and stores the last of
     * them. Every entry is still visited, including active and malformed ones,
     * so no fixed prefix can starve later stale state.
     *
     * @return bool true when the directory is STILL over the entry cap after
     *              this sweep, so the caller can re-arm without waiting out the
     *              reap interval.
     */
    private function _reapStale( int $now ): bool
    {
        $directoryStat = @lstat( $this->_statedir );
        if( !is_array( $directoryStat ) || !isset( $directoryStat['mode'] )
            || !is_int( $directoryStat['mode'] ) || ( $directoryStat['mode'] & 0170000 ) !== 0040000 )
            return false;

        $cursorPath = $this->_statedir . '/.reap-cursor';
        $cursor = '';
        $rawCursor = @file_get_contents( $cursorPath );
        if( is_string( $rawCursor ) && preg_match( '/^[a-f0-9]{64}\n$/D', $rawCursor ) === 1 )
            $cursor = trim( $rawCursor );

        $handle = @opendir( $this->_statedir );
        if( $handle === false )
            return false;

        // One readdir pass; keep only the smallest REAP_SCAN_LIMIT names that
        // sort strictly after the cursor, plus a bounded oldest-first sample for
        // the entry cap. Memory stays O(REAP_SCAN_LIMIT + EVICT_SAMPLE_LIMIT).
        $window = [];
        $windowSorted = false;
        $total = 0;
        $sample = [];
        try
        {
            while( ( $entry = readdir( $handle ) ) !== false )
            {
                if( preg_match( '/^([a-f0-9]{64})\.json$/D', $entry, $matches ) !== 1 )
                    continue;
                $total++;
                $name = $matches[1];
                if( count( $sample ) < self::EVICT_SAMPLE_LIMIT )
                    $sample[] = $entry;
                if( $cursor !== '' && strcmp( $name, $cursor ) <= 0 )
                    continue;
                // Keep the REAP_SCAN_LIMIT smallest names above the cursor.
                // Once the window is full, most entries cannot make the cut, so
                // compare against the current maximum before paying for a sort
                // -- this runs once per directory entry on the failed-login path.
                if( count( $window ) < self::REAP_SCAN_LIMIT ) {
                    $window[] = $name;
                    continue;
                }
                if( !$windowSorted ) {
                    sort( $window, SORT_STRING );
                    $windowSorted = true;
                }
                if( strcmp( $name, (string) end( $window ) ) >= 0 )
                    continue;
                array_pop( $window );
                $window[] = $name;
                sort( $window, SORT_STRING );
            }
        }
        finally
        {
            closedir( $handle );
        }
        sort( $window, SORT_STRING );
        $window = array_slice( $window, 0, self::REAP_SCAN_LIMIT );

        $cutoff = $now - max( $this->_window, $this->_lockout );
        $removed = 0;
        foreach( $window as $name )
            if( $this->_reapFile( $this->_statedir . '/' . $name . '.json', $cutoff ) )
                $removed++;

        // A pass that reached the end of the key space starts over next time.
        $next = count( $window ) < self::REAP_SCAN_LIMIT ? '' : (string) end( $window );
        $this->_saveReapCursor( $cursorPath, $next );



        // Hard cap: stale-only reaping cannot bound a directory an attacker
        // refreshes faster than max(window, lockout). Once the cap is exceeded,
        // evict the least-recently-touched sampled entries as well, so the
        // directory (and therefore every scan over it) stays bounded.
        $remaining = $total - $removed;
        $stillOverCap = $this->_evictOverflow( $remaining, $sample );

        // Remember how full the directory is AFTER eviction, not before:
        // record() creates one file per new prefix with no brake, so the
        // throttle uses this count plus the writes since to decide whether the
        // next login must sweep again. Counting the evicted files would leave
        // it reading over-cap for one more round and buy a needless O(N) sweep.
        // _evictOverflow() stops as soon as it is back at the cap, so that is
        // the floor once it has run.
        $this->_liveCount = $stillOverCap ? $remaining : min( $remaining, $this->_maxEntries );

        return $stillOverCap;
    }

    /**
     * @param list<string> $sample bounded sample of state file basenames
     * @return bool true when entries remain above the cap after this pass
     */
    private function _evictOverflow( int $remaining, array $sample ): bool
    {
        $overflow = $remaining - $this->_maxEntries;
        if( $overflow <= 0 )
            return false;
        if( $sample === [] )
            return true;

        $aged = [];
        foreach( $sample as $entry ) {
            $path = $this->_statedir . '/' . $entry;
            $stat = @lstat( $path );
            if( !is_array( $stat ) || !isset( $stat['mtime'] ) || !is_int( $stat['mtime'] )
                || !isset( $stat['mode'] ) || !is_int( $stat['mode'] )
                || ( $stat['mode'] & 0170000 ) !== 0100000 )
                continue;
            $aged[$path] = $stat['mtime'];
        }
        asort( $aged, SORT_NUMERIC );

        foreach( array_keys( $aged ) as $path ) {
            if( $overflow <= 0 )
                return false;
            $before = @lstat( $path );
            clearstatcache( true, $path );
            $after = @lstat( $path );
            if( $this->_isStableRegularFile( $before, $after ) && @unlink( $path ) )
                $overflow--;
        }

        return $overflow > 0;
    }

    private function _saveReapCursor( string $path, string $cursor ): void
    {
        $tmp = $path . '.' . getmypid() . '.tmp';
        $value = $cursor . "\n";
        if( @file_put_contents( $tmp, $value, LOCK_EX ) !== strlen( $value ) || !@rename( $tmp, $path ) )
        {
            @unlink( $tmp );
            throw new RuntimeException( 'bruteforce state persistence unavailable' );
        }
    }

    private function _reapFile( string $path, int $cutoff ): bool
    {
        $before = @lstat( $path );
        if( !$this->_isStableRegularFile( $before, $before ) )
            return false;
        $json = @file_get_contents( $path );
        $record = is_string( $json ) ? json_decode( $json, true ) : null;
        if( !is_array( $record ) || !$this->_isStaleRecord( $record, $cutoff ) )
            return false;
        clearstatcache( true, $path );
        $after = @lstat( $path );
        return $this->_isStableRegularFile( $before, $after ) && @unlink( $path );
    }

    private function _isStableRegularFile( mixed $before, mixed $after ): bool
    {
        if( !is_array( $before ) || !is_array( $after ) )
            return false;
        foreach( [ 'mode', 'dev', 'ino' ] as $key )
            if( !isset( $before[$key], $after[$key] ) || !is_int( $before[$key] )
                || !is_int( $after[$key] ) || $before[$key] !== $after[$key] )
                return false;
        return ( $before['mode'] & 0170000 ) === 0100000;
    }

    /** @param array<mixed> $record */
    private function _isStaleRecord( array $record, int $cutoff ): bool
    {
        foreach( [ 'attempts', 'first', 'last', 'locked_until' ] as $key )
            if( !isset( $record[$key] ) || !is_int( $record[$key] ) || $record[$key] < 0 )
                return false;
        return max( $record['first'], $record['last'], $record['locked_until'] ) < $cutoff;
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param mixed $request
     * @return string
     */
    private function _ip( $request )
    {
        // Resolve the real client IP per the trusted-proxy policy (default
        // 'auto': peel X-Forwarded-For only when the direct peer is a private
        // proxy). See ViMbAdmin_Net::clientIp.
        return ViMbAdmin_Net::clientIp( self::stringMap( $_SERVER, 'server parameters' ), $this->_proxyMode, $this->_proxies );
    }

    /**
     * @param string $ip
     * @return bool
     */
    private function _isWhitelisted( $ip )
    {
        // Shared IP/CIDR matching (see ViMbAdmin_Net) so the brute-force
        // whitelist, the MCP allowlist and the queue trigger all agree.
        return ViMbAdmin_Net::ipInList( $ip, implode( ' ', $this->_whitelist ) );
    }
}
