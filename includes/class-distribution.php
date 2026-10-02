<?php
namespace MediaFlow;

/** CDN URL rewriting plus asynchronous S3-compatible derivative mirroring. */
final class Distribution {
    private Paths $paths;
    private ?S3_Store $store = null;

    public function __construct( Paths $paths ) {
        $this->paths = $paths;
        if ( Runtime_Config::object_store_enabled() ) { $this->store = new S3_Store(); }
    }

    public function hooks(): void {
        add_action( 'qmediaflow_distribution_sync', array( $this, 'process_queue' ), 10, 1 );
        if ( Runtime_Config::mirror_originals() ) {
            add_action( 'add_attachment', array( $this, 'queue_original' ), 50, 1 );
        }
    }

    public function public_url( string $origin_url ): string {
        $base = Runtime_Config::cdn_base_url();
        if ( '' === $base && $this->store && Runtime_Config::enabled( 'QMEDIAFLOW_OBJECT_PRIMARY', false ) ) {
            $base = $this->store->public_base_url();
        }
        if ( '' === $base ) { return $origin_url; }
        $path = (string) wp_parse_url( $origin_url, PHP_URL_PATH );
        $needle = '/image-cache/';
        $pos = strpos( $path, $needle );
        if ( false === $pos ) { return $origin_url; }
        $relative = ltrim( substr( $path, $pos + strlen( $needle ) ), '/' );
        return $base . '/' . $relative;
    }

