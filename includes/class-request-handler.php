<?php
namespace MediaFlow;

final class Request_Handler {
    private Paths $paths;
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Processor $processor;

    public function __construct( Paths $paths, Manifest_Store $manifests, Resolver $resolver, Processor $processor ) {
        $this->paths      = $paths;
        $this->manifests  = $manifests;
        $this->resolver   = $resolver;
        $this->processor  = $processor;
    }

    /**
     * Handles cache misses only. Existing files should be served by the web server
     * before WordPress/PHP is reached.
     */
    public function maybe_handle(): void {
        if ( is_admin() ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
        $path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
        $prefix      = rtrim( $this->paths->cache_url_path(), '/' ) . '/';

        if ( ! str_starts_with( $path, $prefix ) ) {
            return;
        }

        $relative = ltrim( substr( $path, strlen( $prefix ) ), '/' );
        $pattern  = '#^(\d{2})/(\d{2})/(\d+)/([a-zA-Z0-9_-]{4,32})/([a-f0-9]{12})/w(\d+)-h(\d+)-c([01])-q(\d+)-s([a-f0-9]{32})\.(avif|webp|jpe?g|png)$#i';

        if ( ! preg_match( $pattern, $relative, $m ) ) {
            $this->fail( 404, 'Invalid MediaFlow image request.' );
        }

        $attachment_id = absint( $m[3] );
        $namespace     = (string) $m[4];
        $revision      = strtolower( $m[5] );
        $width         = absint( $m[6] );
        $height        = absint( $m[7] );
        $crop          = '1' === $m[8];
        $quality       = absint( $m[9] );
        $signature     = strtolower( $m[10] );
        $extension     = strtolower( $m[11] );
        $format        = match ( $extension ) {
            'avif'        => 'avif',
            'webp'        => 'webp',
            'png'         => 'png',
            'jpg', 'jpeg' => 'jpeg',
            default       => 'jpeg',
        };

        if ( $attachment_id < 1 || $m[1] . '/' . $m[2] !== $this->paths->attachment_shard( $attachment_id ) ) {
            $this->fail( 404, 'Invalid MediaFlow shard.' );
        }

        if ( $width < 1 || $width > 8192 || $height > 8192 || $quality < 1 || $quality > 100 ) {
            $this->fail( 400, 'MediaFlow transformation is outside the allowed limits.' );
        }

        $variant = new Variant( $width, $height, $crop, $quality, $format, $signature );
        if ( ! $this->resolver->validate_signature( $attachment_id, $revision, $namespace, $variant ) ) {
            $this->fail( 403, 'Invalid MediaFlow transformation signature.' );
        }

        $manifest = $this->manifests->get( $attachment_id );
        if ( ! $manifest ) {
            // One-time recovery for pre-MediaFlow/legacy attachments only.
            $manifest = $this->manifests->build( $attachment_id );
        }
        if ( ! $manifest || ! hash_equals( (string) $manifest['revision'], $revision ) ) {
            $this->fail( 404, 'MediaFlow source revision was not found.' );
        }

        $source_pixels = max( 1, (int) ( $manifest['width'] ?? 0 ) ) * max( 1, (int) ( $manifest['height'] ?? 0 ) );
        if ( $source_pixels > (int) MEDIAFLOW_MAX_SOURCE_PIXELS ) {
            if ( $this->fallback_to_source( $manifest, 'source_too_large' ) ) {
                return;
            }
            $this->fail( 413, 'MediaFlow source image exceeds the configured processing pixel limit.' );
        }


        [ $out_w, $out_h ] = $this->resolver->output_dimensions( $manifest, $variant );
        if ( $out_w * $out_h > (int) MEDIAFLOW_MAX_OUTPUT_PIXELS ) {
            $this->fail( 400, 'MediaFlow output exceeds the configured processing pixel limit.' );
        }

        $target = $this->resolver->path( $attachment_id, $manifest, $variant, $namespace );
        if ( ! is_readable( $target ) ) {
            $target = $this->processor->generate( $attachment_id, $manifest, $variant, $namespace );
        }

        if ( is_wp_error( $target ) ) {
            // Anti-stampede and fail-open behavior: do not hold a PHP worker while
            // another worker is generating, and do not break content if processing fails.
            if ( $this->fallback_to_source( $manifest, $target->get_error_code() ) ) {
                return;
            }
            $this->fail( 500, $target->get_error_message() );
        }

        if ( ! is_string( $target ) || ! is_readable( $target ) ) {
            if ( $this->fallback_to_source( $manifest, 'unavailable' ) ) {
                return;
            }
            $this->fail( 500, 'MediaFlow could not generate this image.' );
        }

        $this->serve( $target, $variant->mime );
    }

    private function fallback_to_source( array $manifest, string $reason ): bool {
        $source_url = $this->manifests->source_url( $manifest );
        if ( ! $source_url ) {
            return false;
        }

        while ( ob_get_level() ) {
            @ob_end_clean();
        }

        status_header( 302 );
        header( 'Location: ' . esc_url_raw( $source_url ) );
        header( 'Cache-Control: no-store, max-age=0' );
        header( 'X-MediaFlow-Fallback: ' . sanitize_key( $reason ) );
        header( 'X-Content-Type-Options: nosniff' );
        exit;
    }

    private function serve( string $path, string $mime ): void {
        while ( ob_get_level() ) {
            @ob_end_clean();
        }

        $size  = (int) filesize( $path );
        $mtime = (int) filemtime( $path );

        status_header( 200 );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) $size );
        header( 'Cache-Control: public, max-age=31536000, immutable' );
        header( 'X-Content-Type-Options: nosniff' );
        header( sprintf( 'ETag: W/"%x-%x"', $size, $mtime ) );

        if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
            readfile( $path );
        }
        exit;
    }

    private function fail( int $status, string $message ): void {
        status_header( $status );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        echo esc_html( $message );
        exit;
    }
}
