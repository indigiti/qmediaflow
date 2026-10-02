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
        $url = false;
        if ( ! empty( $spec['crop'] ) && class_exists( Focal_Point::class ) && class_exists( Focal_Resolver::class ) ) {
            [ $fx, $fy ] = Focal_Point::get( $attachment_id );
            if ( 50 !== $fx || 50 !== $fy ) { $url = Focal_Resolver::url( $attachment_id, $manifest, $spec, $fx, $fy ); }
        }
        if ( ! $url ) { $url = $this->resolver->custom_url( $attachment_id, $spec ); }
        if ( $url ) { $downsize[0] = $this->distribution->public_url( (string) $url ); }
        else { $downsize[0] = $this->distribution->public_url( (string) $downsize[0] ); }
        return $downsize;
    }

    public function filter_attributes( array $attr, \WP_Post $attachment, $size ): array {
        if ( ! empty( $attr['src'] ) ) { $attr['src'] = $this->distribution->public_url( (string) $attr['src'] ); }
        if ( ! empty( $attr['srcset'] ) ) { $attr['srcset'] = $this->distribution->rewrite_srcset( (string) $attr['srcset'] ); }
        return $attr;
    }

    public function filter_content_tag( string $html, string $context, int $attachment_id ): string {
        if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return $html; }
        $processor = new \WP_HTML_Tag_Processor( $html );
        if ( ! $processor->next_tag( 'img' ) ) { return $html; }
        $src = (string) $processor->get_attribute( 'src' );
        $srcset = (string) $processor->get_attribute( 'srcset' );
        if ( '' !== $src ) { $processor->set_attribute( 'src', $this->distribution->public_url( $src ) ); }
        if ( '' !== $srcset ) { $processor->set_attribute( 'srcset', $this->distribution->rewrite_srcset( $srcset ) ); }
        return $processor->get_updated_html();
    }
}
