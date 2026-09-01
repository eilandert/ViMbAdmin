<?php
/**
 * Per-token sliding-window rate limiter for the MCP adapter.
 *
 * Used to cap destructive operations (archive / restore / delete) so a
 * compromised or buggy client can't mass-destroy mailboxes. File-based state
 * (one small JSON file per token+bucket) under var/ -- no extra service.
 */
class ViMbAdmin_Mcp_RateLimit
{
    /** @var string */
    private $_dir;
    /** @var int */
    private $_max;
    /** @var int */
    private $_window;

    /**
     * @param array{
     *     statedir?: string|null,
     *     max?: int|numeric-string|null,
     *     window?: int|numeric-string|null
     * } $opts
     */
    public function __construct( array $opts = [] )
    {
        $this->_dir    = isset( $opts['statedir'] ) && $opts['statedir']
                       ? rtrim( $opts['statedir'], '/' )
                       : sys_get_temp_dir() . '/vimbadmin-mcp-ratelimit';
        $this->_max    = isset( $opts['max'] )    ? (int) $opts['max']    : 10;
        $this->_window = isset( $opts['window'] ) ? (int) $opts['window'] : 3600;
        if( $this->_max > 0 && $this->_window < 1 )
            throw new ViMbAdmin_Mcp_Exception( 'destructive rate-limit window must be at least one second', 503 );
    }

    /**
     * Record one destructive hit for $tokenId and throw if the limit is now
     * exceeded inside the window. Call this BEFORE doing the destructive work.
     *
     * @throws ViMbAdmin_Mcp_Exception (429) when over the limit
     */
    public function hit( int|string|null $tokenId, string $bucket = 'destructive' ): void
    {
        if( $this->_max <= 0 )           // 0/neg disables the limiter
            return;

        $now  = time();
        $file = $this->_file( $tokenId, $bucket );

        // The whole read-modify-write must be atomic, otherwise two concurrent
        // destructive calls can each read a sub-limit count and both proceed,
        // letting a compromised client slip past the cap. Hold an exclusive
        // flock on the state file for the entire check+record.
        // Lock a stable sidecar inode. The state file itself is atomically
        // replaced, so locking it would let waiters retain and later overwrite
        // a stale pre-rename inode.
        $lockFile = $file . '.lock';
        $fh = @fopen( $lockFile, 'c+' );
        if( $fh === false )
        {
            error_log( "ViMbAdmin_Mcp_RateLimit: cannot open lock file {$lockFile} — destructive operation denied for token {$tokenId}" );
            throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit unavailable', 503 );
        }

        $temporary = null;
        try
        {
            if( !flock( $fh, LOCK_EX ) )
            {
                error_log( "ViMbAdmin_Mcp_RateLimit: cannot lock {$lockFile} — destructive operation denied for token {$tokenId}" );
                throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit unavailable', 503 );
            }

            $exists = is_file( $file );
            $raw = $exists ? file_get_contents( $file ) : '';
            if( !is_string( $raw ) )
                throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit unavailable', 503 );
            if( !$exists )
                $hits = [];
            else
            {
                $hits = json_decode( $raw, true );
                if( !is_array( $hits ) || !array_is_list( $hits ) )
                    throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit state is invalid', 503 );
            }

            // drop entries outside the window
            $activeHits = [];
            foreach( $hits as $t )
            {
                if( is_int( $t ) )
                    $timestamp = $t;
                elseif( is_string( $t ) && preg_match( '/^[0-9]+$/D', $t ) === 1 )
                {
                    $parsed = filter_var( $t, FILTER_VALIDATE_INT );
                    if( !is_int( $parsed ) )
                        throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit state is invalid', 503 );
                    $timestamp = $parsed;
                }
                else
                    throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit state is invalid', 503 );

                if( ( $now - $timestamp ) < $this->_window )
                    $activeHits[] = $timestamp;
            }
            $hits = $activeHits;

            if( count( $hits ) >= $this->_max )
                throw new ViMbAdmin_Mcp_Exception(
                    "rate limit: max {$this->_max} destructive operations per {$this->_window}s", 429 );

            $hits[] = $now;

            $encoded = json_encode( $hits );
            if( $encoded === false )
            {
                error_log( "ViMbAdmin_Mcp_RateLimit: cannot encode state for {$file} — destructive operation denied for token {$tokenId}" );
                throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit unavailable', 503 );
            }

            $temporary = tempnam( dirname( $file ), basename( $file ) . '.tmp-' );
            if( $temporary === false
                || file_put_contents( $temporary, $encoded, LOCK_EX ) !== strlen( $encoded )
                || !rename( $temporary, $file ) )
                throw new ViMbAdmin_Mcp_Exception( 'destructive rate limit unavailable', 503 );
            $temporary = null;
        }
        finally
        {
            if( is_string( $temporary ) && is_file( $temporary ) )
                @unlink( $temporary );
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    // ---- internals -----------------------------------------------------

    private function _file( int|string|null $tokenId, string $bucket ): string
    {
        if( !is_dir( $this->_dir ) )
            @mkdir( $this->_dir, 0750, true );
        return $this->_dir . '/' . (int) $tokenId . '-' . preg_replace( '/[^a-z0-9]/', '', $bucket ) . '.json';
    }
}
