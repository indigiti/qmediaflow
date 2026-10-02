<?php
namespace MediaFlow;

final class Budget {
    public static function acquire( Paths $paths ) {
        $dir = $paths->processing_lock_dir();
        if ( ! wp_mkdir_p( $dir ) ) { return new \WP_Error( 'mediaflow_budget_storage', 'Processing lock storage is unavailable.' ); }
        $limit = class_exists( Runtime_Config::class ) ? Runtime_Config::generator_limit() : max( 1, min( 16, (int) MEDIAFLOW_MAX_GENERATORS ) );
        for ( $i = 0; $i < $limit; ++$i ) {
            $lock = @fopen( $dir . '/slot-' . $i, 'c' );
            if ( ! is_resource( $lock ) ) { continue; }
            if ( @flock( $lock, LOCK_EX | LOCK_NB ) ) { return $lock; }
            @fclose( $lock );
        }
        if ( class_exists( Telemetry::class ) ) { Telemetry::event( 'generator_capacity_busy' ); }
        return new \WP_Error( 'mediaflow_capacity', 'Image processing capacity is busy.' );
    }

    public static function release( $lock ): void {
        if ( is_resource( $lock ) ) { @flock( $lock, LOCK_UN ); @fclose( $lock ); }
    }

    public static function check( string $source ) {
        $size = @getimagesize( $source );
        if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) { return new \WP_Error( 'mediaflow_header', 'Cannot read image dimensions.' ); }
        $pixels = (int) $size[0] * (int) $size[1];
        if ( $pixels > (int) MEDIAFLOW_MAX_SOURCE_PIXELS ) { return new \WP_Error( 'mediaflow_pixel_limit', 'Source exceeds the processing pixel limit.' ); }
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( '-1' !== $raw && '' !== $raw ) {
            $unit = strtolower( substr( $raw, -1 ) );
            $limit = (float) $raw * match ( $unit ) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
            // Conservative admission estimate; native ImageMagick limits still belong on the server.
            if ( memory_get_usage( true ) + $pixels * 16 + 33554432 > $limit ) { return new \WP_Error( 'mediaflow_memory_budget', 'Insufficient image-processing memory budget.' ); }
        }
        return true;
    }
}
