<?php
namespace MediaFlow;

/**
 * Bounded, filesystem-backed derivative pre-generation queue.
 * Jobs are de-duplicated by immutable variant identity and processed away from
 * visitor requests. Action Scheduler is used when present; WP-Cron is the zero-
 * dependency fallback and WP-CLI can drain the same queue explicitly.
 */
final class Derivative_Queue {
    private Paths $paths;
    private Settings $settings;
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Processor $processor;

    public function __construct( Paths $paths, Settings $settings, Manifest_Store $manifests, Resolver $resolver ) {
        $this->paths     = $paths;
        $this->settings  = $settings;
        $this->manifests = $manifests;
        $this->resolver  = $resolver;
        $this->processor = new Processor( $paths, $manifests, $resolver );
    }

    public function root(): string {
        return $this->paths->private_dir() . '/derivative-queue';
    }

    public function enqueue( int $attachment_id, array $spec, string $priority = 'normal' ): bool {
        if ( $attachment_id < 1 ) { return false; }
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) { return false; }
        $variant = $this->resolver->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $variant ) { return false; }
        $namespace = $this->settings->cache_namespace();
        $target = $this->resolver->path( $attachment_id, $manifest, $variant, $namespace );
        if ( is_readable( $target ) ) { return false; }

        $priority = $this->normalize_priority( $priority );
        $identity = hash( 'sha256', implode( '|', array( $attachment_id, (string) $manifest['revision'], $namespace, $variant->token(), $variant->format ) ) );
        $path = $this->job_path( $priority, $identity );
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        if ( is_readable( $path ) ) { return false; }

        $job = array(
            'version'       => 1,
            'identity'      => $identity,
            'site_id'       => $this->paths->site_id(),
            'attachment_id' => $attachment_id,
            'revision'      => (string) $manifest['revision'],
            'namespace'     => $namespace,
            'priority'      => $priority,
            'spec'          => array(
                'width'   => $variant->width,
                'height'  => $variant->height,
                'crop'    => $variant->crop,
                'quality' => $variant->quality,
                'format'  => $variant->format,
            ),
            'attempts'      => 0,
            'state'         => 'queued',
            'queued_at'     => time(),
            'updated_at'    => time(),
            'retry_after'   => 0,
        );

        $handle = @fopen( $path, 'x' );
        if ( ! is_resource( $handle ) ) { return false; }
        $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES );
        $ok = is_string( $json ) && false !== @fwrite( $handle, $json );
        @fclose( $handle );
        @chmod( $path, 0600 );
        if ( ! $ok ) { @unlink( $path ); return false; }

        Telemetry::event( 'queue_enqueued' );
        $this->schedule_worker( 1 );
        return true;
    }

    /** @return array{processed:int,ready:int,failed:int,busy:int,scheduled:bool} */
    public function process( ?int $max_jobs = null, ?float $time_budget = null ): array {
        $max_jobs = max( 1, $max_jobs ?? Runtime_Config::queue_batch() );
        $time_budget = max( 1.0, $time_budget ?? Runtime_Config::queue_time_budget() );
        $result = array( 'processed' => 0, 'ready' => 0, 'failed' => 0, 'busy' => 0, 'scheduled' => false );

        $root = $this->root();
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) { return $result; }
        $worker = @fopen( $root . '/worker.lock', 'c' );
        if ( ! is_resource( $worker ) ) { return $result; }
        if ( ! @flock( $worker, LOCK_EX | LOCK_NB ) ) { @fclose( $worker ); return $result; }

        $started = microtime( true );
        try {
            foreach ( $this->next_jobs( max( $max_jobs * 4, 20 ) ) as $path ) {
                if ( $result['processed'] >= $max_jobs || microtime( true ) - $started >= $time_budget ) { break; }
                ++$result['processed'];
                $status = $this->process_job( $path );
                if ( 'ready' === $status ) { ++$result['ready']; }
                elseif ( 'busy' === $status ) { ++$result['busy']; }
                else { ++$result['failed']; }
            }

            if ( $this->has_ready_job() ) {
                $result['scheduled'] = $this->schedule_worker( 2 );
            }
        } finally {
            @flock( $worker, LOCK_UN );
            @fclose( $worker );
        }
        Telemetry::event( 'queue_processed', $result['processed'] );
        Telemetry::event( 'queue_ready', $result['ready'] );
        Telemetry::event( 'queue_failed', $result['failed'] );
        Telemetry::event( 'queue_busy', $result['busy'] );
        return $result;
    }

    public function status( int $scan_limit = 5000 ): array {
        $scan_limit = max( 1, min( 50000, $scan_limit ) );
        $count = 0;
        $failed = 0;
        $oldest = 0;
        foreach ( $this->all_job_paths( $scan_limit ) as $path ) {
            ++$count;
            $job = $this->read_job( $path );
            if ( $job ) {
                if ( 'failed' === (string) ( $job['state'] ?? '' ) ) { ++$failed; }
                $queued = absint( $job['queued_at'] ?? 0 );
                if ( $queued > 0 && ( 0 === $oldest || $queued < $oldest ) ) { $oldest = $queued; }
            }
        }
        return array(
            'depth'          => $count,
            'failed'         => $failed,
            'oldest_seconds' => $oldest ? max( 0, time() - $oldest ) : 0,
            'scan_limit'     => $scan_limit,
            'truncated'      => $count >= $scan_limit,
        );
    }

    private function process_job( string $path ): string {
        $job = $this->read_job( $path );
        if ( ! $job ) { @unlink( $path ); return 'failed'; }
        $now = time();
        if ( absint( $job['retry_after'] ?? 0 ) > $now ) { return 'busy'; }
        if ( (string) ( $job['namespace'] ?? '' ) !== $this->settings->cache_namespace() ) {
            @unlink( $path );
            return 'failed';
        }

        $attachment_id = absint( $job['attachment_id'] ?? 0 );
        $manifest = $this->manifests->get( $attachment_id );
        if ( ! $manifest || ! hash_equals( (string) ( $job['revision'] ?? '' ), (string) ( $manifest['revision'] ?? '' ) ) ) {
            @unlink( $path );
            return 'failed';
        }

        $spec = is_array( $job['spec'] ?? null ) ? $job['spec'] : array();
        $variant = $this->resolver->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $variant ) { @unlink( $path ); return 'failed'; }

        $job['state'] = 'processing';
        $job['updated_at'] = $now;
        $this->write_job( $path, $job );
        $started = microtime( true );
        $generated = $this->processor->generate( $attachment_id, $manifest, $variant, (string) $job['namespace'] );
        Telemetry::timing( 'queue_generation_ms', ( microtime( true ) - $started ) * 1000 );

        if ( is_string( $generated ) && is_readable( $generated ) ) {
            Telemetry::bytes( 'generated_bytes', (int) @filesize( $generated ) );
            @unlink( $path );
            return 'ready';
        }

        if ( is_wp_error( $generated ) && in_array( $generated->get_error_code(), array( 'mediaflow_busy', 'mediaflow_capacity' ), true ) ) {
            $job['state'] = 'queued';
            $job['updated_at'] = time();
            $job['retry_after'] = time() + 2;
            $this->write_job( $path, $job );
            return 'busy';
        }

        $attempts = absint( $job['attempts'] ?? 0 ) + 1;
        if ( $attempts >= 5 ) {
            $job['state'] = 'failed';
            $job['attempts'] = $attempts;
            $job['updated_at'] = time();
            $job['retry_after'] = time() + 3600;
            $this->write_job( $path, $job );
            return 'failed';
        }
        $job['state'] = 'queued';
        $job['attempts'] = $attempts;
        $job['updated_at'] = time();
        $job['retry_after'] = time() + min( 300, 5 * ( 2 ** $attempts ) );
        $this->write_job( $path, $job );
        return 'failed';
    }

    /** @return \Generator<int,string> */
    private function next_jobs( int $limit ): \Generator {
        $now = time();
        $seen = 0;
        foreach ( $this->all_job_paths( max( 100, $limit * 10 ) ) as $path ) {
            $job = $this->read_job( $path );
            if ( ! $job ) { @unlink( $path ); continue; }
            if ( absint( $job['retry_after'] ?? 0 ) > $now ) { continue; }
            yield $path;
            if ( ++$seen >= $limit ) { return; }
        }
    }

    private function has_ready_job(): bool {
        foreach ( $this->next_jobs( 1 ) as $path ) { return true; }
        return false;
    }

    /** @return \Generator<int,string> */
    private function all_job_paths( int $limit ): \Generator {
        $seen = 0;
        foreach ( array( '0-critical', '1-high', '2-normal', '3-maintenance' ) as $priority ) {
            $base = $this->root() . '/' . $priority;
            if ( ! is_dir( $base ) ) { continue; }
            foreach ( (array) @scandir( $base ) as $one ) {
                if ( ! preg_match( '/^[a-f0-9]{2}$/', (string) $one ) ) { continue; }
                $one_path = $base . '/' . $one;
                foreach ( (array) @scandir( $one_path ) as $two ) {
                    if ( ! preg_match( '/^[a-f0-9]{2}$/', (string) $two ) ) { continue; }
                    $two_path = $one_path . '/' . $two;
                    foreach ( (array) @scandir( $two_path ) as $file ) {
                        if ( ! preg_match( '/^[a-f0-9]{64}\.json$/', (string) $file ) ) { continue; }
                        yield $two_path . '/' . $file;
                        if ( ++$seen >= $limit ) { return; }
                    }
                }
            }
        }
    }

    private function job_path( string $priority, string $identity ): string {
        $prefix = substr( $identity, 0, 2 ) . '/' . substr( $identity, 2, 2 );
        return $this->root() . '/' . $this->priority_directory( $priority ) . '/' . $prefix . '/' . $identity . '.json';
    }

    private function priority_directory( string $priority ): string {
        return match ( $priority ) {
            'critical'    => '0-critical',
            'high'        => '1-high',
            'maintenance' => '3-maintenance',
            default       => '2-normal',
        };
    }

    private function normalize_priority( string $priority ): string {
        $priority = sanitize_key( $priority );
        return in_array( $priority, array( 'critical', 'high', 'normal', 'maintenance' ), true ) ? $priority : 'normal';
    }

    private function read_job( string $path ): ?array {
        if ( ! is_readable( $path ) ) { return null; }
        $json = @file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function write_job( string $path, array $job ): bool {
        $json = wp_json_encode( $job, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { return false; }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        if ( false === @file_put_contents( $temp, $json, LOCK_EX ) ) { return false; }
        @chmod( $temp, 0600 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return false; }
        @chmod( $path, 0600 );
        return true;
    }

    private function schedule_worker( int $delay ): bool {
        $site_id = $this->paths->site_id();
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'qmediaflow_process_derivative_queue', array( $site_id ), 'qmediaflow', true );
            return true;
        }
        $args = array( $site_id );
        if ( ! wp_next_scheduled( 'qmediaflow_process_derivative_queue', $args ) ) {
            return (bool) wp_schedule_single_event( time() + max( 1, $delay ), 'qmediaflow_process_derivative_queue', $args );
        }
        return true;
    }
}
