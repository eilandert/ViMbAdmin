<?php
/**
 * ViMbAdmin brute-force login protection.
 *
 * Tracks failed-login pressure per source IP and locks a source out for a
 * cooldown window once it crosses a threshold. State is kept as one small
 * JSON file per IP under a state directory (no DB coupling, survives across
 * requests, trivially clearable).
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
     * when it crosses the threshold inside the window. Counting is per-IP only;
     * $username is accepted for call-site symmetry/logging but not keyed on
     * (an attacker rotating usernames from one IP still trips the same lock).
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
        // Hash the IP so the filename is filesystem-safe and doesn't leak the
        // raw address in a directory listing.
        return $this->_statedir . '/' . hash( 'sha256', $ip ) . '.json';
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
