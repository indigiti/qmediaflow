<?php
/**
 * Plugin Name: QMediaFlow
 * Description: Browser-first WordPress image optimization with adaptive on-demand delivery, direct cold generation, bounded warming, CDN/object storage and operational tooling.
 * Version:     0.3.0
 * Author:      QMediaFlow
 * License:     GPL-2.0-or-later
 * Text Domain: qmediaflow
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* Compatibility identifiers remain supported so existing sites do not break. */
define( 'QMEDIAFLOW_VERSION', '0.3.0' );
define( 'QMEDIAFLOW_ROUTING_SCHEMA_VERSION', '6' );
define( 'QMEDIAFLOW_FILE', __FILE__ );
define( 'QMEDIAFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'QMEDIAFLOW_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'MEDIAFLOW_VERSION' ) ) { define( 'MEDIAFLOW_VERSION', QMEDIAFLOW_VERSION ); }
if ( ! defined( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION' ) ) { define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', QMEDIAFLOW_ROUTING_SCHEMA_VERSION ); }
if ( ! defined( 'MEDIAFLOW_FILE' ) ) { define( 'MEDIAFLOW_FILE', QMEDIAFLOW_FILE ); }
if ( ! defined( 'MEDIAFLOW_DIR' ) ) { define( 'MEDIAFLOW_DIR', QMEDIAFLOW_DIR ); }
if ( ! defined( 'MEDIAFLOW_URL' ) ) { define( 'MEDIAFLOW_URL', QMEDIAFLOW_URL ); }

$gateway_path = (string) parse_url( QMEDIAFLOW_URL . 'qmediaflow-edge.php', PHP_URL_PATH );
if ( '' === $gateway_path ) { $gateway_path = '/wp-content/plugins/qmediaflow/qmediaflow-edge.php'; }
if ( ! defined( 'QMEDIAFLOW_FRONT_CONTROLLER_PATH' ) ) { define( 'QMEDIAFLOW_FRONT_CONTROLLER_PATH', $gateway_path ); }
if ( ! defined( 'MEDIAFLOW_FRONT_CONTROLLER_PATH' ) ) { define( 'MEDIAFLOW_FRONT_CONTROLLER_PATH', QMEDIAFLOW_FRONT_CONTROLLER_PATH ); }

if ( defined( 'QMEDIAFLOW_PRIVATE_DIR' ) && ! defined( 'MEDIAFLOW_PRIVATE_DIR' ) ) { define( 'MEDIAFLOW_PRIVATE_DIR', QMEDIAFLOW_PRIVATE_DIR ); }
elseif ( defined( 'MEDIAFLOW_PRIVATE_DIR' ) && ! defined( 'QMEDIAFLOW_PRIVATE_DIR' ) ) { define( 'QMEDIAFLOW_PRIVATE_DIR', MEDIAFLOW_PRIVATE_DIR ); }

if ( ! defined( 'QMEDIAFLOW_MAX_OUTPUT_PIXELS' ) ) { define( 'QMEDIAFLOW_MAX_OUTPUT_PIXELS', defined( 'MEDIAFLOW_MAX_OUTPUT_PIXELS' ) ? MEDIAFLOW_MAX_OUTPUT_PIXELS : 8000000 ); }
if ( ! defined( 'MEDIAFLOW_MAX_OUTPUT_PIXELS' ) ) { define( 'MEDIAFLOW_MAX_OUTPUT_PIXELS', QMEDIAFLOW_MAX_OUTPUT_PIXELS ); }
if ( ! defined( 'QMEDIAFLOW_MAX_SOURCE_PIXELS' ) ) { define( 'QMEDIAFLOW_MAX_SOURCE_PIXELS', defined( 'MEDIAFLOW_MAX_SOURCE_PIXELS' ) ? MEDIAFLOW_MAX_SOURCE_PIXELS : 24000000 ); }
if ( ! defined( 'MEDIAFLOW_MAX_SOURCE_PIXELS' ) ) { define( 'MEDIAFLOW_MAX_SOURCE_PIXELS', QMEDIAFLOW_MAX_SOURCE_PIXELS ); }
if ( ! defined( 'QMEDIAFLOW_MAX_GENERATORS' ) ) { define( 'QMEDIAFLOW_MAX_GENERATORS', defined( 'MEDIAFLOW_MAX_GENERATORS' ) ? MEDIAFLOW_MAX_GENERATORS : 2 ); }
if ( ! defined( 'MEDIAFLOW_MAX_GENERATORS' ) ) { define( 'MEDIAFLOW_MAX_GENERATORS', QMEDIAFLOW_MAX_GENERATORS ); }

