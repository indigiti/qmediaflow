<?php
namespace MediaFlow;

/** Explicit diagnostics for routing, storage, encoders, workers and distribution. */
final class Health {
    private Paths $paths;
    private Settings $settings;
    private Derivative_Queue $queue;
    private Distribution $distribution;

    public function __construct( Paths $paths, Settings $settings, Derivative_Queue $queue, Distribution $distribution ) {
        $this->paths = $paths;
        $this->settings = $settings;
        $this->queue = $queue;
        $this->distribution = $distribution;
    }

    public function run( bool $deep = false ): array {
        $cache = $this->paths->cache_dir();
        $private = $this->paths->private_dir();
        $cache_root = $this->paths->site_id() ? dirname( dirname( $cache ) ) : $cache;
        $htaccess = rtrim( $cache_root, '/\\' ) . '/.htaccess';
        $rules = is_readable( $htaccess ) ? (string) @file_get_contents( $htaccess ) : '';
        $gateway = QMEDIAFLOW_DIR . 'qmediaflow-gateway.php';
        $config = rtrim( $cache_root, '/\\' ) . '/__qmediaflow-gateway-config.php';

        $webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
        $avif = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
        $encoder = $deep ? $this->encoder_benchmark() : array( 'ran' => false, 'message' => 'Use a deep health check to benchmark encoders.' );
        $distribution = $deep ? $this->distribution->health() : array(
            'cdn_base_url' => Runtime_Config::cdn_base_url(),
            'cdn_enabled'  => '' !== Runtime_Config::cdn_base_url(),
            'object_store' => array( 'configured' => Runtime_Config::object_store_enabled(), 'ok' => null, 'message' => 'Deep check not run.' ),
        );

        return array(
            'version' => QMEDIAFLOW_VERSION,
            'site_id' => $this->paths->site_id(),
            'storage' => array(
                'cache_dir'        => $cache,
                'cache_writable'   => is_dir( $cache ) && is_writable( $cache ),
                'private_dir'      => $private,
                'private_writable' => is_dir( $private ) && is_writable( $private ),
                'signing_key'      => '' !== $this->settings->signing_key(),
            ),
            'routing' => array(
                'gateway_file'      => is_readable( $gateway ),
                'gateway_config'    => is_readable( $config ),
                'legacy_cold_route' => str_contains( $rules, 'w[0-9]+-h[0-9]+-c[01]-q[0-9]+-s' ) && str_contains( $rules, 'qmediaflow-gateway.php' ),
                'focal_cold_route'  => str_contains( $rules, '-fx[0-9]{1,3}-fy[0-9]{1,3}-s' ),
                'immutable_headers' => str_contains( $rules, 'max-age=31536000, immutable' ),
                'pending_no_store'  => str_contains( $rules, 'no-store, max-age=0' ),
                'nginx_note'        => 'Nginx must mirror the documented /image-cache static-hit and gateway fallback rules.',
            ),
            'encoders' => array( 'webp' => $webp, 'avif' => $avif, 'benchmark' => $encoder ),
            'workers' => array(
                'hard_generator_ceiling' => (int) QMEDIAFLOW_MAX_GENERATORS,
                'effective_generators'   => Runtime_Config::generator_limit(),
                'upload_workers'         => Runtime_Config::upload_workers(),
                'wp_cron_disabled'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                'action_scheduler'       => function_exists( 'as_enqueue_async_action' ),
                'queue'                  => $this->queue->status( 5000 ),
            ),
            'features' => array(
                'critical_warming'      => Runtime_Config::warm_enabled(),
                'content_aware_encoding'=> Runtime_Config::content_aware_enabled(),
                'dual_format'           => Runtime_Config::dual_format_enabled(),
                'telemetry'             => Runtime_Config::telemetry_enabled(),
                'cdn'                   => '' !== Runtime_Config::cdn_base_url(),
                'object_store'          => Runtime_Config::object_store_enabled(),
            ),
            'distribution' => $distribution,
        );
    }

    private function encoder_benchmark(): array {
        $source = wp_tempnam( 'qmediaflow-health-source' );
        if ( ! $source ) { return array( 'ran' => true, 'ok' => false, 'message' => 'Could not allocate temp storage.' ); }
        $image = function_exists( 'imagecreatetruecolor' ) ? imagecreatetruecolor( 640, 360 ) : false;
        if ( ! $image || ! function_exists( 'imagejpeg' ) ) { @unlink( $source ); return array( 'ran' => true, 'ok' => false, 'message' => 'GD JPEG fixture generation is unavailable.' ); }
        imagejpeg( $image, $source, 85 );
        imagedestroy( $image );

        $results = array();
        foreach ( array( 'image/webp' => 'webp', 'image/avif' => 'avif' ) as $mime => $label ) {
            if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) { $results[ $label ] = array( 'supported' => false ); continue; }
            $editor = wp_get_image_editor( $source );
            if ( is_wp_error( $editor ) ) { $results[ $label ] = array( 'supported' => true, 'ok' => false, 'error' => $editor->get_error_message() ); continue; }
            $target = wp_tempnam( 'qmediaflow-health-' . $label );
            $started = microtime( true );
            $save = $target ? $editor->save( $target, $mime ) : new \WP_Error( 'temp', 'Temp file unavailable.' );
            $ms = ( microtime( true ) - $started ) * 1000;
            $results[ $label ] = array(
                'supported' => true,
                'ok'        => ! is_wp_error( $save ),
                'ms'        => round( $ms, 2 ),
                'bytes'     => $target && is_file( $target ) ? (int) @filesize( $target ) : 0,
                'error'     => is_wp_error( $save ) ? $save->get_error_message() : '',
            );
            if ( $target ) { @unlink( $target ); }
        }
        @unlink( $source );
        return array( 'ran' => true, 'ok' => true, 'formats' => $results );
    }
}
