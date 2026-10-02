<?php
namespace MediaFlow;

final class Processor {
    private Paths $paths;
    private Manifest_Store $manifests;
    private Resolver $resolver;

    public function __construct( Paths $paths, Manifest_Store $manifests, Resolver $resolver ) {
        $this->paths     = $paths;
        $this->manifests = $manifests;
        $this->resolver  = $resolver;
    }

    /**
     * Generate a variant exactly once.
     *
     * A cache miss must never block a PHP-FPM worker behind another generator.
     * The first worker acquires a non-blocking lock; concurrent workers receive
     * mediaflow_busy so the request handler can immediately fall back to the
     * static source image.
     *
     * @return string|\WP_Error Final file path or error.
     */
    public function generate( int $attachment_id, array $manifest, Variant $variant, ?string $namespace = null ) {
        $target = $this->resolver->path( $attachment_id, $manifest, $variant, $namespace );
        if ( is_readable( $target ) ) {
            return $target;
        }

        $dir = dirname( $target );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error( 'mediaflow_cache_dir', 'MediaFlow could not create the cache directory.' );
        }

        if ( ! $this->paths->is_inside_cache( $dir ) ) {
            return new \WP_Error( 'mediaflow_cache_path', 'MediaFlow refused an unsafe cache path.' );
        }

        $lock_path = $dir . '/.lock-' . hash( 'sha256', basename( $target ) );
        $lock      = @fopen( $lock_path, 'c' );
        if ( ! is_resource( $lock ) ) {
            return new \WP_Error( 'mediaflow_lock', 'MediaFlow could not create a generation lock.' );
        }

        $owns_lock = false;
        $slot = null;
        try {
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                return new \WP_Error( 'mediaflow_busy', 'MediaFlow is already generating this image.' );
            }
            $owns_lock = true;

            clearstatcache( true, $target );
            if ( is_readable( $target ) ) {
                return $target;
            }

            $source = $this->manifests->source_path( $manifest );
            if ( ! $source ) {
                return new \WP_Error( 'mediaflow_source', 'MediaFlow source image is unavailable.' );
            }

            $source_pixels = max( 1, (int) ( $manifest['width'] ?? 0 ) ) * max( 1, (int) ( $manifest['height'] ?? 0 ) );
            [ $out_w, $out_h ] = $this->resolver->output_dimensions( $manifest, $variant );
            if ( $source_pixels > (int) MEDIAFLOW_MAX_SOURCE_PIXELS || $out_w * $out_h > (int) MEDIAFLOW_MAX_OUTPUT_PIXELS ) {
                return new \WP_Error( 'mediaflow_pixel_limit', 'MediaFlow refused an image that exceeds the configured processing pixel limits.' );
            }

            $budget = Budget::check( $source );
            if ( is_wp_error( $budget ) ) { return $budget; }
            $slot = Budget::acquire( $this->paths );
            if ( is_wp_error( $slot ) ) { return $slot; }
            $editor = wp_get_image_editor( $source );
            if ( is_wp_error( $editor ) ) {
                return $editor;
            }

            if ( method_exists( $editor, 'set_quality' ) ) {
                $quality_result = $editor->set_quality( $variant->quality );
                if ( is_wp_error( $quality_result ) ) {
                    return $quality_result;
                }
            }

            $height = $variant->height > 0 ? $variant->height : null;
            $resize = $editor->resize( $variant->width, $height, $variant->crop );
            if ( is_wp_error( $resize ) ) {
                return $resize;
            }

            try {
                $rand = bin2hex( random_bytes( 6 ) );
            } catch ( \Throwable $e ) {
                $rand = uniqid( '', true );
            }

            $temp = $dir . '/.mf-' . $rand . '.' . $variant->extension;
            $save = Encoding::save( $editor, $temp, $variant->mime, Plugin::instance()->settings()->encoding_enabled( $variant->mime ) );
            if ( is_wp_error( $save ) ) {
                @unlink( $temp );
                return $save;
            }

            $saved_path = isset( $save['path'] ) ? (string) $save['path'] : $temp;
            if ( ! is_readable( $saved_path ) ) {
                @unlink( $saved_path );
                return new \WP_Error( 'mediaflow_save', 'MediaFlow image editor did not create an output file.' );
            }

            if ( ! @rename( $saved_path, $target ) ) {
                @unlink( $saved_path );
                return new \WP_Error( 'mediaflow_atomic_write', 'MediaFlow could not atomically publish the generated image.' );
            }

            @chmod( $target, 0644 );
            clearstatcache( true, $target );
            return $target;
        } finally {
            if ( $owns_lock ) {
                @flock( $lock, LOCK_UN );
            }
            @fclose( $lock );
            Budget::release( $slot );
            // Keep lock inode stable across requests, including failed encodes.
        }
    }
}
