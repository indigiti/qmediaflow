<?php
namespace MediaFlow;

/** Applies CDN rewriting, deterministic smart quality and focal crop overrides. */
final class Smart_Delivery {
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Settings $settings;
    private Distribution $distribution;
    private bool $smart_quality;
    private ?array $registered_sizes = null;

    public function __construct( Manifest_Store $manifests, Resolver $resolver, Settings $settings, Distribution $distribution ) {
        $this->manifests = $manifests;
        $this->resolver = $resolver;
        $this->settings = $settings;
        $this->distribution = $distribution;
        $this->smart_quality = Runtime_Config::content_aware_enabled();
    }

    public function hooks(): void {
        add_filter( 'image_downsize', array( $this, 'filter_downsize' ), 7, 3 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attributes' ), 90, 3 );
        add_filter( 'wp_content_img_tag', array( $this, 'filter_content_tag' ), 90, 3 );
    }

    public function filter_downsize( $downsize, int $attachment_id, $size ) {
        if ( ! is_array( $downsize ) || empty( $downsize[0] ) ) { return $downsize; }
        $cdn = $this->distribution->rewrites_urls();
        if ( ! $this->smart_quality && ! $this->size_may_crop( $size ) ) {
            if ( $cdn ) { $downsize[0] = $this->distribution->public_url( (string) $downsize[0] ); }
            return $downsize;
        }

        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            if ( $cdn ) { $downsize[0] = $this->distribution->public_url( (string) $downsize[0] ); }
            return $downsize;
        }
        $spec = $this->resolver->spec_for_size( $size, $manifest );
        if ( ! $spec ) {
            if ( $cdn ) { $downsize[0] = $this->distribution->public_url( (string) $downsize[0] ); }
            return $downsize;
        }

        $focal_x = 50;
        $focal_y = 50;
        $needs_focal = false;
        if ( ! empty( $spec['crop'] ) ) {
            [ $focal_x, $focal_y ] = Focal_Point::runtime( $attachment_id );
            $needs_focal = 50 !== $focal_x || 50 !== $focal_y;
        }
        if ( ! $this->smart_quality && ! $needs_focal ) {
            if ( $cdn ) { $downsize[0] = $this->distribution->public_url( (string) $downsize[0] ); }
            return $downsize;
        }

        if ( $this->smart_quality ) {
            $spec['quality'] = Encoding_Policy::quality( $manifest, absint( $spec['width'] ?? $downsize[1] ?? 0 ), (string) ( $spec['format'] ?? 'webp' ), absint( $spec['quality'] ?? $this->settings->quality() ) );
        }
        $url = $needs_focal
            ? Focal_Resolver::url( $attachment_id, $manifest, $spec, $focal_x, $focal_y )
            : $this->resolver->custom_url( $attachment_id, $spec );
        $downsize[0] = $url ? $this->distribution->public_url( (string) $url ) : $this->distribution->public_url( (string) $downsize[0] );
        return $downsize;
    }

    public function filter_attributes( array $attr, \WP_Post $attachment, $size ): array {
        $id = (int) $attachment->ID;
        $cdn = $this->distribution->rewrites_urls();
        $may_crop = $this->size_may_crop( $size );
        if ( ! $this->smart_quality && ! $may_crop ) {
            if ( $cdn ) {
                if ( ! empty( $attr['src'] ) ) { $attr['src'] = $this->distribution->public_url( (string) $attr['src'] ); }
                if ( ! empty( $attr['srcset'] ) ) { $attr['srcset'] = $this->distribution->rewrite_srcset( (string) $attr['srcset'] ); }
            }
            return $attr;
        }
        if ( ! empty( $attr['src'] ) ) { $attr['src'] = $this->distribution->public_url( (string) $attr['src'] ); }
        if ( ! empty( $attr['srcset'] ) ) { $attr['srcset'] = $this->smart_srcset( $id, (string) $attr['srcset'] ); }
        return $attr;
    }

