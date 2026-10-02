<?php
namespace MediaFlow;

/**
 * Low-overhead filesystem telemetry. Static cache hits never execute PHP and
 * therefore never touch this class; only dynamic/cold/control-plane work records.
 */
final class Telemetry {
    private static ?self $instance = null;
    private Paths $paths;

    public function __construct( Paths $paths ) {
        $this->paths = $paths;
    }

    public static function boot( Paths $paths ): self {
        return self::$instance = new self( $paths );
    }

    public static function instance(): ?self {
        return self::$instance;
    }

    public static function event( string $name, int $delta = 1 ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->increment( $name, $delta );
        }
    }

    public static function timing( string $name, float $milliseconds ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->record_timing( $name, $milliseconds );
        }
    }

    public static function bytes( string $name, int $bytes ): void {
        if ( self::$instance && Runtime_Config::telemetry_enabled() ) {
            self::$instance->increment( $name, max( 0, $bytes ) );
        }
    }

    public function snapshot(): array {
        $metrics = $this->read_json( $this->metrics_path() );
        $gateway = $this->read_json( $this->gateway_metrics_path() );
        $metrics = is_array( $metrics ) ? $metrics : $this->empty_metrics();
        if ( is_array( $gateway ) ) {
            $metrics = $this->merge_metrics( $metrics, $gateway );
        }
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
        $ok = $this->write_json( $this->metrics_path(), $this->empty_metrics() );
        if ( is_file( $this->gateway_metrics_path() ) ) {
            $ok = @unlink( $this->gateway_metrics_path() ) && $ok;
        }
        return $ok;
    }

    private function increment( string $name, int $delta ): void {
        $name = sanitize_key( $name );
        if ( '' === $name || 0 === $delta ) { return; }
        $this->mutate( static function ( array &$metrics ) use ( $name, $delta ): void {
            $metrics['counters'][ $name ] = (int) ( $metrics['counters'][ $name ] ?? 0 ) + $delta;
        } );
    }

    private function record_timing( string $name, float $milliseconds ): void {
        $name = sanitize_key( $name );
        if ( '' === $name ) { return; }
        $milliseconds = max( 0.0, min( 600000.0, $milliseconds ) );
        $this->mutate( static function ( array &$metrics ) use ( $name, $milliseconds ): void {
            $timing = is_array( $metrics['timings'][ $name ] ?? null ) ? $metrics['timings'][ $name ] : array(
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'buckets' => array(),
            );
            ++$timing['count'];
            $timing['total_ms'] = (float) $timing['total_ms'] + $milliseconds;
            $timing['max_ms']   = max( (float) $timing['max_ms'], $milliseconds );
            $bucket = self::bucket_for( $milliseconds );
            $timing['buckets'][ $bucket ] = (int) ( $timing['buckets'][ $bucket ] ?? 0 ) + 1;
            $metrics['timings'][ $name ] = $timing;
        } );
    }

    private function mutate( callable $callback ): void {
        $dir = $this->paths->private_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return; }
        $lock = @fopen( $dir . '/telemetry.lock', 'c' );
        if ( ! is_resource( $lock ) ) { return; }
        if ( ! @flock( $lock, LOCK_EX ) ) { @fclose( $lock ); return; }
        try {
            $metrics = $this->read_json( $this->metrics_path() );
            $metrics = is_array( $metrics ) ? $metrics : $this->empty_metrics();
            $callback( $metrics );
            $metrics['updated_at'] = time();
            $this->write_json( $this->metrics_path(), $metrics );
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    private function metrics_path(): string {
        return $this->paths->private_dir() . '/metrics.json';
    }

    private function gateway_metrics_path(): string {
        return $this->paths->private_dir() . '/gateway-metrics.json';
    }

    private function empty_metrics(): array {
        return array(
            'version'    => 1,
            'counters'   => array(),
            'timings'    => array(),
            'updated_at' => 0,
        );
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
        if ( false === @file_put_contents( $temp, $json, LOCK_EX ) ) { return false; }
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
            $base['count']    = (int) $base['count'] + (int) ( $timing['count'] ?? 0 );
            $base['total_ms'] = (float) $base['total_ms'] + (float) ( $timing['total_ms'] ?? 0 );
            $base['max_ms']   = max( (float) $base['max_ms'], (float) ( $timing['max_ms'] ?? 0 ) );
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
            if ( $seen >= $target ) {
                return 'inf' === $bucket ? '>10000' : (int) $bucket;
            }
        }
        return '>10000';
    }
}
