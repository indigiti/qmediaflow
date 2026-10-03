<?php
namespace MediaFlow;

/** Deterministic quality policy based on cheap source characteristics. */
final class Encoding_Policy {
    private static array $source_bytes_cache = array();

    public static function quality( array $manifest, int $output_width, string $format, int $base_quality ): int {
        $base_quality = max( 1, min( 100, $base_quality ) );
        if ( ! Runtime_Config::content_aware_enabled() ) { return $base_quality; }

        $pixels = max( 1, (int) ( $manifest['width'] ?? 1 ) * (int) ( $manifest['height'] ?? 1 ) );
        $bytes = absint( $manifest['source_bytes'] ?? 0 );
        if ( $bytes < 1 && ! empty( $manifest['source_path'] ) ) {
            $path = (string) $manifest['source_path'];
            $key = (string) ( $manifest['revision'] ?? '' ) . '|' . $path;
            if ( array_key_exists( $key, self::$source_bytes_cache ) ) {
                $bytes = self::$source_bytes_cache[ $key ];
            } else {
                $bytes = is_readable( $path ) ? max( 0, (int) @filesize( $path ) ) : 0;
                self::$source_bytes_cache[ $key ] = $bytes;
            }
        }
        $density = $bytes > 0 ? $bytes / $pixels : 0.5;
        $quality = $base_quality;

        // Source byte density is a cheap, stable proxy for entropy/edge density.
        if ( $density >= 1.5 ) { $quality -= 7; }
        elseif ( $density >= 0.9 ) { $quality -= 4; }
        elseif ( $density <= 0.18 ) { $quality += 5; }
        elseif ( $density <= 0.35 ) { $quality += 2; }

        if ( $output_width <= 480 ) { $quality += 3; }
        elseif ( $output_width >= 1600 ) { $quality -= 2; }

        $mime = (string) ( $manifest['mime'] ?? '' );
        if ( 'image/png' === $mime ) { $quality += 2; }
        if ( 'avif' === $format ) { $quality -= 5; }
        if ( 'jpeg' === $format ) { $quality += 1; }

        $quality = max( 45, min( 95, $quality ) );
        return function_exists( 'apply_filters' )
            ? max( 1, min( 100, (int) apply_filters( 'qmediaflow_content_quality', $quality, $manifest, $output_width, $format, $base_quality ) ) )
            : $quality;
    }

    public static function signature( array $manifest, int $output_width, string $format, int $base_quality ): string {
        return substr( hash( 'sha256', implode( '|', array(
            'qmf-policy-v1',
            (string) ( $manifest['revision'] ?? '' ),
            $output_width,
            $format,
            $base_quality,
            self::quality( $manifest, $output_width, $format, $base_quality ),
        ) ) ), 0, 12 );
    }
}
