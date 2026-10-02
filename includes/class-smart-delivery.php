<?php
namespace MediaFlow;

/** Applies CDN rewriting, deterministic smart quality and focal crop overrides. */
final class Smart_Delivery {
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Settings $settings;
    private Distribution $distribution;

    public function __construct( Manifest_Store $manifests, Resolver $resolver, Settings $settings, Distribution $distribution ) {
        $this->manifests = $manifests;
        $this->resolver = $resolver;
        $this->settings = $settings;
        $this->distribution = $distribution;
    }

    public function hooks(): void {
        add_filter( 'image_downsize', array( $this, 'filter_downsize' ), 7, 3 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attributes' ), 90, 3 );
        add_filter( 'wp_content_img_tag', array( $this, 'filter_content_tag' ), 90, 3 );
    }

    public function filter_downsize( $downsize, int $attachment_id, $size ) {
        if ( ! is_array( $downsize ) || empty( $downsize[0] ) ) { return $downsize; }
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) {
            $downsize[0] = $this->distribution->public_url( (string) $downsize[0] );
            return $downsize;
        }
        $spec = $this->resolver->spec_for_size( $size, $manifest );
        if ( ! $spec ) {
            $downsize[0] = $this->distribution->public_url( (string) $downsize[0] );
            return $downsize;
        }

        $spec['quality'] = Encoding_Policy::quality( $manifest, absint( $spec['width'] ?? $downsize[1] ?? 0 ), (string) ( $spec['format'] ?? 'webp' ), absint( $spec['quality'] ?? $this->settings->quality() ) );
        $url = $this->url_for_spec( $attachment_id, $manifest, $spec );
        $downsize[0] = $url ? $this->distribution->public_url( (string) $url ) : $this->distribution->public_url( (string) $downsize[0] );
        return $downsize;
    }

    public function filter_attributes( array $attr, \WP_Post $attachment, $size ): array {
        $id = (int) $attachment->ID;
        if ( ! empty( $attr['src'] ) ) { $attr['src'] = $this->distribution->public_url( (string) $attr['src'] ); }
        if ( ! empty( $attr['srcset'] ) ) { $attr['srcset'] = $this->smart_srcset( $id, (string) $attr['srcset'] ); }
        return $attr;
    }

    public function filter_content_tag( string $html, string $context, int $attachment_id ): string {
        if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return $html; }
        $processor = new \WP_HTML_Tag_Processor( $html );
        if ( ! $processor->next_tag( 'img' ) ) { return $html; }
        $src = (string) $processor->get_attribute( 'src' );
        $srcset = (string) $processor->get_attribute( 'srcset' );
        if ( '' !== $src ) { $processor->set_attribute( 'src', $this->distribution->public_url( $src ) ); }
        if ( '' !== $srcset ) { $processor->set_attribute( 'srcset', $this->smart_srcset( $attachment_id, $srcset ) ); }
        return $processor->get_updated_html();
    }

    private function smart_srcset( int $attachment_id, string $srcset ): string {
        if ( $attachment_id < 1 || ! Runtime_Config::content_aware_enabled() ) {
            return $this->distribution->rewrite_srcset( $srcset );
        }
        $manifest = $this->manifests->ensure_for_attachment( $attachment_id );
        if ( ! $manifest ) { return $this->distribution->rewrite_srcset( $srcset ); }

        $parts = preg_split( '/\s*,\s*/', trim( $srcset ) ) ?: array();
        $out = array();
        foreach ( $parts as $part ) {
            if ( ! preg_match( '/^(\S+)(\s+.+)?$/', trim( $part ), $match ) ) { continue; }
            $url = (string) $match[1];
            $descriptor = (string) ( $match[2] ?? '' );
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            $filename = basename( $path );
            if ( preg_match( '/^w(?<w>\d+)-h(?<h>\d+)-c(?<c>[01])-q\d+(?:-fx(?<fx>\d{1,3})-fy(?<fy>\d{1,3}))?-s[a-f0-9]{32}\.(?<ext>avif|webp|jpe?g|png)$/i', $filename, $token ) ) {
                $format = match ( strtolower( (string) $token['ext'] ) ) { 'avif' => 'avif', 'webp' => 'webp', 'png' => 'png', default => 'jpeg' };
                $width = max( 1, absint( $token['w'] ) );
                $spec = array(
                    'width'   => $width,
                    'height'  => max( 0, absint( $token['h'] ) ),
                    'crop'    => '1' === (string) $token['c'],
                    'format'  => $format,
                    'quality' => Encoding_Policy::quality( $manifest, $width, $format, $this->settings->quality() ),
                );
                $smart = $this->url_for_spec( $attachment_id, $manifest, $spec );
                if ( $smart ) { $url = (string) $smart; }
            }
            $out[] = $this->distribution->public_url( $url ) . $descriptor;
        }
        return $out ? implode( ', ', $out ) : $this->distribution->rewrite_srcset( $srcset );
    }

    private function url_for_spec( int $attachment_id, array $manifest, array $spec ) {
        if ( ! empty( $spec['crop'] ) && class_exists( Focal_Point::class ) && class_exists( Focal_Resolver::class ) ) {
            [ $fx, $fy ] = Focal_Point::get( $attachment_id );
            if ( 50 !== $fx || 50 !== $fy ) { return Focal_Resolver::url( $attachment_id, $manifest, $spec, $fx, $fy ); }
        }
        return $this->resolver->custom_url( $attachment_id, $spec );
    }
}
