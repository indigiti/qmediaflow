<?php
namespace MediaFlow;

/**
 * Low-overhead filesystem telemetry.
 *
 * Static cache hits never execute PHP. Dynamic requests aggregate counters and
 * timings in memory and perform at most one best-effort filesystem merge at
 * shutdown. Delivery never waits for telemetry lock contention.
 */
final class Telemetry {
    private static ?self $instance = null;
    private static bool $shutdown_registered = false;

    private Paths $paths;
    private array $pending_counters = array();
    private array $pending_timings = array();
    private bool $dirty = false;

    public function __construct( Paths $paths ) {
        $this->paths = $paths;
    }

    public static function boot( Paths $paths ): self {
        if ( self::$instance && self::$instance->paths->private_dir() !== $paths->private_dir() ) {
            self::$instance->flush( false );
        }
        self::$instance = new self( $paths );
        if ( ! self::$shutdown_registered ) {
            register_shutdown_function( static function (): void {
                if ( self::$instance ) { self::$instance->flush( false ); }
            } );
            self::$shutdown_registered = true;
        }
        return self::$instance;
    }

    public static function instance(): ?self { return self::$instance; }

    public static function event( string $name, int $delta = 1 ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->buffer_counter( $name, $delta );
        }
    }

    public static function timing( string $name, float $milliseconds ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->buffer_timing( $name, $milliseconds );
        }
    }

    public static function bytes( string $name, int $bytes ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->buffer_counter( $name, max( 0, $bytes ) );
        }
    }

    /**
     * Flush buffered metrics. Blocking mode is reserved for explicit admin/CLI
     * reads; visitor/background shutdown uses non-blocking mode and drops no work
     * from the delivery path if another process owns the telemetry lock.
     */
    public function flush( bool $blocking = false ): bool {
        if ( ! $this->dirty ) { return true; }
        $dir = $this->paths->private_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        $lock = @fopen( $dir . '/telemetry.lock', 'c' );
        if ( ! is_resource( $lock ) ) { return false; }
        $mode = $blocking ? LOCK_EX : ( LOCK_EX | LOCK_NB );
        if ( ! @flock( $lock, $mode ) ) { @fclose( $lock ); return false; }

        try {
            $metrics = $this->read_json( $this->metrics_path() );
            $metrics = is_array( $metrics ) ? $metrics : $this->empty_metrics();
            foreach ( $this->pending_counters as $name => $delta ) {
                $metrics['counters'][ $name ] = (int) ( $metrics['counters'][ $name ] ?? 0 ) + (int) $delta;
            }
            foreach ( $this->pending_timings as $name => $pending ) {
                $base = is_array( $metrics['timings'][ $name ] ?? null )
                    ? $metrics['timings'][ $name ]
                    : array( 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'buckets' => array() );
                $base['count'] = (int) $base['count'] + (int) ( $pending['count'] ?? 0 );
                $base['total_ms'] = (float) $base['total_ms'] + (float) ( $pending['total_ms'] ?? 0 );
                $base['max_ms'] = max( (float) $base['max_ms'], (float) ( $pending['max_ms'] ?? 0 ) );
                foreach ( (array) ( $pending['buckets'] ?? array() ) as $bucket => $count ) {
                    $base['buckets'][ (string) $bucket ] = (int) ( $base['buckets'][ (string) $bucket ] ?? 0 ) + (int) $count;
                }
                $metrics['timings'][ $name ] = $base;
            }
            $metrics['updated_at'] = time();
            if ( ! $this->write_json( $this->metrics_path(), $metrics ) ) { return false; }
            $this->pending_counters = array();
            $this->pending_timings = array();
            $this->dirty = false;
            return true;
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    public function snapshot(): array {
        $this->flush( true );
        $metrics = $this->read_json( $this->metrics_path() );
        $gateway = $this->read_json( $this->gateway_metrics_path() );
        $metrics = is_array( $metrics ) ? $metrics : $this->empty_metrics();
        if ( is_array( $gateway ) ) { $metrics = $this->merge_metrics( $metrics, $gateway ); }
        $metrics['derived'] = array();
        foreach ( (array) ( $metrics['timings'] ?? array() ) as $name => $timing ) {
            if ( ! is_array( $timing ) ) { continue; }
            $count = max( 0, (int) ( $timing['count'] ?? 0 ) );
            $metrics['derived'][ $name ] = array(
                'count'  => $count,
                'avg_ms' => $count > 0 ? round( (float) ( $timing['total_ms'] ?? 0 ) / $count, 2 ) : 0.0,
                'max_ms' => round( (float) ( $timing['max_ms'] ?? 0 ), 2 ),
                'p50_ms' => $this->percentile_bucket( $timing, 0.50 ),
                'p95_ms' => $this->percentile_bucket( $timing, 0.95 ),
                'p99_ms' => $this->percentile_bucket( $timing, 0.99 ),
            );
        }
        return $metrics;
    }

    public function reset(): bool {
        $this->pending_counters = array();
        $this->pending_timings = array();
        $this->dirty = false;
        $dir = $this->paths->private_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        $lock = @fopen( $dir . '/telemetry.lock', 'c' );
        if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) {
            if ( is_resource( $lock ) ) { @fclose( $lock ); }
            return false;
        }
        try {
            $ok = $this->write_json( $this->metrics_path(), $this->empty_metrics() );
            if ( is_file( $this->gateway_metrics_path() ) ) { $ok = @unlink( $this->gateway_metrics_path() ) && $ok; }
            return $ok;
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    private function buffer_counter( string $name, int $delta ): void {
        $name = sanitize_key( $name );
        if ( '' === $name || 0 === $delta ) { return; }
        $this->pending_counters[ $name ] = (int) ( $this->pending_counters[ $name ] ?? 0 ) + $delta;
        $this->dirty = true;
    }

    private function buffer_timing( string $name, float $milliseconds ): void {
        $name = sanitize_key( $name );
        if ( '' === $name ) { return; }
        $milliseconds = max( 0.0, min( 600000.0, $milliseconds ) );
        $timing = is_array( $this->pending_timings[ $name ] ?? null )
            ? $this->pending_timings[ $name ]
            : array( 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0, 'buckets' => array() );
        ++$timing['count'];
        $timing['total_ms'] = (float) $timing['total_ms'] + $milliseconds;
        $timing['max_ms'] = max( (float) $timing['max_ms'], $milliseconds );
        $bucket = self::bucket_for( $milliseconds );
        $timing['buckets'][ $bucket ] = (int) ( $timing['buckets'][ $bucket ] ?? 0 ) + 1;
        $this->pending_timings[ $name ] = $timing;
        $this->dirty = true;
    }

    private function metrics_path(): string { return $this->paths->private_dir() . '/metrics.json'; }
    private function gateway_metrics_path(): string { return $this->paths->private_dir() . '/gateway-metrics.json'; }

    private function empty_metrics(): array {
        return array( 'version' => 1, 'counters' => array(), 'timings' => array(), 'updated_at' => 0 );
    }

    private function read_json( string $path ): ?array {
        if ( ! is_readable( $path ) ) { return null; }
        $json = @file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function write_json( string $path, array $data ): bool {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { return false; }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        if ( false === @file_put_contents( $temp, $json ) ) { return false; }
        @chmod( $temp, 0600 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return false; }
        @chmod( $path, 0600 );
        return true;
    }

    private function merge_metrics( array $left, array $right ): array {
        foreach ( (array) ( $right['counters'] ?? array() ) as $name => $value ) {
            $left['counters'][ $name ] = (int) ( $left['counters'][ $name ] ?? 0 ) + (int) $value;
        }
        foreach ( (array) ( $right['timings'] ?? array() ) as $name => $timing ) {
            if ( ! is_array( $timing ) ) { continue; }
            $base = is_array( $left['timings'][ $name ] ?? null ) ? $left['timings'][ $name ] : array( 'count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'buckets' => array() );
            $base['count'] = (int) $base['count'] + (int) ( $timing['count'] ?? 0 );
            $base['total_ms'] = (float) $base['total_ms'] + (float) ( $timing['total_ms'] ?? 0 );
            $base['max_ms'] = max( (float) $base['max_ms'], (float) ( $timing['max_ms'] ?? 0 ) );
            foreach ( (array) ( $timing['buckets'] ?? array() ) as $bucket => $count ) {
                $base['buckets'][ (string) $bucket ] = (int) ( $base['buckets'][ (string) $bucket ] ?? 0 ) + (int) $count;
            }
            $left['timings'][ $name ] = $base;
        }
        $left['updated_at'] = max( (int) ( $left['updated_at'] ?? 0 ), (int) ( $right['updated_at'] ?? 0 ) );
        return $left;
    }

    private static function bucket_for( float $milliseconds ): string {
        foreach ( array( 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000 ) as $limit ) {
            if ( $milliseconds <= $limit ) { return (string) $limit; }
        }
        return 'inf';
    }

    private function percentile_bucket( array $timing, float $percentile ) {
        $count = max( 0, (int) ( $timing['count'] ?? 0 ) );
        if ( 0 === $count ) { return 0; }
        $target = max( 1, (int) ceil( $count * $percentile ) );
        $seen = 0;
        foreach ( array( '10', '25', '50', '100', '250', '500', '1000', '2500', '5000', '10000', 'inf' ) as $bucket ) {
            $seen += (int) ( $timing['buckets'][ $bucket ] ?? 0 );
            if ( $seen >= $target ) { return 'inf' === $bucket ? '>10000' : (int) $bucket; }
        }
        return '>10000';
    }
}
