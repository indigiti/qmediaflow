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
     * mediaflow_busy so the request handler can immediately fall back to source.
     *
     * @return string|\WP_Error Final file path or error.
     */
    public function generate( int $attachment_id, array $manifest, Variant $variant, ?string $namespace = null ) {
        $target = $this->resolver->path( $attachment_id, $manifest, $variant, $namespace );
        if ( is_readable( $target ) ) { return $target; }

        $dir = dirname( $target );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error( 'mediaflow_cache_dir', 'QMediaFlow could not create the cache directory.' );
        }
        if ( ! $this->paths->is_inside_cache( $dir ) ) {
            return new \WP_Error( 'mediaflow_cache_path', 'QMediaFlow refused an unsafe cache path.' );
        }

        $lock_path = $dir . '/.lock-' . hash( 'sha256', basename( $target ) );
        $lock = @fopen( $lock_path, 'c' );
        if ( ! is_resource( $lock ) ) {
            return new \WP_Error( 'mediaflow_lock', 'QMediaFlow could not create a generation lock.' );
        }

        $owns_lock = false;
        $slot = null;
        $started = microtime( true );
        try {
            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                Telemetry::event( 'variant_lock_busy' );
                return new \WP_Error( 'mediaflow_busy', 'QMediaFlow is already generating this image.' );
            }
            $owns_lock = true;

            clearstatcache( true, $target );
            if ( is_readable( $target ) ) { return $target; }

            $source = $this->manifests->source_path( $manifest );
            if ( ! $source ) { return new \WP_Error( 'mediaflow_source', 'QMediaFlow source image is unavailable.' ); }

            $source_pixels = max( 1, (int) ( $manifest['width'] ?? 0 ) ) * max( 1, (int) ( $manifest['height'] ?? 0 ) );
            [ $out_w, $out_h ] = $this->resolver->output_dimensions( $manifest, $variant );
            if ( $source_pixels > (int) MEDIAFLOW_MAX_SOURCE_PIXELS || $out_w * $out_h > (int) MEDIAFLOW_MAX_OUTPUT_PIXELS ) {
                return new \WP_Error( 'mediaflow_pixel_limit', 'QMediaFlow refused an image that exceeds configured processing pixel limits.' );
            }

            $budget = Budget::check( $source );
            if ( is_wp_error( $budget ) ) { return $budget; }
            $slot = Budget::acquire( $this->paths );
            if ( is_wp_error( $slot ) ) { return $slot; }

            Telemetry::event( 'generation_started' );
            $editor = wp_get_image_editor( $source );
            if ( is_wp_error( $editor ) ) { return $editor; }

            if ( method_exists( $editor, 'set_quality' ) ) {
                $quality_result = $editor->set_quality( $variant->quality );
                if ( is_wp_error( $quality_result ) ) { return $quality_result; }
            }

            if ( $variant->crop && $variant->height > 0 && $variant->has_focal_point() && method_exists( $editor, 'crop' ) ) {
                [ $src_x, $src_y, $crop_w, $crop_h ] = $this->focal_crop_box(
                    max( 1, (int) $manifest['width'] ),
                    max( 1, (int) $manifest['height'] ),
                    $out_w,
                    $out_h,
                    $variant->focal_x,
                    $variant->focal_y
                );
                $crop_result = $editor->crop( $src_x, $src_y, $crop_w, $crop_h, $out_w, $out_h, false );
                if ( is_wp_error( $crop_result ) ) { return $crop_result; }
            } else {
                $height = $variant->height > 0 ? $variant->height : null;
                $resize = $editor->resize( $variant->width, $height, $variant->crop );
                if ( is_wp_error( $resize ) ) { return $resize; }
            }

            try { $rand = bin2hex( random_bytes( 6 ) ); }
            catch ( \Throwable $e ) { $rand = uniqid( '', true ); }

            $temp = $dir . '/.mf-' . $rand . '.' . $variant->extension;
            $save = Encoding::save( $editor, $temp, $variant->mime, Plugin::instance()->settings()->encoding_enabled( $variant->mime ) );
            if ( is_wp_error( $save ) ) { @unlink( $temp ); return $save; }

            $saved_path = isset( $save['path'] ) ? (string) $save['path'] : $temp;
            if ( ! is_readable( $saved_path ) ) {
                @unlink( $saved_path );
                return new \WP_Error( 'mediaflow_save', 'QMediaFlow image editor did not create an output file.' );
            }

            if ( ! @rename( $saved_path, $target ) ) {
                @unlink( $saved_path );
                return new \WP_Error( 'mediaflow_atomic_write', 'QMediaFlow could not atomically publish the generated image.' );
            }

            @chmod( $target, 0644 );
            clearstatcache( true, $target );
            $generated_bytes = max( 0, (int) @filesize( $target ) );
            $source_bytes = max( 0, (int) @filesize( $source ) );
            Telemetry::event( 'generation_ready' );
            Telemetry::bytes( 'generated_bytes', $generated_bytes );
            Telemetry::bytes( 'source_bytes_processed', $source_bytes );
            if ( $source_bytes > $generated_bytes ) { Telemetry::bytes( 'bytes_saved', $source_bytes - $generated_bytes ); }
            Telemetry::timing( 'generation_ms', ( microtime( true ) - $started ) * 1000 );

            if ( class_exists( Features::class ) && method_exists( Features::instance(), 'distribution' ) ) {
                try { Features::instance()->distribution()->enqueue_file( $target, $variant->mime ); }
                catch ( \Throwable $e ) { Telemetry::event( 'distribution_enqueue_error' ); }
            }
            return $target;
        } finally {
            if ( $owns_lock ) { @flock( $lock, LOCK_UN ); }
            @fclose( $lock );
            Budget::release( $slot );
            // Keep lock inode stable across requests, including failed encodes.
        }
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function focal_crop_box( int $source_w, int $source_h, int $out_w, int $out_h, int $focal_x, int $focal_y ): array {
        $target_ratio = $out_w / max( 1, $out_h );
        $source_ratio = $source_w / max( 1, $source_h );
        if ( $source_ratio > $target_ratio ) {
            $crop_h = $source_h;
            $crop_w = max( 1, (int) round( $source_h * $target_ratio ) );
        } else {
            $crop_w = $source_w;
            $crop_h = max( 1, (int) round( $source_w / $target_ratio ) );
        }
        $center_x = ( $focal_x / 100 ) * $source_w;
        $center_y = ( $focal_y / 100 ) * $source_h;
        $x = max( 0, min( $source_w - $crop_w, (int) round( $center_x - $crop_w / 2 ) ) );
        $y = max( 0, min( $source_h - $crop_h, (int) round( $center_y - $crop_h / 2 ) ) );
        return array( $x, $y, $crop_w, $crop_h );
    }
}
