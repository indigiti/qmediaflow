<?php
namespace MediaFlow;

/** CDN URL rewriting plus asynchronous S3-compatible derivative mirroring. */
final class Distribution {
    private Paths $paths;
    private ?S3_Store $store = null;
    private string $public_base = '';

    public function __construct( Paths $paths ) {
        $this->paths = $paths;
        if ( Runtime_Config::object_store_enabled() ) { $this->store = new S3_Store(); }
        $base = Runtime_Config::cdn_base_url();
        if ( '' === $base && $this->store && Runtime_Config::enabled( 'QMEDIAFLOW_OBJECT_PRIMARY', false ) ) {
            $base = $this->store->public_base_url();
        }
        $this->public_base = rtrim( $base, '/' );
    }

    public function hooks(): void {
        add_action( 'qmediaflow_distribution_sync', array( $this, 'process_queue' ), 10, 1 );
        if ( Runtime_Config::mirror_originals() ) { add_action( 'add_attachment', array( $this, 'queue_original' ), 50, 1 ); }
    }

    public function rewrites_urls(): bool { return '' !== $this->public_base; }

    public function public_url( string $origin_url ): string {
        if ( '' === $this->public_base ) { return $origin_url; }
        $needle = '/image-cache/';
        $pos = strpos( $origin_url, $needle );
        if ( false === $pos ) { return $origin_url; }
        $relative = ltrim( substr( $origin_url, $pos + strlen( $needle ) ), '/' );
        return $this->public_base . '/' . $relative;
    }