    public function filter_content_tag( string $html, string $context, int $attachment_id ): string {
        $cdn = $this->distribution->rewrites_urls();
        if ( ! $cdn && ! $this->smart_quality && ! str_contains( $html, '-c1-' ) ) { return $html; }
        if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return $html; }
        $processor = new \WP_HTML_Tag_Processor( $html );
        if ( ! $processor->next_tag( 'img' ) ) { return $html; }
        $src = (string) $processor->get_attribute( 'src' );
        $srcset = (string) $processor->get_attribute( 'srcset' );
        if ( '' !== $src && $cdn ) { $processor->set_attribute( 'src', $this->distribution->public_url( $src ) ); }
        if ( '' !== $srcset ) {
            if ( $this->smart_quality || str_contains( $srcset, '-c1-' ) ) { $processor->set_attribute( 'srcset', $this->smart_srcset( $attachment_id, $srcset ) ); }
            elseif ( $cdn ) { $processor->set_attribute( 'srcset', $this->distribution->rewrite_srcset( $srcset ) ); }
        }
        return $processor->get_updated_html();
    }

    private function smart_srcset( int $attachment_id, string $srcset ): string {
        if ( $attachment_id < 1 ) { return $this->distribution->rewrite_srcset( $srcset ); }
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) { return $this->distribution->rewrite_srcset( $srcset ); }

        $focal = null;
        $parts = preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            if ( ! preg_match( '/^(\S+)(\s+.+)?$/', trim( $part ), $match ) ) { continue; }
            $url = (string) $match[1];
            $descriptor = (string) ( $match[2] ?? '' );
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            $filename = basename( $path );
            if ( preg_match( '/^w(?<w>\d+)-h(?<h>\d+)-c(?<c>[01])-q(?<q>\d+)(?:-fx(?<fx>\d{1,3})-fy(?<fy>\d{1,3}))?-s[a-f0-9]{32}\.(?<ext>avif|webp|jpe?g|png)$/i', $filename, $token ) ) {
                $format = match ( strtolower( (string) $token['ext'] ) ) { 'avif' => 'avif', 'webp' => 'webp', 'png' => 'png', default => 'jpeg' };
                $width = max( 1, absint( $token['w'] ) );
                $crop = '1' === (string) $token['c'];
                $base_quality = max( 1, min( 100, absint( $token['q'] ) ) );
                $quality = $this->smart_quality ? Encoding_Policy::quality( $manifest, $width, $format, $this->settings->quality() ) : $base_quality;
                $needs_focal = false;
                if ( $crop ) {
                    if ( null === $focal ) { $focal = Focal_Point::runtime( $attachment_id ); }
                    $needs_focal = 50 !== $focal[0] || 50 !== $focal[1];
                }
                if ( $this->smart_quality || $needs_focal ) {
                    $spec = array(
                        'width'   => $width,
                        'height'  => max( 0, absint( $token['h'] ) ),
                        'crop'    => $crop,
                        'format'  => $format,
                        'quality' => $quality,
                    );
                    $smart = $needs_focal
                        ? Focal_Resolver::url( $attachment_id, $manifest, $spec, $focal[0], $focal[1] )
                        : $this->resolver->custom_url( $attachment_id, $spec );
                    if ( $smart ) { $url = (string) $smart; }
                }
            }
            $out[] = $this->distribution->public_url( $url ) . $descriptor;
        }
        return $out ? implode( ', ', $out ) : $this->distribution->rewrite_srcset( $srcset );
    }

    private function size_may_crop( $size ): bool {
        if ( ! is_string( $size ) || 'full' === $size ) { return false; }
        if ( null === $this->registered_sizes ) { $this->registered_sizes = wp_get_registered_image_subsizes(); }
        if ( empty( $this->registered_sizes[ $size ] ) ) { return false; }
        $crop = $this->registered_sizes[ $size ]['crop'] ?? false;
        return ! empty( $crop );
    }
}