require_once QMEDIAFLOW_DIR . 'includes/class-runtime-config.php';
require_once QMEDIAFLOW_DIR . 'includes/class-budget.php';
require_once QMEDIAFLOW_DIR . 'includes/class-encoding.php';
require_once QMEDIAFLOW_DIR . 'includes/class-paths.php';
require_once QMEDIAFLOW_DIR . 'includes/class-settings.php';
require_once QMEDIAFLOW_DIR . 'includes/class-upload-optimizer.php';
require_once QMEDIAFLOW_DIR . 'includes/class-manifest-store.php';
require_once QMEDIAFLOW_DIR . 'includes/class-variant.php';
require_once QMEDIAFLOW_DIR . 'includes/class-resolver.php';
require_once QMEDIAFLOW_DIR . 'includes/class-focal-point.php';
require_once QMEDIAFLOW_DIR . 'includes/class-focal-resolver.php';
require_once QMEDIAFLOW_DIR . 'includes/class-telemetry.php';
require_once QMEDIAFLOW_DIR . 'includes/class-processor.php';
require_once QMEDIAFLOW_DIR . 'includes/class-request-handler.php';
require_once QMEDIAFLOW_DIR . 'includes/class-responsive.php';
require_once QMEDIAFLOW_DIR . 'includes/class-encoding-policy.php';
require_once QMEDIAFLOW_DIR . 'includes/class-derivative-queue.php';
require_once QMEDIAFLOW_DIR . 'includes/class-warmer.php';
require_once QMEDIAFLOW_DIR . 'includes/class-s3-store.php';
require_once QMEDIAFLOW_DIR . 'includes/class-distribution.php';
require_once QMEDIAFLOW_DIR . 'includes/class-smart-delivery.php';
require_once QMEDIAFLOW_DIR . 'includes/class-picture.php';
require_once QMEDIAFLOW_DIR . 'includes/class-woocommerce-adapter.php';
require_once QMEDIAFLOW_DIR . 'includes/class-rest-api.php';
require_once QMEDIAFLOW_DIR . 'includes/class-thumbnail-migrator.php';
require_once QMEDIAFLOW_DIR . 'includes/class-health.php';
require_once QMEDIAFLOW_DIR . 'includes/class-operations-admin.php';
require_once QMEDIAFLOW_DIR . 'includes/class-ops-cli.php';
require_once QMEDIAFLOW_DIR . 'includes/class-admin.php';
require_once QMEDIAFLOW_DIR . 'includes/class-cli.php';
require_once QMEDIAFLOW_DIR . 'includes/class-plugin.php';
require_once QMEDIAFLOW_DIR . 'includes/class-branding.php';
require_once QMEDIAFLOW_DIR . 'includes/class-features.php';
require_once QMEDIAFLOW_DIR . 'includes/class-release-hardening.php';
require_once QMEDIAFLOW_DIR . 'includes/class-image-intelligence.php';
require_once QMEDIAFLOW_DIR . 'includes/class-predictive-cache.php';
require_once QMEDIAFLOW_DIR . 'includes/class-viewport-loader.php';
require_once QMEDIAFLOW_DIR . 'includes/class-production-validator.php';

register_activation_hook( __FILE__, array( 'MediaFlow\\Plugin', 'activate' ) );

add_action( 'plugins_loaded', static function (): void {
    MediaFlow\Plugin::instance()->boot();
    MediaFlow\Branding::register();
    if ( MediaFlow\Plugin::network_enabled() ) {
        MediaFlow\Features::boot();
        MediaFlow\Release_Hardening::boot();
        MediaFlow\Image_Intelligence::boot();
        MediaFlow\Predictive_Cache::boot();
        MediaFlow\Viewport_Loader::boot();
        MediaFlow\Production_Validator::boot();
    }
}, 1 );

/** Canonical public helper: return a QMediaFlow variant URL. */
if ( ! function_exists( 'qmediaflow_url' ) ) {
    function qmediaflow_url( int $attachment_id, array $args = array() ) {
        if ( ! MediaFlow\Plugin::network_enabled() ) { return false; }
        if ( ! empty( $args['crop'] ) && ( isset( $args['focal_x'] ) || isset( $args['focal_y'] ) ) ) {
            $manifest = MediaFlow\Plugin::instance()->manifests()->ensure_for_attachment( $attachment_id );
            if ( ! $manifest ) { return false; }
            $fx = isset( $args['focal_x'] ) ? max( 0, min( 100, absint( $args['focal_x'] ) ) ) : 50;
            $fy = isset( $args['focal_y'] ) ? max( 0, min( 100, absint( $args['focal_y'] ) ) ) : 50;
            return MediaFlow\Focal_Resolver::url( $attachment_id, $manifest, $args, $fx, $fy );
        }
        return MediaFlow\Plugin::instance()->resolver()->custom_url( $attachment_id, $args );
    }
}

/** Canonical public helper: render a responsive QMediaFlow image. */
if ( ! function_exists( 'qmediaflow_image' ) ) {
    function qmediaflow_image( int $attachment_id, $size = 'large', array $attr = array() ): string {
        return (string) wp_get_attachment_image( $attachment_id, $size, false, $attr );
    }
}

/** Canonical optional dual-format picture helper. */
if ( ! function_exists( 'qmediaflow_picture' ) ) {
    function qmediaflow_picture( int $attachment_id, $size = 'large', array $attr = array() ): string {
        if ( ! MediaFlow\Plugin::network_enabled() || ! MediaFlow\Runtime_Config::dual_format_enabled() ) { return qmediaflow_image( $attachment_id, $size, $attr ); }
        return MediaFlow\Features::instance()->picture()->render( $attachment_id, $size, $attr );
    }
}

/** Legacy API aliases retained for backward compatibility. */
if ( ! function_exists( 'mediaflow_url' ) ) { function mediaflow_url( int $attachment_id, array $args = array() ) { return qmediaflow_url( $attachment_id, $args ); } }
if ( ! function_exists( 'mediaflow_image' ) ) { function mediaflow_image( int $attachment_id, $size = 'large', array $attr = array() ): string { return qmediaflow_image( $attachment_id, $size, $attr ); } }