    public function rewrite_srcset( string $srcset ): string {
        if ( '' === $this->public_base ) { return $srcset; }
        $parts = preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            if ( ! preg_match( '/^(\S+)(\s+.+)?$/', trim( $part ), $m ) ) { continue; }
            $out[] = $this->public_url( $m[1] ) . ( $m[2] ?? '' );
        }
        return $out ? implode( ', ', $out ) : $srcset;
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
        if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( is_resource( $lock ) ) { @fclose( $lock ); }
            return $result;
        }

        $next_retry = 0;
        try {
            foreach ( $this->job_paths() as $job_path ) {
                if ( $result['processed'] >= max( 1, $limit ) ) { break; }
                $job = $this->read_job( $job_path );
                if ( ! $job ) { $this->delete_job( $job_path ); continue; }
                $retry_after = absint( $job['retry_after'] ?? 0 );
                if ( $retry_after > time() ) {
                    $next_retry = 0 === $next_retry ? $retry_after : min( $next_retry, $retry_after );
                    continue;
                }

                ++$result['processed'];
                $path = (string) ( $job['path'] ?? '' );
                $key = (string) ( $job['key'] ?? '' );
                $mime = (string) ( $job['mime'] ?? 'application/octet-stream' );
                if ( '' === $path || '' === $key || ! is_readable( $path ) ) {
                    $this->delete_job( $job_path );
                    ++$result['failed'];
                    continue;
                }

                $started = microtime( true );
                if ( $this->store->put( $key, $path, $mime ) ) {
                    $this->delete_job( $job_path );
                    ++$result['ready'];
                    Telemetry::timing( 'object_store_put_ms', ( microtime( true ) - $started ) * 1000 );
                    Telemetry::event( 'object_store_put_ready' );
                } else {
                    ++$result['failed'];
                    $attempts = absint( $job['attempts'] ?? 0 ) + 1;
                    $job['attempts'] = $attempts;
                    $job['retry_after'] = time() + min( 3600, 30 * ( 2 ** min( 6, $attempts ) ) );
                    $job['updated_at'] = time();
                    $next_retry = 0 === $next_retry ? $job['retry_after'] : min( $next_retry, $job['retry_after'] );
                    $this->write_job( $job_path, $job );
                    Telemetry::event( 'object_store_put_failed' );
                }
            }
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }

        if ( $this->has_ready_job() ) { $this->schedule( 2 ); }
        elseif ( $next_retry > 0 ) { $this->schedule( max( 2, $next_retry - time() ) ); }
        return $result;
    }

    public function health(): array {
        $configured_cdn = Runtime_Config::cdn_base_url();
        $store = $this->store ? $this->store->health() : array( 'configured' => false, 'ok' => true, 'message' => 'Object storage disabled.' );
        return array(
            'cdn_base_url'    => $configured_cdn,
            'cdn_enabled'     => '' !== $configured_cdn,
            'public_base_url' => $this->public_base,
            'object_store'    => $store,
        );
    }

    private function enqueue_job( array $job ): bool {
        $root = $this->queue_root();
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) { return false; }
        $identity = hash( 'sha256', (string) $job['key'] . '|' . (string) @filemtime( (string) $job['path'] ) . '|' . (string) @filesize( (string) $job['path'] ) );
        $path = $this->job_path( $identity );
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        if ( is_readable( $path ) ) { return false; }
        $job = array_merge( $job, array( 'identity' => $identity, 'attempts' => 0, 'queued_at' => time(), 'updated_at' => time(), 'retry_after' => 0 ) );
        $handle = @fopen( $path, 'x' );
        if ( ! is_resource( $handle ) ) { return false; }
        $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES );
        $ok = is_string( $json ) && false !== @fwrite( $handle, $json );
        @fclose( $handle );
        @chmod( $path, 0600 );
        if ( ! $ok ) { @unlink( $path ); return false; }
        $this->schedule( 1 );
        return true;
    }

    private function schedule( int $delay = 2 ): bool {
        $args = array( $this->paths->site_id() );
        $delay = max( 1, $delay );
        if ( 1 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'qmediaflow_distribution_sync', $args, 'qmediaflow', true );
            return true;
        }
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + $delay, 'qmediaflow_distribution_sync', $args, 'qmediaflow', true );
            return true;
        }
        if ( ! wp_next_scheduled( 'qmediaflow_distribution_sync', $args ) ) {
            return (bool) wp_schedule_single_event( time() + $delay, 'qmediaflow_distribution_sync', $args );
        }
        return true;
    }

    private function queue_root(): string { return $this->paths->private_dir() . '/distribution-queue'; }

    private function job_path( string $identity ): string {
        return $this->queue_root() . '/' . substr( $identity, 0, 2 ) . '/' . substr( $identity, 2, 2 ) . '/' . $identity . '.json';
    }

    /** @return \Generator<int,string> */
    private function job_paths(): \Generator {
        $root = $this->queue_root();
        foreach ( (array) @scandir( $root ) as $one ) {
            if ( ! preg_match( '/^[a-f0-9]{2}$/', (string) $one ) ) { continue; }
            $one_path = $root . '/' . $one;
            foreach ( (array) @scandir( $one_path ) as $two ) {
                if ( ! preg_match( '/^[a-f0-9]{2}$/', (string) $two ) ) { continue; }
                $two_path = $one_path . '/' . $two;
                foreach ( (array) @scandir( $two_path ) as $file ) {
                    if ( preg_match( '/^[a-f0-9]{64}\.json$/', (string) $file ) ) { yield $two_path . '/' . $file; }
                }
            }
        }
    }

    private function read_job( string $path ): ?array {
        if ( ! is_readable( $path ) ) { return null; }
        $raw = @file_get_contents( $path );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function write_job( string $path, array $job ): bool {
        $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { return false; }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        if ( false === @file_put_contents( $temp, $json ) ) { return false; }
        @chmod( $temp, 0600 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return false; }
        @chmod( $path, 0600 );
        return true;
    }

    private function delete_job( string $path ): void {
        @unlink( $path );
        $root = $this->queue_root();
        $two = dirname( $path );
        $one = dirname( $two );
        foreach ( array( $two, $one ) as $dir ) {
            if ( $dir === $root || ! str_starts_with( wp_normalize_path( $dir ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) { continue; }
            $items = @scandir( $dir );
            if ( is_array( $items ) && count( $items ) <= 2 ) { @rmdir( $dir ); }
        }
    }

    private function has_ready_job(): bool {
        $now = time();
        foreach ( $this->job_paths() as $path ) {
            $job = $this->read_job( $path );
            if ( $job && absint( $job['retry_after'] ?? 0 ) <= $now ) { return true; }
        }
        return false;
    }
}
