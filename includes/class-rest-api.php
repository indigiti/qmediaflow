<?php
namespace MediaFlow;

/** Stable headless API exposing only bounded, signed QMediaFlow image metadata. */
final class REST_API {
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Distribution $distribution;

    public function __construct( Manifest_Store $manifests, Resolver $resolver, Distribution $distribution ) {
        $this->manifests = $manifests;
        $this->resolver = $resolver;
        $this->distribution = $distribution;
    }

    public function hooks(): void { add_action( 'rest_api_init', array( $this, 'register_routes' ) ); }

    public function register_routes(): void {
        register_rest_route( 'qmediaflow/v1', '/image/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array( $this, 'image' ),
            'args'                => array(
                'id'     => array( 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $v ): bool => absint( $v ) > 0 ),
                'width'  => array( 'sanitize_callback' => 'absint', 'default' => 1024 ),
                'height' => array( 'sanitize_callback' => 'absint', 'default' => 0 ),
                'crop'   => array( 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ),
                'format' => array( 'sanitize_callback' => 'sanitize_key', 'default' => 'auto' ),
            ),
        ) );
    }

    public function image( \WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        $manifest = $this->manifests->ensure_for_attachment( $id );
        if ( ! $manifest ) { return new \WP_Error( 'qmediaflow_not_found', 'Image attachment is unavailable.', array( 'status' => 404 ) ); }

        $width = max( 1, min( 8192, absint( $request['width'] ) ) );
        $height = max( 0, min( 8192, absint( $request['height'] ) ) );
        $crop = (bool) $request['crop'];
        $format = sanitize_key( (string) $request['format'] );
        if ( ! in_array( $format, array( 'auto', 'avif', 'webp', 'jpeg', 'png', 'original' ), true ) ) { $format = 'auto'; }

        $args = array( 'width' => $width, 'height' => $height, 'crop' => $crop );
        if ( ! in_array( $format, array( 'auto', 'original' ), true ) ) { $args['format'] = $format; }
        elseif ( 'original' === $format ) { $args['format'] = Variant::format_from_mime( (string) $manifest['mime'] ); }

        $settings = Plugin::instance()->settings();
        $base_variant = $this->resolver->variant_for( $id, $manifest, array_merge( $args, array( 'quality' => $settings->quality() ) ) );
        if ( ! $base_variant ) { return new \WP_Error( 'qmediaflow_transform', 'Requested transform is unavailable.', array( 'status' => 400 ) ); }

        [ $focal_x, $focal_y ] = Focal_Point::get( $id );
        $main_spec = array(
            'width'   => $base_variant->width,
            'height'  => $base_variant->height,
            'crop'    => $base_variant->crop,
            'format'  => $base_variant->format,
            'quality' => Encoding_Policy::quality( $manifest, $base_variant->width, $base_variant->format, $settings->quality() ),
        );
        $variant = $crop && ( 50 !== $focal_x || 50 !== $focal_y )
            ? Focal_Resolver::variant( $id, $manifest, $main_spec, $focal_x, $focal_y )
            : $this->resolver->variant_for( $id, $manifest, $main_spec );
        if ( ! $variant ) { return new \WP_Error( 'qmediaflow_transform', 'Requested transform is unavailable.', array( 'status' => 400 ) ); }

        [ $out_w, $out_h ] = $this->resolver->output_dimensions( $manifest, $variant );
        $aspect = $crop && $out_h > 0 ? $out_w / $out_h : null;
        $base_sources = $this->resolver->responsive_sources( $id, $out_w, $aspect, $crop );
        $widths = array_keys( $base_sources );
        $widths[] = $out_w;
        $widths = array_values( array_unique( array_map( 'absint', $widths ) ) );
        sort( $widths, SORT_NUMERIC );

        $candidates = array();
        foreach ( $widths as $candidate_width ) {
            $candidate_height = $crop && $aspect ? max( 1, (int) round( $candidate_width / $aspect ) ) : 0;
            $spec = array(
                'width'   => $candidate_width,
                'height'  => $candidate_height,
                'crop'    => $crop,
                'format'  => $variant->format,
                'quality' => Encoding_Policy::quality( $manifest, $candidate_width, $variant->format, $settings->quality() ),
            );
            $candidate = $crop && ( 50 !== $focal_x || 50 !== $focal_y )
                ? Focal_Resolver::variant( $id, $manifest, $spec, $focal_x, $focal_y )
                : $this->resolver->variant_for( $id, $manifest, $spec );
            if ( ! $candidate ) { continue; }
            [ $actual_width ] = $this->resolver->output_dimensions( $manifest, $candidate );
            $candidates[ $actual_width ] = array(
                'width' => $actual_width,
                'url'   => $this->distribution->public_url( $this->resolver->url( $id, $manifest, $candidate ) ),
            );
        }
        ksort( $candidates, SORT_NUMERIC );
        $candidates = array_values( $candidates );

        $lqip_url = 'auto' === (string) ( $manifest['placeholder_type'] ?? '' ) ? $this->manifests->lqip_url( $id, $manifest ) : '';
        if ( $lqip_url ) { $lqip_url = $this->distribution->public_url( $lqip_url ); }
        $data = array(
            'id'            => $id,
            'revision'      => (string) $manifest['revision'],
            'mime'          => (string) $manifest['mime'],
            'source_width'  => (int) $manifest['width'],
            'source_height' => (int) $manifest['height'],
            'aspect_ratio'  => round( (int) $manifest['width'] / max( 1, (int) $manifest['height'] ), 6 ),
            'width'         => $out_w,
            'height'        => $out_h,
            'url'           => $this->distribution->public_url( $this->resolver->url( $id, $manifest, $variant ) ),
            'candidates'    => $candidates,
            'focal'         => array( 'x' => $focal_x, 'y' => $focal_y ),
            'placeholder'   => array(
                'mode'  => (string) ( $manifest['placeholder_type'] ?? 'none' ),
                'state' => (string) ( $manifest['lqip_state'] ?? 'disabled' ),
                'url'   => $lqip_url,
                'color' => 'color' === (string) ( $manifest['placeholder_type'] ?? '' ) ? (string) ( $manifest['placeholder'] ?? '' ) : '',
            ),
        );
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=300' );
        return $response;
    }
}
