<?php
namespace MediaFlow;

final class Responsive {
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Settings $settings;
    private static int $inline_lqip_count = 0;
    private static int $inline_lqip_bytes = 0;
    private static int $auto_lqip_count = 0;

    public function __construct( Manifest_Store $manifests, Resolver $resolver, Settings $settings ) {
        $this->manifests = $manifests;
        $this->resolver  = $resolver;
        $this->settings  = $settings;
    }

    public function filter_attachment_attributes( array $attr, \WP_Post $attachment, $size ): array {
        $attachment_id = (int) $attachment->ID;
        $manifest      = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            return $attr;
        }

        $spec = $this->resolver->spec_for_size( $size, $manifest );
        if ( ! $spec ) {
            return $attr;
        }

        $variant = $this->resolver->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $variant ) {
            return $attr;
        }

        [ $display_w, $display_h ] = $this->resolver->output_dimensions( $manifest, $variant );
        $aspect_ratio              = $variant->crop && $display_h > 0 ? $display_w / $display_h : null;
        $sources                   = $this->resolver->responsive_sources( $attachment_id, $display_w, $aspect_ratio, $variant->crop );
        $sources[ $display_w ]     = $this->resolver->url( $attachment_id, $manifest, $variant );
        $sources = $this->bounded_sources( $sources, $display_w );
        ksort( $sources, SORT_NUMERIC );

        if ( count( $sources ) > 1 ) {
            $attr['srcset'] = $this->srcset_string( $sources );
            if ( empty( $attr['sizes'] ) ) {
                $base          = sprintf( '(max-width: %dpx) 100vw, %dpx', $display_w, $display_w );
                $attr['sizes'] = ( isset( $attr['loading'] ) && 'lazy' === $attr['loading'] ) ? 'auto, ' . $base : $base;
            }
        }

        $attr['data-mediaflow'] = '1';
        $this->apply_progressive_attributes( $attr, $manifest, $attachment_id );
        return $attr;
    }

    public function filter_content_img_tag( string $html, string $context, int $attachment_id ): string {
        if ( $attachment_id < 1 || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
            return $html;
        }

        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            return $html;
        }

        $processor = new \WP_HTML_Tag_Processor( $html );
        if ( ! $processor->next_tag( 'img' ) ) {
            return $html;
        }

        if ( $processor->get_attribute( 'data-mediaflow' ) ) { return $html; }
        $existing_width = absint( $processor->get_attribute( 'width' ) );
        $existing_height = absint( $processor->get_attribute( 'height' ) );
        if ( $existing_width && $existing_height && abs( $existing_width / $existing_height - (int) $manifest['width'] / (int) $manifest['height'] ) > 0.01 ) { return $html; }
        $classes = (string) $processor->get_attribute( 'class' );
        if ( preg_match( '/(?:^|\s)size-([a-zA-Z0-9_-]+)/', $classes, $match ) && 'full' !== $match[1] ) {
            $sizes = wp_get_registered_image_subsizes();
            if ( ! empty( $sizes[ $match[1] ]['crop'] ) ) { return $html; }
        }
        $display_width  = $existing_width ?: min( 1024, (int) $manifest['width'] );
        $display_width  = min( $display_width, (int) $manifest['width'] );

        $url = $this->resolver->custom_url(
            $attachment_id,
            array(
                'width' => $display_width,
            )
        );
        if ( ! $url ) {
            return $html;
        }

        $sources                   = $this->resolver->responsive_sources( $attachment_id, $display_width );
        $sources[ $display_width ] = $url;
        $sources = $this->bounded_sources( $sources, $display_width );
        ksort( $sources, SORT_NUMERIC );

        $processor->set_attribute( 'width', $display_width );
        $processor->set_attribute( 'height', max( 1, (int) round( $display_width * (int) $manifest['height'] / (int) $manifest['width'] ) ) );
        $processor->set_attribute( 'src', $url );
        $processor->set_attribute( 'data-mediaflow', '1' );
        $this->apply_progressive_processor( $processor, $manifest, $attachment_id );

        if ( count( $sources ) > 1 ) {
            $processor->set_attribute( 'srcset', $this->srcset_string( $sources ) );
            if ( ! $processor->get_attribute( 'sizes' ) ) {
                $loading = (string) $processor->get_attribute( 'loading' );
                $base    = sprintf( '(max-width: %dpx) 100vw, %dpx', $display_width, $display_width );
                $processor->set_attribute( 'sizes', 'lazy' === $loading ? 'auto, ' . $base : $base );
            }
        }

        return $processor->get_updated_html();
    }

    private function apply_progressive_attributes( array &$attr, array $manifest, int $attachment_id ): void {
        if ( ! $this->settings->progressive() ) { return; }
        $mode  = $this->settings->placeholder_mode();
        $style = isset( $attr['style'] ) ? rtrim( (string) $attr['style'], '; ' ) . ';' : '';

        if ( 'auto' === $mode ) {
            $gradient = $this->gradient_css( $attachment_id, $manifest );
            if ( $this->can_use_auto_lqip() ) {
                $url = $this->manifests->lqip_url( $attachment_id, $manifest );
                if ( '' !== $url ) {
                    $style .= "background-image:url('" . esc_url_raw( $url ) . "')," . $gradient . ';background-size:cover,cover;background-position:center,center;background-repeat:no-repeat,no-repeat;';
                    $attr['data-mediaflow-progressive'] = 'auto-lqip';
                    $this->manifests->queue_lqip( $attachment_id, $manifest );
                } else {
                    $style .= 'background-image:' . $gradient . ';background-size:cover;background-position:center;';
                    $attr['data-mediaflow-progressive'] = 'gradient';
                }
            } else {
                $style .= 'background-image:' . $gradient . ';background-size:cover;background-position:center;';
                $attr['data-mediaflow-progressive'] = 'gradient-budget';
            }
            $attr['style'] = $style;
            return;
        }

        if ( 'gradient' === $mode ) {
            $style .= 'background-image:' . $this->gradient_css( $attachment_id, $manifest ) . ';background-size:cover;background-position:center;';
            $attr['data-mediaflow-progressive'] = 'gradient';
            $attr['style'] = $style;
            return;
        }

        $type        = (string) ( $manifest['placeholder_type'] ?? 'none' );
        $placeholder = (string) ( $manifest['placeholder'] ?? '' );
        if ( 'lqip' === $mode && 'lqip' === $type && '' !== $placeholder && $this->can_inline_lqip( $placeholder ) ) {
            $style .= "background-image:url('" . $placeholder . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
            $attr['data-mediaflow-progressive'] = 'lqip-inline';
        } elseif ( 'color' === $mode || 'lqip' === $mode ) {
            $color = 'color' === $type && '' !== $placeholder ? $placeholder : $this->settings->placeholder_color();
            $style .= 'background-color:' . $color . ';';
            $attr['data-mediaflow-progressive'] = 'color';
        } else {
            return;
        }
        $attr['style'] = $style;
    }

    private function apply_progressive_processor( \WP_HTML_Tag_Processor $processor, array $manifest, int $attachment_id ): void {
        if ( ! $this->settings->progressive() ) { return; }
        $mode     = $this->settings->placeholder_mode();
        $existing = (string) $processor->get_attribute( 'style' );
        $style    = $existing ? rtrim( $existing, '; ' ) . ';' : '';

        if ( 'auto' === $mode ) {
            $gradient = $this->gradient_css( $attachment_id, $manifest );
            if ( $this->can_use_auto_lqip() ) {
                $url = $this->manifests->lqip_url( $attachment_id, $manifest );
                if ( '' !== $url ) {
                    $style .= "background-image:url('" . esc_url_raw( $url ) . "')," . $gradient . ';background-size:cover,cover;background-position:center,center;background-repeat:no-repeat,no-repeat;';
                    $processor->set_attribute( 'data-mediaflow-progressive', 'auto-lqip' );
                    $this->manifests->queue_lqip( $attachment_id, $manifest );
                } else {
                    $style .= 'background-image:' . $gradient . ';background-size:cover;background-position:center;';
                    $processor->set_attribute( 'data-mediaflow-progressive', 'gradient' );
                }
            } else {
                $style .= 'background-image:' . $gradient . ';background-size:cover;background-position:center;';
                $processor->set_attribute( 'data-mediaflow-progressive', 'gradient-budget' );
            }
            $processor->set_attribute( 'style', $style );
            return;
        }

        if ( 'gradient' === $mode ) {
            $style .= 'background-image:' . $this->gradient_css( $attachment_id, $manifest ) . ';background-size:cover;background-position:center;';
            $processor->set_attribute( 'data-mediaflow-progressive', 'gradient' );
            $processor->set_attribute( 'style', $style );
            return;
        }

        $type        = (string) ( $manifest['placeholder_type'] ?? 'none' );
        $placeholder = (string) ( $manifest['placeholder'] ?? '' );
        if ( 'lqip' === $mode && 'lqip' === $type && '' !== $placeholder && $this->can_inline_lqip( $placeholder ) ) {
            $style .= "background-image:url('" . $placeholder . "');background-size:cover;background-position:center;background-repeat:no-repeat;";
            $processor->set_attribute( 'data-mediaflow-progressive', 'lqip-inline' );
        } elseif ( 'color' === $mode || 'lqip' === $mode ) {
            $color = 'color' === $type && '' !== $placeholder ? $placeholder : $this->settings->placeholder_color();
            $style .= 'background-color:' . $color . ';';
            $processor->set_attribute( 'data-mediaflow-progressive', 'color' );
        } else {
            return;
        }
        $processor->set_attribute( 'style', $style );
    }

    private function can_inline_lqip( string $placeholder ): bool {
        $max_count = $this->settings->lqip_max_per_request();
        $max_bytes = $this->settings->lqip_max_total_bytes();
        $bytes     = strlen( $placeholder );
        if ( 0 === $max_count || self::$inline_lqip_count >= $max_count ) { return false; }
        if ( $bytes < 1 || self::$inline_lqip_bytes + $bytes > $max_bytes ) { return false; }
        ++self::$inline_lqip_count;
        self::$inline_lqip_bytes += $bytes;
        return true;
    }

    private function can_use_auto_lqip(): bool {
        $max_count = $this->settings->lqip_max_per_request();
        if ( 0 === $max_count || self::$auto_lqip_count >= $max_count ) { return false; }
        ++self::$auto_lqip_count;
        return true;
    }

    private function gradient_css( int $attachment_id, array $manifest ): string {
        $palettes = array(
            array( '#dbe7dd', '#9eb8a5', '#5f7d69' ),
            array( '#eee2d2', '#cab59c', '#8d735f' ),
            array( '#dde8ef', '#9fb7c9', '#657f96' ),
            array( '#eadfe7', '#c2a9bc', '#866c82' ),
            array( '#e9e5d6', '#b8b296', '#77765f' ),
            array( '#e0e8e6', '#a4b7b2', '#657c78' ),
            array( '#f0e0d6', '#c6a38f', '#8d6b5b' ),
            array( '#e2e2ec', '#aaaac1', '#707089' ),
        );
        $angles = array( 115, 125, 135, 145, 155, 165 );
        $seed = hash( 'sha256', $attachment_id . '|' . (string) ( $manifest['revision'] ?? '' ) );
        $palette = $palettes[ hexdec( substr( $seed, 0, 2 ) ) % count( $palettes ) ];
        $angle   = $angles[ hexdec( substr( $seed, 2, 2 ) ) % count( $angles ) ];
        return sprintf( 'linear-gradient(%ddeg,%s 0%%,%s 52%%,%s 100%%)', $angle, $palette[0], $palette[1], $palette[2] );
    }

    private function bounded_sources( array $sources, int $display ): array {
        $max = $this->settings->max_srcset_candidates();
        while ( count( $sources ) > $max ) {
            $keys = array_keys( $sources );
            $highest = max( $keys );
            $removable = array_values( array_filter( $keys, static fn( $w ) => $w !== $display && $w !== $highest ) );
            if ( ! $removable ) { break; }
            usort( $removable, static fn( $a, $b ) => abs( $b - $display ) <=> abs( $a - $display ) );
            unset( $sources[ $removable[0] ] );
        }
        return $sources;
    }

    private function srcset_string( array $sources ): string {
        $parts = array();
        foreach ( $sources as $width => $url ) {
            $parts[] = esc_url( $url ) . ' ' . absint( $width ) . 'w';
        }
        return implode( ', ', $parts );
    }
}