    public function rewrite_srcset( string $srcset ): string {
        $parts = preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            if ( ! preg_match( '/^(\S+)(\s+.+)?$/', trim( $part ), $m ) ) { continue; }
            $out[] = $this->public_url( $m[1] ) . ( $m[2] ?? '' );
        }
        return implode( ', ', $out );
    }

    public function enqueue_file( string $path, string $mime ): bool {
        if ( ! $this->store || ! is_readable( $path ) || ! $this->paths->is_inside_any_cache( $path ) ) { return false; }
        $cache_dir = rtrim( wp_normalize_path( $this->paths->cache_dir() ), '/' );
        $normalized = wp_normalize_path( $path );
        if ( ! str_starts_with( $normalized, $cache_dir . '/' ) ) { return false; }
        $relative = ltrim( substr( $normalized, strlen( $cache_dir ) ), '/' );
        $key = $this->paths->site_id() ? 'sites/' . $this->paths->site_id() . '/' . $relative : $relative;
        return $this->enqueue_job( array( 'path' => $normalized, 'key' => $key, 'mime' => $mime, 'kind' => 'derivative' ) );
    }

    public function queue_original( int $attachment_id ): void {
        if ( ! $this->store ) { return; }
        $path = get_attached_file( $attachment_id, true );
        if ( ! is_string( $path ) || ! is_readable( $path ) ) { return; }
        $mime = (string) get_post_mime_type( $attachment_id );
        $key = 'originals/' . ( $this->paths->site_id() ? $this->paths->site_id() . '/' : '' ) . $attachment_id . '/' . basename( $path );
        $this->enqueue_job( array( 'path' => wp_normalize_path( $path ), 'key' => $key, 'mime' => $mime ?: 'application/octet-stream', 'kind' => 'original' ) );
    }

    public function process_queue( int $site_id = 0, int $limit = 2 ): array {
        $result = array( 'processed' => 0, 'ready' => 0, 'failed' => 0 );
        if ( ! $this->store ) { return $result; }
        $root = $this->queue_root();
        if ( ! is_dir( $root ) ) { return $result; }
        $lock = @fopen( $root . '/worker.lock', 'c' );
        if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) { if ( is_resource( $lock ) ) @fclose( $lock ); return $result; }
        try {
            foreach ( (array) @scandir( $root ) as $file ) {
                if ( $result['processed'] >= max( 1, $limit ) ) { break; }
                if ( ! preg_match( '/^[a-f0-9]{64}\.json$/', (string) $file ) ) { continue; }
                $job_path = $root . '/' . $file;
                $job = $this->read_job( $job_path );
                if ( ! $job || absint( $job['retry_after'] ?? 0 ) > time() ) { continue; }
                ++$result['processed'];
                $path = (string) ( $job['path'] ?? '' );
                $key = (string) ( $job['key'] ?? '' );
                $mime = (string) ( $job['mime'] ?? 'application/octet-stream' );
                if ( '' === $path || '' === $key || ! is_readable( $path ) ) { @unlink( $job_path ); ++$result['failed']; continue; }
                $started = microtime( true );
                if ( $this->store->put( $key, $path, $mime ) ) {
                    @unlink( $job_path ); ++$result['ready'];
                    Telemetry::timing( 'object_store_put_ms', ( microtime( true ) - $started ) * 1000 );
                    Telemetry::event( 'object_store_put_ready' );
                } else {
                    ++$result['failed'];
                    $attempts = absint( $job['attempts'] ?? 0 ) + 1;
                    $job['attempts'] = $attempts;
                    $job['retry_after'] = time() + min( 3600, 30 * ( 2 ** min( 6, $attempts ) ) );
                    $job['updated_at'] = time();
                    $this->write_job( $job_path, $job );
                    Telemetry::event( 'object_store_put_failed' );
                }
            }
        } finally { @flock( $lock, LOCK_UN ); @fclose( $lock ); }
        if ( $this->has_ready_job() ) { $this->schedule(); }
        return $result;
    }

    public function health(): array {
        $cdn = Runtime_Config::cdn_base_url();
        $store = $this->store ? $this->store->health() : array( 'configured' => false, 'ok' => true, 'message' => 'Object storage disabled.' );
        return array(
            'cdn_base_url' => $cdn,
            'cdn_enabled'  => '' !== $cdn,
            'object_store' => $store,
        );
    }

    private function enqueue_job( array $job ): bool {
        $root = $this->queue_root();
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) { return false; }
        $identity = hash( 'sha256', (string) $job['key'] . '|' . (string) @filemtime( (string) $job['path'] ) . '|' . (string) @filesize( (string) $job['path'] ) );
        $path = $root . '/' . $identity . '.json';
        if ( is_readable( $path ) ) { return false; }
        $job = array_merge( $job, array( 'identity' => $identity, 'attempts' => 0, 'queued_at' => time(), 'updated_at' => time(), 'retry_after' => 0 ) );
        $handle = @fopen( $path, 'x' );
        if ( ! is_resource( $handle ) ) { return false; }
        $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES );
        $ok = is_string( $json ) && false !== @fwrite( $handle, $json );
        @fclose( $handle ); @chmod( $path, 0600 );
        if ( ! $ok ) { @unlink( $path ); return false; }
        $this->schedule();
        return true;
    }

    private function schedule(): bool {
        $args = array( $this->paths->site_id() );
        if ( function_exists( 'as_enqueue_async_action' ) ) { as_enqueue_async_action( 'qmediaflow_distribution_sync', $args, 'qmediaflow', true ); return true; }
        if ( ! wp_next_scheduled( 'qmediaflow_distribution_sync', $args ) ) { return (bool) wp_schedule_single_event( time() + 2, 'qmediaflow_distribution_sync', $args ); }
        return true;
    }

    private function queue_root(): string { return $this->paths->private_dir() . '/distribution-queue'; }
    private function read_job( string $path ): ?array { if ( ! is_readable( $path ) ) return null; $raw = @file_get_contents( $path ); $data = is_string( $raw ) ? json_decode( $raw, true ) : null; return is_array( $data ) ? $data : null; }
    private function write_job( string $path, array $job ): bool { $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES ); return is_string( $json ) && false !== @file_put_contents( $path, $json, LOCK_EX ); }
    private function has_ready_job(): bool { foreach ( (array) @scandir( $this->queue_root() ) as $file ) { if ( preg_match( '/^[a-f0-9]{64}\.json$/', (string) $file ) ) { $job = $this->read_job( $this->queue_root() . '/' . $file ); if ( $job && absint( $job['retry_after'] ?? 0 ) <= time() ) return true; } } return false; }
}
