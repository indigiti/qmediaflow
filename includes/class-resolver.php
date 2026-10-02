<?php
namespace MediaFlow;

final class Resolver {
    private Paths $paths;
    private Settings $settings;
    private Manifest_Store $manifests;
    private array $format_cache = array();
    private array $format_support_cache = array();
    private ?array $registered_sizes = null;

    public function __construct( Paths $paths, Settings $settings, Manifest_Store $manifests ) {
        $this->paths     = $paths;
        $this->settings  = $settings;
        $this->manifests = $manifests;
    }

    public function filter_image_downsize( $downsize, int $attachment_id, $size ) {
        if ( false !== $downsize || 'full' === $size ) {
            return $downsize;
        }

        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            return $downsize;
        }

        $spec = $this->spec_for_size( $size, $manifest );
        if ( ! $spec ) {
            return $downsize;
        }

        $variant = $this->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $variant ) {
            return $downsize;
        }

        [ $out_w, $out_h ] = $this->output_dimensions( $manifest, $variant );

        return array(
            $this->url( $attachment_id, $manifest, $variant ),
            $out_w,
            $out_h,
            true,
        );
    }

    public function custom_url( int $attachment_id, array $args = array() ) {
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            return false;
        }

        $width  = absint( $args['width'] ?? 0 );
        $height = absint( $args['height'] ?? 0 );
        if ( ! $width && ! $height ) {
            return false;
        }

        if ( ! $width ) {
            $width = (int) round( ( $height / (int) $manifest['height'] ) * (int) $manifest['width'] );
        }

        $spec = array(
            'width'   => $width,
            'height'  => $height,
            'crop'    => ! empty( $args['crop'] ),
            'quality' => isset( $args['quality'] ) ? absint( $args['quality'] ) : $this->settings->quality(),
            'format'  => isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : $this->choose_format( $manifest ),
        );

        $variant = $this->variant_for( $attachment_id, $manifest, $spec );
        return $variant ? $this->url( $attachment_id, $manifest, $variant ) : false;
    }

    /**
     * Return a deliberately small srcset candidate set. Width descriptors are based
     * on the actual constrained output dimensions, not the requested dimensions.
     */
    public function responsive_sources( int $attachment_id, ?int $target_width = null, ?float $aspect_ratio = null, bool $crop = false ): array {
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            return array();
        }

        $source_width = max( 1, (int) $manifest['width'] );
        $target       = $target_width ? min( max( 1, $target_width ), $source_width ) : min( 1024, $source_width );
        $widths       = $this->candidate_widths( $target, $source_width );

        $sources = array();
        foreach ( $widths as $width ) {
            $height = 0;
            if ( $crop && $aspect_ratio && $aspect_ratio > 0 ) {
                $height = (int) round( $width / $aspect_ratio );
            }

            $variant = $this->variant_for(
                $attachment_id,
                $manifest,
                array(
                    'width'   => $width,
                    'height'  => $height,
                    'crop'    => $crop,
                    'quality' => $this->settings->quality(),
                    'format'  => $this->choose_format( $manifest ),
                )
            );
            if ( ! $variant ) {
                continue;
            }

            [ $actual_width ] = $this->output_dimensions( $manifest, $variant );
            $sources[ $actual_width ] = $this->url( $attachment_id, $manifest, $variant );
        }

        ksort( $sources, SORT_NUMERIC );
        return $sources;
    }

    public function spec_for_size( $size, array $manifest ): ?array {
        $width  = 0;
        $height = 0;
        $crop   = false;

        if ( is_array( $size ) ) {
            $width  = absint( $size[0] ?? 0 );
            $height = absint( $size[1] ?? 0 );
        } elseif ( is_string( $size ) ) {
            if ( null === $this->registered_sizes ) {
                $this->registered_sizes = wp_get_registered_image_subsizes();
            }
            $sizes = $this->registered_sizes;
            if ( isset( $sizes[ $size ] ) ) {
                $width  = absint( $sizes[ $size ]['width'] ?? 0 );
                $height = absint( $sizes[ $size ]['height'] ?? 0 );
                if ( is_array( $sizes[ $size ]['crop'] ?? false ) ) { return null; } // Preserve WordPress positional cropping.
                $crop   = ! empty( $sizes[ $size ]['crop'] );
            }
        }

        if ( ! $width && ! $height ) {
            return null;
        }

        if ( ! $width ) {
            $width = (int) round( ( $height / (int) $manifest['height'] ) * (int) $manifest['width'] );
        }

        return array(
            'width'   => $width,
            'height'  => $height,
            'crop'    => $crop,
            'quality' => $this->settings->quality(),
            'format'  => $this->choose_format( $manifest ),
        );
    }

    public function variant_for( int $attachment_id, array $manifest, array $spec ): ?Variant {
        $width   = max( 1, absint( $spec['width'] ?? 0 ) );
        $height  = max( 0, absint( $spec['height'] ?? 0 ) );
        $crop    = ! empty( $spec['crop'] ) && $height > 0;
        $quality = max( 1, min( 100, absint( $spec['quality'] ?? $this->settings->quality() ) ) );
        $format  = sanitize_key( (string) ( $spec['format'] ?? $this->choose_format( $manifest ) ) );

        $source_w = max( 1, (int) $manifest['width'] );
        $source_h = max( 1, (int) $manifest['height'] );

        // Never upscale. For fixed crop ratios, constrain both dimensions together.
        if ( $width > $source_w ) {
            if ( $crop && $height > 0 ) {
                $ratio  = $height / $width;
                $width  = $source_w;
                $height = max( 1, (int) round( $width * $ratio ) );
            } else {
                $width = $source_w;
            }
        }
        if ( $height > $source_h && $crop ) {
            $ratio  = $width / $height;
            $height = $source_h;
            $width  = max( 1, (int) round( $height * $ratio ) );
        }

        if ( ! in_array( $format, array( 'avif', 'webp', 'jpeg', 'png' ), true ) ) {
            $format = $this->choose_format( $manifest );
        }
        if ( ! $this->supports_format( $format ) ) {
            $format = Variant::format_from_mime( (string) $manifest['mime'] );
        }

        if ( $width > 8192 || $height > 8192 || '' === $this->settings->signing_key() ) { return null; }
        $unsigned = new Variant( $width, $height, $crop, $quality, $format );
        [ $out_w, $out_h ] = $this->output_dimensions( $manifest, $unsigned );
        if ( $out_w * $out_h > (int) MEDIAFLOW_MAX_OUTPUT_PIXELS ) {
            return null;
        }

        $sig = $this->signature( $attachment_id, (string) $manifest['revision'], $this->settings->cache_namespace(), $unsigned );
        return $unsigned->with_signature( $sig );
    }

    public function url( int $attachment_id, array $manifest, Variant $variant, ?string $namespace = null ): string {
        $namespace = $namespace ?: $this->settings->cache_namespace();
        return $this->paths->attachment_cache_url( $attachment_id, $namespace, (string) $manifest['revision'] )
            . '/' . $variant->token() . '.' . $variant->extension;
    }

    public function path( int $attachment_id, array $manifest, Variant $variant, ?string $namespace = null ): string {
        $namespace = $namespace ?: $this->settings->cache_namespace();
        return $this->paths->attachment_cache_dir( $attachment_id, $namespace, (string) $manifest['revision'] )
            . '/' . $variant->token() . '.' . $variant->extension;
    }

    public function validate_signature( int $attachment_id, string $revision, string $namespace, Variant $variant ): bool {
        $expected = $this->signature( $attachment_id, $revision, $namespace, $variant );
        return '' !== $expected && hash_equals( $expected, $variant->signature );
    }

    public function output_dimensions( array $manifest, Variant $variant ): array {
        $source_w = max( 1, (int) $manifest['width'] );
        $source_h = max( 1, (int) $manifest['height'] );

        if ( $variant->crop && $variant->height > 0 ) {
            return array( min( $variant->width, $source_w ), min( $variant->height, $source_h ) );
        }

        $ratio = min(
            $variant->width / $source_w,
            $variant->height > 0 ? $variant->height / $source_h : PHP_FLOAT_MAX,
            1
        );
        if ( PHP_FLOAT_MAX === $ratio ) {
            $ratio = min( $variant->width / $source_w, 1 );
        }

        return array(
            max( 1, (int) round( $source_w * $ratio ) ),
            max( 1, (int) round( $source_h * $ratio ) ),
        );
    }

    private function candidate_widths( int $target, int $source_width ): array {
        $target  = min( max( 1, $target ), $source_width );
        $ceiling = min( $source_width, max( $target, $target * 2 ) );
        $allowed = array_values( array_filter( $this->settings->widths(), static fn( int $width ): bool => $width <= $ceiling ) );
        sort( $allowed, SORT_NUMERIC );

        if ( empty( $allowed ) ) {
            return array( $target );
        }

        $lower = 0;
        $at_or_above = 0;
        $next = 0;
        foreach ( $allowed as $width ) {
            if ( $width < $target ) {
                $lower = $width;
                continue;
            }
            if ( 0 === $at_or_above ) {
                $at_or_above = $width;
                continue;
            }
            if ( 0 === $next ) {
                $next = $width;
                break;
            }
        }

        $highest = end( $allowed );
        $selected = array_values( array_unique( array_filter( array( $lower, $at_or_above, $next, $highest ) ) ) );
        sort( $selected, SORT_NUMERIC );

        $max = $this->settings->max_srcset_candidates();
        if ( count( $selected ) > $max ) {
            // Keep candidates closest to the target plus the highest DPR candidate.
            usort(
                $selected,
                static function ( int $a, int $b ) use ( $target ): int {
                    return abs( $a - $target ) <=> abs( $b - $target );
                }
            );
            $selected = array_slice( $selected, 0, $max - 1 );
            $selected[] = $highest;
            $selected = array_values( array_unique( $selected ) );
            sort( $selected, SORT_NUMERIC );
        }

        return $selected ?: array( $target );
    }

    private function choose_format( array $manifest ): string {
        $setting = $this->settings->preferred_format();
        $key     = $setting . '|' . (string) ( $manifest['mime'] ?? '' );
        if ( isset( $this->format_cache[ $key ] ) ) {
            return $this->format_cache[ $key ];
        }

        if ( 'original' === $setting ) {
            return $this->format_cache[ $key ] = Variant::format_from_mime( (string) $manifest['mime'] );
        }

        $candidates = 'avif' === $setting ? array( 'avif', 'webp' ) : array( 'webp', 'avif' );
        foreach ( $candidates as $format ) {
            if ( $this->supports_format( $format ) ) {
                return $this->format_cache[ $key ] = $format;
            }
        }

        return $this->format_cache[ $key ] = Variant::format_from_mime( (string) $manifest['mime'] );
    }

    private function supports_format( string $format ): bool {
        if ( array_key_exists( $format, $this->format_support_cache ) ) {
            return $this->format_support_cache[ $format ];
        }
        return $this->format_support_cache[ $format ] = wp_image_editor_supports(
            array( 'mime_type' => Variant::mime_for_format( $format ) )
        );
    }

    private function signature( int $attachment_id, string $revision, string $namespace, Variant $variant ): string {
        $payload = implode(
            '|',
            array(
                $this->paths->site_id() ? 'mf3-site-' . $this->paths->site_id() : 'mf2',
                $attachment_id,
                $revision,
                $namespace,
                $variant->width,
                $variant->height,
                $variant->crop ? 1 : 0,
                $variant->quality,
                $variant->format,
            )
        );

        $key = $this->settings->signing_key();
        return '' === $key ? '' : substr( hash_hmac( 'sha256', $payload, $key ), 0, 32 );
    }
}
