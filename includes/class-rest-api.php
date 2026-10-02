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

        $variant = $this->resolver->variant_for( $id, $manifest, array_merge( $args, array( 'quality' => Plugin::instance()->settings()->quality() ) ) );
        if ( ! $variant ) { return new \WP_Error( 'qmediaflow_transform', 'Requested transform is unavailable.', array( 'status' => 400 ) ); }
        [ $out_w, $out_h ] = $this->resolver->output_dimensions( $manifest, $variant );
        $aspect = $crop && $out_h > 0 ? $out_w / $out_h : null;
        $responsive = $this->resolver->responsive_sources( $id, $out_w, $aspect, $crop );
        $responsive[ $out_w ] = $this->resolver->url( $id, $manifest, $variant );
        ksort( $responsive, SORT_NUMERIC );
        $candidates = array();
        foreach ( $responsive as $candidate_width => $url ) {
            $candidates[] = array( 'width' => (int) $candidate_width, 'url' => $this->distribution->public_url( (string) $url ) );
        }

        [ $focal_x, $focal_y ] = class_exists( Focal_Point::class ) ? Focal_Point::get( $id ) : array( 50, 50 );
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
                'url'   => 'auto' === (string) ( $manifest['placeholder_type'] ?? '' ) ? $this->manifests->lqip_url( $id, $manifest ) : '',
                'color' => 'color' === (string) ( $manifest['placeholder_type'] ?? '' ) ? (string) ( $manifest['placeholder'] ?? '' ) : '',
            ),
        );
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'public, max-age=60, stale-while-revalidate=300' );
        return $response;
    }
}
