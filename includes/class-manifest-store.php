<?php
namespace MediaFlow;

final class Manifest_Store {
    private Paths $paths;
    private Settings $settings;
    private array $runtime = array();
    private ?string $lqip_extension_runtime = null;

    public function __construct( Paths $paths, Settings $settings ) {
        $this->paths    = $paths;
        $this->settings = $settings;
    }

    public function get( int $attachment_id ): ?array {
        if ( isset( $this->runtime[ $attachment_id ] ) ) {
            return $this->runtime[ $attachment_id ];
        }

        $path   = $this->paths->manifest_path( $attachment_id );
        $legacy = false;
        if ( ! is_readable( $path ) ) {
            $path   = $this->paths->legacy_manifest_path( $attachment_id );
            $legacy = is_readable( $path );
        }
        if ( ! is_readable( $path ) ) {
            return null;
        }

        $json = file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $data ) || empty( $data['revision'] ) || ! isset( $data['source'] ) ) {
            return null;
        }

        $this->runtime[ $attachment_id ] = $data;

        // Move a v0.1 manifest into the sharded private store without requiring DB access.
        if ( $legacy ) {
            $this->write_atomic( $this->paths->manifest_path( $attachment_id ), $data );
        }

        return $data;
    }

    public function ensure_for_attachment( int $attachment_id ): ?array {
        $manifest = $this->get( $attachment_id );
        $desired  = $this->settings->placeholder_mode();

        if ( $manifest ) {
            $runtime_ready = ! empty( $manifest['source_path'] ) && ! empty( $manifest['source_url'] );
            if ( $runtime_ready ) {
                if ( 'color' === $desired ) {
                    $manifest['placeholder_type'] = 'color';
                    $manifest['placeholder']      = $this->settings->placeholder_color();
                    return $manifest;
                }
                if ( 'gradient' === $desired ) {
                    $manifest['placeholder_type'] = 'gradient';
                    $manifest['placeholder']      = '';
                    return $manifest;
                }
                if ( 'none' === $desired ) {
                    return $manifest;
                }
                if ( 'auto' === $desired ) {
                    $signature = $this->settings->lqip_signature();
                    $extension = $this->lqip_extension();
                    if ( 'auto' === (string) ( $manifest['placeholder_type'] ?? '' )
                        && hash_equals( $signature, (string) ( $manifest['placeholder_signature'] ?? '' ) )
                        && $extension === (string) ( $manifest['lqip_extension'] ?? '' ) ) {
                        return $manifest;
                    }

                    // Existing manifests already contain the source identity and revision.
                    // Migrating them into Auto mode must not perform attachment DB lookups,
                    // source sampling or image decode on the visitor request.
                    $manifest['version']               = 4;
                    $manifest['placeholder_type']      = 'auto';
                    $manifest['placeholder']           = '';
                    $manifest['placeholder_signature'] = $signature;
                    $manifest['lqip_extension']        = $extension;
                    $manifest['lqip_state']            = $this->lqip_file_ready( $attachment_id, $manifest, $signature ) ? 'ready' : 'missing';
                    $manifest['lqip_updated_at']       = 'ready' === $manifest['lqip_state'] ? absint( $manifest['lqip_updated_at'] ?? time() ) : 0;
                    $manifest['updated_at']            = time();
                    if ( $this->write_atomic( $this->paths->manifest_path( $attachment_id ), $manifest ) ) {
                        $this->runtime[ $attachment_id ] = $manifest;
                    }
                    return $manifest;
                }
                if ( 'lqip' === $desired ) {
                    // Existing inline previews stay usable until an explicit bounded rebuild.
                    if ( 'lqip' === (string) ( $manifest['placeholder_type'] ?? '' ) && ! empty( $manifest['placeholder'] ) ) {
                        if ( (string) ( $manifest['placeholder_signature'] ?? '' ) !== $this->settings->lqip_signature() ) {
                            $manifest['lqip_stale'] = true;
                        }
                        return $manifest;
                    }
                    $manifest['placeholder_type'] = 'color';
                    $manifest['placeholder']      = $this->settings->placeholder_color();
                    $manifest['lqip_pending']     = true;
                    return $manifest;
                }
            }
        }

        $rebuilt = $this->build( $attachment_id, null, false );
        if ( ! $rebuilt ) {
            return $manifest ?: null;
        }
        if ( 'lqip' === $desired && empty( $rebuilt['placeholder'] ) ) {
            $rebuilt['placeholder_type'] = 'color';
            $rebuilt['placeholder']      = $this->settings->placeholder_color();
            $rebuilt['lqip_pending']     = true;
        }
        return $rebuilt;
    }

    public function build( int $attachment_id, ?array $metadata = null, bool $allow_lqip = false ): ?array {
        $this->paths->ensure();
        $source = get_attached_file( $attachment_id, true );
        if ( ! is_string( $source ) || '' === $source || ! is_readable( $source ) ) {
            return null;
        }

        $mime = (string) get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
            return null;
        }

        if ( null === $metadata ) {
            $metadata = wp_get_attachment_metadata( $attachment_id );
        }
        $metadata = is_array( $metadata ) ? $metadata : array();

        $width  = isset( $metadata['width'] ) ? absint( $metadata['width'] ) : 0;
        $height = isset( $metadata['height'] ) ? absint( $metadata['height'] ) : 0;
        if ( ! $width || ! $height ) {
            $size = @getimagesize( $source );
            if ( is_array( $size ) ) {
                $width  = absint( $size[0] ?? 0 );
                $height = absint( $size[1] ?? 0 );
            }
        }
        if ( ! $width || ! $height ) {
            return null;
        }

        $source_norm  = wp_normalize_path( $source );
        $uploads      = wp_get_upload_dir();
        $uploads_root = ! empty( $uploads['basedir'] ) ? wp_normalize_path( untrailingslashit( (string) $uploads['basedir'] ) ) . '/' : '';
        $uploads_url  = ! empty( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
        $content_root = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ) . '/';
        $source_type  = 'attachment';
        $source_value = '';
        $source_url   = '';

        if ( $uploads_root && str_starts_with( $source_norm, $uploads_root ) ) {
            $source_type  = 'uploads-relative';
            $source_value = ltrim( substr( $source_norm, strlen( $uploads_root ) ), '/' );
            $source_url   = $uploads_url ? $uploads_url . '/' . str_replace( '%2F', '/', rawurlencode( $source_value ) ) : '';
        } elseif ( str_starts_with( $source_norm, $content_root ) ) {
            $source_type  = 'content-relative';
            $source_value = ltrim( substr( $source_norm, strlen( $content_root ) ), '/' );
            $source_url   = content_url( '/' . $source_value );
        }

        if ( '' === $source_url ) {
            $attachment_url = wp_get_attachment_url( $attachment_id );
            $source_url     = is_string( $attachment_url ) ? $attachment_url : '';
        }

        $fingerprint = implode(
            '|',
            array(
                $source_type,
                $source_value ?: basename( $source_norm ),
                (string) @filesize( $source ),
                (string) @filemtime( $source ),
                (string) $width,
                (string) $height,
                $this->source_sample_hash( $source ),
            )
        );

        $revision              = substr( hash( 'sha256', $fingerprint ), 0, 12 );
        $existing              = $this->get( $attachment_id );
        $placeholder_type      = $this->settings->placeholder_mode();
        $uses_lqip             = in_array( $placeholder_type, array( 'lqip', 'auto' ), true );
        $placeholder_signature = $uses_lqip ? $this->settings->lqip_signature() : '';
        $auto_ready            = 'auto' === $placeholder_type && $existing && $this->lqip_file_ready( $attachment_id, $existing, $placeholder_signature );
        $same_source = $existing
            && hash_equals( (string) ( $existing['revision'] ?? '' ), $revision )
            && (string) ( $existing['source_path'] ?? '' ) === $source_norm
            && (string) ( $existing['mime'] ?? '' ) === $mime
            && (int) ( $existing['width'] ?? 0 ) === $width
            && (int) ( $existing['height'] ?? 0 ) === $height
            && (string) ( $existing['placeholder_type'] ?? '' ) === $placeholder_type
            && ( ! $uses_lqip || hash_equals( $placeholder_signature, (string) ( $existing['placeholder_signature'] ?? '' ) ) )
            && (string) ( $existing['source_url'] ?? '' ) === esc_url_raw( $source_url )
            && ( ! $allow_lqip
                || ( 'lqip' !== $placeholder_type && 'auto' !== $placeholder_type )
                || ( 'lqip' === $placeholder_type && ! empty( $existing['placeholder'] ) )
                || ( 'auto' === $placeholder_type && $auto_ready ) );

        if ( $same_source ) {
            return $existing;
        }

        $placeholder = '';
        $lqip_state  = 'disabled';
        $lqip_ext    = '';
        if ( 'color' === $placeholder_type ) {
            $placeholder = $this->settings->placeholder_color();
        } elseif ( 'lqip' === $placeholder_type ) {
            $lqip_state = 'missing';
            if ( $allow_lqip ) {
                $placeholder = $this->make_placeholder( $source );
                $lqip_state  = '' !== $placeholder ? 'ready' : 'missing';
            }
        } elseif ( 'auto' === $placeholder_type ) {
            $lqip_state = 'missing';
            $lqip_ext   = $this->lqip_extension();
            if ( $allow_lqip ) {
                $asset = $this->create_placeholder_asset( $source, false );
                if ( is_array( $asset ) && $this->write_lqip_asset( $attachment_id, $revision, $placeholder_signature, $asset ) ) {
                    $lqip_state = 'ready';
                    $lqip_ext   = (string) $asset['extension'];
                }
            }
        }

        $manifest = array(
            'version'               => 4,
            'id'                    => $attachment_id,
            'revision'              => $revision,
            'source_type'           => $source_type,
            'source'                => $source_value,
            'source_path'           => $source_norm,
            'source_url'            => esc_url_raw( $source_url ),
            'mime'                  => $mime,
            'width'                 => $width,
            'height'                => $height,
            'placeholder_type'      => $placeholder_type,
            'placeholder'           => $placeholder,
            'placeholder_signature' => $placeholder_signature,
            'lqip_state'            => $lqip_state,
            'lqip_extension'        => $lqip_ext,
            'lqip_updated_at'       => 'ready' === $lqip_state ? time() : 0,
            'updated_at'            => time(),
        );

        if ( ! $this->write_atomic( $this->paths->manifest_path( $attachment_id ), $manifest ) ) {
            return null;
        }

        $this->runtime[ $attachment_id ] = $manifest;
        return $manifest;
    }

    public function source_path( array $manifest ): ?string {
        $absolute = isset( $manifest['source_path'] ) ? (string) $manifest['source_path'] : '';
        if ( '' !== $absolute && is_readable( $absolute ) && ! $this->paths->is_inside_any_cache( $absolute ) && ! $this->paths->is_inside_any_private( $absolute ) ) {
            return $absolute;
        }

        $source      = isset( $manifest['source'] ) ? (string) $manifest['source'] : '';
        $source_type = (string) ( $manifest['source_type'] ?? '' );

        if ( 'uploads-relative' === $source_type ) {
            $uploads = wp_get_upload_dir();
            $path    = ! empty( $uploads['basedir'] ) ? untrailingslashit( (string) $uploads['basedir'] ) . '/' . ltrim( $source, '/' ) : '';
        } elseif ( 'content-relative' === $source_type ) {
            $path = untrailingslashit( WP_CONTENT_DIR ) . '/' . ltrim( $source, '/' );
        } elseif ( 'attachment' === $source_type ) {
            $path = get_attached_file( absint( $manifest['id'] ?? 0 ), true );
        } else {
            $path = '';
        }

        return is_string( $path ) && is_readable( $path ) ? $path : null;
    }

    public function source_url( array $manifest ): ?string {
        $url = isset( $manifest['source_url'] ) ? (string) $manifest['source_url'] : '';
        return '' !== $url ? $url : null;
    }

    public function lqip_extension(): string {
        if ( null !== $this->lqip_extension_runtime ) {
            return $this->lqip_extension_runtime;
        }
        $this->lqip_extension_runtime = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'webp' : 'jpg';
        return $this->lqip_extension_runtime;
    }

    public function lqip_url( int $attachment_id, array $manifest ): string {
        $revision  = (string) ( $manifest['revision'] ?? '' );
        $signature = $this->settings->lqip_signature();
        // Auto-LQIP HTML and the background worker must agree on the current
        // encoder extension. A stale manifest extension could otherwise leave
        // page-cache HTML pointing at a file that will never be published.
        $extension = $this->lqip_extension();
        if ( ! preg_match( '/^[a-f0-9]{12}$/', $revision ) ) {
            return '';
        }
        return $this->paths->lqip_url( $attachment_id, $revision, $signature, $extension );
    }

    public function lqip_path_for_request( int $attachment_id, string $revision, string $signature, string $extension ): string {
        return $this->paths->lqip_path( $attachment_id, $revision, $signature, $extension );
    }

    public function lqip_file_ready( int $attachment_id, array $manifest, ?string $signature = null ): bool {
        $revision = (string) ( $manifest['revision'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{12}$/', $revision ) ) { return false; }
        $signature = $signature ?: $this->settings->lqip_signature();
        // Auto-LQIP HTML and the background worker must agree on the current
        // encoder extension. A stale manifest extension could otherwise leave
        // page-cache HTML pointing at a file that will never be published.
        $extension = $this->lqip_extension();
        return is_readable( $this->paths->lqip_path( $attachment_id, $revision, $signature, $extension ) );
    }

    /**
     * Queue one missing Auto-LQIP without decoding the source in the visitor request.
     * A filesystem marker is the anti-stampede authority; one site-scoped WP-Cron
     * worker drains the queue in bounded one-image runs.
     */
    public function queue_lqip( int $attachment_id, ?array $manifest = null ): bool {
        if ( 'auto' !== $this->settings->placeholder_mode() || $attachment_id < 1 ) {
            return false;
        }
        $manifest = $manifest ?: $this->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) { return false; }

        $revision  = (string) ( $manifest['revision'] ?? '' );
        $signature = $this->settings->lqip_signature();
        if ( ! preg_match( '/^[a-f0-9]{12}$/', $revision ) ) { return false; }
        if ( $this->lqip_file_ready( $attachment_id, $manifest, $signature ) ) { return false; }

        $queue = $this->paths->lqip_queue_path( $attachment_id, $revision, $signature );
        $dir   = dirname( $queue );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }

        $previous = $this->read_queue_marker( $queue );
        $now      = time();
        if ( $previous ) {
            $state       = (string) ( $previous['state'] ?? 'queued' );
            $updated_at  = absint( $previous['updated_at'] ?? 0 );
            $retry_after = absint( $previous['retry_after'] ?? 0 );
            if ( 'failed' === $state && $retry_after > $now ) { return false; }
            if ( in_array( $state, array( 'queued', 'processing' ), true ) && $updated_at > $now - 900 ) { return false; }
            @unlink( $queue );
        }

        $marker = array(
            'state'         => 'queued',
            'site_id'       => $this->paths->site_id(),
            'attachment_id' => $attachment_id,
            'revision'      => $revision,
            'signature'     => $signature,
            'extension'     => $this->lqip_extension(),
            'attempts'      => absint( $previous['attempts'] ?? 0 ),
            'queued_at'     => $now,
            'updated_at'    => $now,
            'retry_after'   => 0,
        );

        $handle = @fopen( $queue, 'x' );
        if ( ! is_resource( $handle ) ) { return false; }
        $json = wp_json_encode( $marker, JSON_UNESCAPED_SLASHES );
        $ok   = is_string( $json ) && false !== @fwrite( $handle, $json );
        @fclose( $handle );
        @chmod( $queue, 0600 );
        if ( ! $ok ) { @unlink( $queue ); return false; }

        if ( ! $this->schedule_lqip_worker( $now + 1 ) ) {
            $marker['state']       = 'failed';
            $marker['updated_at']  = time();
            $marker['retry_after'] = time() + 300;
            $this->write_atomic( $queue, $marker );
            return false;
        }
        return true;
    }

    /** @return array{processed:int,ready:int,failed:int,scheduled:bool} */
    public function process_lqip_queue( int $max_jobs = 1, float $time_budget = 8.0 ): array {
        $result = array( 'processed' => 0, 'ready' => 0, 'failed' => 0, 'scheduled' => false );
        if ( 'auto' !== $this->settings->placeholder_mode() ) { return $result; }

        $root = $this->paths->lqip_queue_root();
        if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) { return $result; }
        $worker = @fopen( $this->paths->lqip_worker_lock_path(), 'c' );
        if ( ! is_resource( $worker ) ) { return $result; }
        if ( ! @flock( $worker, LOCK_EX | LOCK_NB ) ) { @fclose( $worker ); return $result; }

        $started = microtime( true );
        try {
            $scan = $this->scan_queue( max( 1, $max_jobs ) );
            foreach ( $scan['ready'] as $queue_path ) {
                if ( $result['processed'] >= $max_jobs || microtime( true ) - $started >= $time_budget ) { break; }
                ++$result['processed'];
                if ( $this->process_queue_marker( $queue_path ) ) { ++$result['ready']; }
                else { ++$result['failed']; }
            }

            $next = $this->scan_queue( 1 );
            if ( ! empty( $next['ready'] ) ) {
                $result['scheduled'] = $this->schedule_lqip_worker( time() + 2 );
            } elseif ( ! empty( $next['next_retry'] ) ) {
                $result['scheduled'] = $this->schedule_lqip_worker( max( time() + 5, (int) $next['next_retry'] ) );
            }
        } finally {
            @flock( $worker, LOCK_UN );
            @fclose( $worker );
        }
        return $result;
    }

    public function rebuild_lqip( int $attachment_id ): bool {
        $mode = $this->settings->placeholder_mode();
        if ( 'lqip' === $mode ) {
            $manifest = $this->build( $attachment_id, null, true );
            return (bool) ( $manifest && 'lqip' === (string) ( $manifest['placeholder_type'] ?? '' ) && ! empty( $manifest['placeholder'] ) );
        }
        if ( 'auto' === $mode ) {
            $manifest = $this->build( $attachment_id, null, false );
            if ( ! $manifest ) { return false; }
            return $this->generate_external_lqip( $attachment_id, $manifest, $this->settings->lqip_signature() );
        }
        return false;
    }

    public function delete( int $attachment_id ): void {
        unset( $this->runtime[ $attachment_id ] );
        foreach ( array( $this->paths->manifest_path( $attachment_id ), $this->paths->legacy_manifest_path( $attachment_id ) ) as $path ) {
            if ( file_exists( $path ) ) { @unlink( $path ); }
        }
        $queue_pattern = $this->paths->lqip_queue_root() . '/' . $this->paths->attachment_shard( $attachment_id ) . '/' . $attachment_id . '-*.json';
        foreach ( (array) glob( $queue_pattern ) as $queue ) { @unlink( $queue ); }
        $this->remove_empty_parents( dirname( $this->paths->manifest_path( $attachment_id ) ), $this->paths->manifest_dir() );
    }

    private function process_queue_marker( string $queue_path ): bool {
        $marker = $this->read_queue_marker( $queue_path );
        if ( ! $marker ) { @unlink( $queue_path ); return false; }

        $attachment_id = absint( $marker['attachment_id'] ?? 0 );
        $revision      = strtolower( (string) ( $marker['revision'] ?? '' ) );
        $signature     = strtolower( (string) ( $marker['signature'] ?? '' ) );
        if ( $attachment_id < 1 || ! preg_match( '/^[a-f0-9]{12}$/', $revision ) || ! hash_equals( $this->settings->lqip_signature(), $signature ) ) {
            @unlink( $queue_path );
            return false;
        }

        $manifest = $this->ensure_for_attachment( $attachment_id );
        if ( ! $manifest || ! hash_equals( (string) ( $manifest['revision'] ?? '' ), $revision ) ) {
            @unlink( $queue_path );
            return false;
        }

        $marker['state']      = 'processing';
        $marker['updated_at'] = time();
        $this->write_atomic( $queue_path, $marker );

        if ( $this->generate_external_lqip( $attachment_id, $manifest, $signature ) ) {
            @unlink( $queue_path );
            return true;
        }

        $attempts = absint( $marker['attempts'] ?? 0 ) + 1;
        $delay    = min( 3600, 60 * ( 2 ** min( 5, $attempts ) ) );
        $marker['state']       = 'failed';
        $marker['attempts']    = $attempts;
        $marker['updated_at']  = time();
        $marker['retry_after'] = time() + $delay;
        $this->write_atomic( $queue_path, $marker );
        return false;
    }

    private function generate_external_lqip( int $attachment_id, array $manifest, string $signature ): bool {
        if ( ! hash_equals( $this->settings->lqip_signature(), $signature ) ) { return false; }
        $revision = (string) ( $manifest['revision'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{12}$/', $revision ) ) { return false; }
        $extension = $this->lqip_extension();
        $target    = $this->paths->lqip_path( $attachment_id, $revision, $signature, $extension );
        if ( is_readable( $target ) ) { return true; }

        $lock_path = $this->paths->lqip_job_lock_path( $attachment_id, $revision, $signature );
        $lock_dir  = dirname( $lock_path );
        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) { return false; }
        $lock = @fopen( $lock_path, 'c' );
        if ( ! is_resource( $lock ) ) { return false; }
        if ( ! @flock( $lock, LOCK_EX | LOCK_NB ) ) { @fclose( $lock ); return is_readable( $target ); }

        try {
            clearstatcache( true, $target );
            if ( is_readable( $target ) ) { return true; }
            $source = $this->source_path( $manifest );
            if ( ! $source ) { return false; }
            $asset = $this->create_placeholder_asset( $source, false );
            if ( ! is_array( $asset ) || (string) $asset['extension'] !== $extension ) { return false; }
            if ( ! $this->write_lqip_asset( $attachment_id, $revision, $signature, $asset ) ) { return false; }

            $manifest['placeholder_type']      = 'auto';
            $manifest['placeholder_signature'] = $signature;
            $manifest['lqip_state']            = 'ready';
            $manifest['lqip_extension']        = $extension;
            $manifest['lqip_updated_at']       = time();
            $manifest['updated_at']            = time();
            if ( $this->write_atomic( $this->paths->manifest_path( $attachment_id ), $manifest ) ) {
                $this->runtime[ $attachment_id ] = $manifest;
            }
            return true;
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    /** @return array{ready:array<int,string>,next_retry:int} */
    private function scan_queue( int $ready_limit ): array {
        $ready = array();
        $next_retry = 0;
        $root = $this->paths->lqip_queue_root();
        $level_one = @scandir( $root );
        foreach ( is_array( $level_one ) ? $level_one : array() as $one ) {
            if ( ! preg_match( '/^\d{2}$/', (string) $one ) ) { continue; }
            $one_path = $root . '/' . $one;
            $level_two = @scandir( $one_path );
            foreach ( is_array( $level_two ) ? $level_two : array() as $two ) {
                if ( ! preg_match( '/^\d{2}$/', (string) $two ) ) { continue; }
                $two_path = $one_path . '/' . $two;
                $files = @scandir( $two_path );
                foreach ( is_array( $files ) ? $files : array() as $file ) {
                    if ( ! str_ends_with( (string) $file, '.json' ) ) { continue; }
                    $path = $two_path . '/' . $file;
                    $marker = $this->read_queue_marker( $path );
                    if ( ! $marker ) { @unlink( $path ); continue; }
                    $retry_after = absint( $marker['retry_after'] ?? 0 );
                    if ( 'failed' === (string) ( $marker['state'] ?? '' ) && $retry_after > time() ) {
                        $next_retry = 0 === $next_retry ? $retry_after : min( $next_retry, $retry_after );
                        continue;
                    }
                    $ready[] = $path;
                    if ( count( $ready ) >= $ready_limit ) { return array( 'ready' => $ready, 'next_retry' => $next_retry ); }
                }
            }
        }
        return array( 'ready' => $ready, 'next_retry' => $next_retry );
    }

    private function schedule_lqip_worker( int $timestamp ): bool {
        $args = array( $this->paths->site_id() );
        $existing = wp_next_scheduled( 'mediaflow_process_lqip_queue', $args );
        if ( false !== $existing && (int) $existing <= $timestamp + 5 ) { return true; }
        $scheduled = wp_schedule_single_event( max( time() + 1, $timestamp ), 'mediaflow_process_lqip_queue', $args, true );
        return ! is_wp_error( $scheduled ) && false !== $scheduled;
    }

    private function read_queue_marker( string $path ): ?array {
        if ( ! is_readable( $path ) ) { return null; }
        $json = @file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function source_sample_hash( string $source ): string {
        $handle = @fopen( $source, 'rb' );
        if ( ! is_resource( $handle ) ) { return ''; }
        $size  = (int) @filesize( $source );
        $first = (string) @fread( $handle, 4096 );
        $last  = '';
        if ( $size > 4096 && 0 === @fseek( $handle, max( 0, $size - 4096 ) ) ) {
            $last = (string) @fread( $handle, 4096 );
        }
        @fclose( $handle );
        return substr( hash( 'sha256', $first . '|' . $last ), 0, 16 );
    }

    private function make_placeholder( string $source ): string {
        $asset = $this->create_placeholder_asset( $source, true );
        if ( ! is_array( $asset ) ) { return ''; }
        return 'data:' . (string) $asset['mime'] . ';base64,' . base64_encode( (string) $asset['bytes'] );
    }

    /** @return array{bytes:string,mime:string,extension:string,width:int,quality:int}|null */
    private function create_placeholder_asset( string $source, bool $inline ): ?array {
        if ( is_wp_error( Budget::check( $source ) ) ) { return null; }
        $slot = Budget::acquire( $this->paths );
        if ( is_wp_error( $slot ) ) { return null; }

        try {
            // Decode exactly once; quality retries save the same working image and
            // resolution fallbacks progressively downscale that editor.
            $editor = wp_get_image_editor( $source );
            if ( is_wp_error( $editor ) ) { return null; }

            $target  = $this->settings->lqip_width();
            $quality = $this->settings->lqip_quality();
            $budget  = $this->settings->lqip_max_bytes();
            $lower   = 16;
            foreach ( array( 128, 64, 32, 16 ) as $candidate ) {
                if ( $candidate < $target ) { $lower = $candidate; break; }
            }

            $attempts = array(
                array( $target, $quality ),
                array( $target, max( 8, $quality - 8 ) ),
                array( $lower, $quality ),
                array( 16, max( 8, $quality - 8 ) ),
            );
            $seen = array();
            $size = method_exists( $editor, 'get_size' ) ? $editor->get_size() : array();
            $current_width = absint( is_array( $size ) ? ( $size['width'] ?? 0 ) : 0 );
            if ( $current_width < 1 ) { return null; }

            $extension = $this->lqip_extension();
            $format    = 'webp' === $extension ? 'webp' : 'jpeg';
            $mime      = Variant::mime_for_format( $format );

            foreach ( $attempts as $attempt ) {
                $requested_width = max( 16, (int) $attempt[0] );
                $attempt_width   = min( $requested_width, $current_width );
                $attempt_quality = (int) $attempt[1];
                $key = $attempt_width . ':' . $attempt_quality;
                if ( isset( $seen[ $key ] ) ) { continue; }
                $seen[ $key ] = true;

                if ( $attempt_width < $current_width ) {
                    $resize = $editor->resize( $attempt_width, null, false );
                    if ( is_wp_error( $resize ) ) { continue; }
                    $current_width = $attempt_width;
                }

                $bytes = $this->encode_placeholder_editor( $editor, $mime, $extension, $attempt_quality );
                if ( '' === $bytes ) { continue; }
                $measured = $inline ? strlen( 'data:' . $mime . ';base64,' ) + strlen( base64_encode( $bytes ) ) : strlen( $bytes );
                if ( $measured <= $budget ) {
                    return array(
                        'bytes'     => $bytes,
                        'mime'      => $mime,
                        'extension' => $extension,
                        'width'     => $attempt_width,
                        'quality'   => $attempt_quality,
                    );
                }
            }
            return null;
        } finally {
            Budget::release( $slot );
        }
    }

    private function encode_placeholder_editor( $editor, string $mime, string $ext, int $quality ): string {
        if ( method_exists( $editor, 'set_quality' ) ) { $editor->set_quality( $quality ); }
        $tmp_dir = $this->paths->temp_dir();
        if ( ! is_dir( $tmp_dir ) && ! wp_mkdir_p( $tmp_dir ) ) { return ''; }
        try { $rand = bin2hex( random_bytes( 6 ) ); }
        catch ( \Throwable $e ) { $rand = uniqid( '', true ); }
        $tmp = $tmp_dir . '/lqip-' . $rand . '.' . $ext;
        $save = $editor->save( $tmp, $mime );
        if ( is_wp_error( $save ) ) { @unlink( $tmp ); return ''; }
        $saved = isset( $save['path'] ) ? (string) $save['path'] : $tmp;
        $bytes = is_readable( $saved ) ? file_get_contents( $saved ) : false;
        @unlink( $saved );
        return is_string( $bytes ) ? $bytes : '';
    }

    private function write_lqip_asset( int $attachment_id, string $revision, string $signature, array $asset ): bool {
        $extension = (string) ( $asset['extension'] ?? $this->lqip_extension() );
        $bytes     = (string) ( $asset['bytes'] ?? '' );
        if ( '' === $bytes ) { return false; }
        return $this->write_atomic_binary( $this->paths->lqip_path( $attachment_id, $revision, $signature, $extension ), $bytes );
    }

    private function write_atomic_binary( string $path, string $bytes ): bool {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        try { $suffix = bin2hex( random_bytes( 6 ) ); }
        catch ( \Throwable $e ) { $suffix = uniqid( '', true ); }
        $tmp = $path . '.' . $suffix . '.tmp';
        if ( false === @file_put_contents( $tmp, $bytes, LOCK_EX ) ) { return false; }
        if ( ! @chmod( $tmp, 0644 ) || ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
        @chmod( $path, 0644 );
        return true;
    }

    private function write_atomic( string $path, array $data ): bool {
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        try { $suffix = bin2hex( random_bytes( 6 ) ); }
        catch ( \Throwable $e ) { $suffix = uniqid( '', true ); }
        $tmp  = $path . '.' . $suffix . '.tmp';
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || false === @file_put_contents( $tmp, $json, LOCK_EX ) ) { return false; }
        if ( ! @chmod( $tmp, 0600 ) || ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
        @chmod( $path, 0600 );
        return true;
    }

    private function remove_empty_parents( string $path, string $stop ): void {
        $stop = rtrim( wp_normalize_path( $stop ), '/' );
        $path = rtrim( wp_normalize_path( $path ), '/' );
        while ( str_starts_with( $path, $stop . '/' ) ) {
            $items = @scandir( $path );
            if ( ! is_array( $items ) || count( $items ) > 2 || ! @rmdir( $path ) ) { break; }
            $path = dirname( $path );
        }
    }
}
