<?php
namespace MediaFlow;

/** Optional bounded AVIF/WebP <picture> output without width × format explosion. */
final class Picture {
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Settings $settings;
    private Distribution $distribution;
    private bool $wrapping = false;
    private static array $format_support = array();

    public function __construct( Manifest_Store $manifests, Resolver $resolver, Settings $settings, Distribution $distribution ) {
        $this->manifests = $manifests;
        $this->resolver = $resolver;
        $this->settings = $settings;
        $this->distribution = $distribution;
    }

    public function hooks(): void {
        if ( Runtime_Config::dual_format_enabled() ) {
            add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_html' ), 90, 5 );
        }
    }

    public function filter_attachment_html( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
        if ( $this->wrapping || $icon || str_contains( $html, '<picture' ) ) { return $html; }
        return $this->wrap( $html, $attachment_id, $size );
    }

    public function render( int $attachment_id, $size = 'large', array $attr = array() ): string {
        $this->wrapping = true;
        try { $html = (string) wp_get_attachment_image( $attachment_id, $size, false, $attr ); }
        finally { $this->wrapping = false; }
        return '' === $html ? '' : $this->wrap( $html, $attachment_id, $size );
    }

    public function wrap( string $img_html, int $attachment_id, $size ): string {
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) { return $img_html; }
        $spec = $this->resolver->spec_for_size( $size, $manifest );
        if ( ! $spec ) { return $img_html; }
        $variant = $this->resolver->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $variant ) { return $img_html; }
        [ $display_w, $display_h ] = $this->resolver->output_dimensions( $manifest, $variant );
        $aspect = $variant->crop && $display_h > 0 ? $display_w / $display_h : null;
        $base_sources = $this->resolver->responsive_sources( $attachment_id, $display_w, $aspect, $variant->crop );
        $widths = array_keys( $base_sources );
        $widths[] = $display_w;
        $widths = array_values( array_unique( array_map( 'absint', $widths ) ) );
        sort( $widths, SORT_NUMERIC );
        $per_format = max( 1, (int) floor( Runtime_Config::max_picture_candidates() / 2 ) );
        $widths = $this->bounded_widths( $widths, $display_w, $per_format );

        $focal_x = 50;
        $focal_y = 50;
        if ( $variant->crop ) { [ $focal_x, $focal_y ] = Focal_Point::get( $attachment_id ); }
        $needs_focal = $variant->crop && ( 50 !== $focal_x || 50 !== $focal_y );

        $sources = array();
        foreach ( array( 'avif' => 'image/avif', 'webp' => 'image/webp' ) as $format => $mime ) {
            if ( ! self::supports_format( $format, $mime ) ) { continue; }
            $parts = array();
            foreach ( $widths as $width ) {
                $height = 0;
                if ( $variant->crop && $aspect ) { $height = max( 1, (int) round( $width / $aspect ) ); }
                $quality = Encoding_Policy::quality( $manifest, $width, $format, $this->settings->quality() );
                $candidate_spec = array( 'width' => $width, 'height' => $height, 'crop' => $variant->crop, 'format' => $format, 'quality' => $quality );
                $url = $needs_focal
                    ? Focal_Resolver::url( $attachment_id, $manifest, $candidate_spec, $focal_x, $focal_y )
                    : $this->resolver->custom_url( $attachment_id, $candidate_spec );
                if ( $url ) { $parts[] = esc_url( $this->distribution->public_url( (string) $url ) ) . ' ' . $width . 'w'; }
            }
            if ( $parts ) { $sources[] = '<source type="' . esc_attr( $mime ) . '" srcset="' . esc_attr( implode( ', ', $parts ) ) . '">'; }
        }
        if ( ! $sources ) { return $img_html; }
        return '<picture data-qmediaflow-picture="1">' . implode( '', $sources ) . $img_html . '</picture>';
    }

    private static function supports_format( string $format, string $mime ): bool {
        if ( array_key_exists( $format, self::$format_support ) ) { return self::$format_support[ $format ]; }
        return self::$format_support[ $format ] = wp_image_editor_supports( array( 'mime_type' => $mime ) );
    }

    /** @param int[] $widths @return int[] */
    private function bounded_widths( array $widths, int $display, int $limit ): array {
        if ( count( $widths ) <= $limit ) { return $widths; }
        $highest = max( $widths );
        usort( $widths, static fn( int $a, int $b ): int => abs( $a - $display ) <=> abs( $b - $display ) );
        $selected = array_slice( $widths, 0, max( 1, $limit - 1 ) );
        $selected[] = $highest;
        $selected = array_values( array_unique( $selected ) );
        sort( $selected, SORT_NUMERIC );
        return $selected;
    }
}
