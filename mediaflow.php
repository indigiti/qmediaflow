<?php
/**
 * Plugin Name: QMediaFlow
 * Description: Browser-first WordPress image optimization with adaptive, on-demand responsive delivery and a disposable static cache.
 * Version:     0.2.9
 * Author:      QMediaFlow
 * License:     GPL-2.0-or-later
 * Text Domain: qmediaflow
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * QMediaFlow is the canonical product name from v0.2.9 onward.
 * The MediaFlow namespace, hooks, actions, storage paths and constants remain
 * supported as compatibility identifiers so existing sites do not break.
 */
define( 'QMEDIAFLOW_VERSION', '0.2.9' );
define( 'QMEDIAFLOW_ROUTING_SCHEMA_VERSION', '4' );
define( 'QMEDIAFLOW_FILE', __FILE__ );
define( 'QMEDIAFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'QMEDIAFLOW_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'MEDIAFLOW_VERSION' ) ) {
    define( 'MEDIAFLOW_VERSION', QMEDIAFLOW_VERSION );
}
if ( ! defined( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION' ) ) {
    define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', QMEDIAFLOW_ROUTING_SCHEMA_VERSION );
}
if ( ! defined( 'MEDIAFLOW_FILE' ) ) {
    define( 'MEDIAFLOW_FILE', QMEDIAFLOW_FILE );
}
if ( ! defined( 'MEDIAFLOW_DIR' ) ) {
    define( 'MEDIAFLOW_DIR', QMEDIAFLOW_DIR );
}
if ( ! defined( 'MEDIAFLOW_URL' ) ) {
    define( 'MEDIAFLOW_URL', QMEDIAFLOW_URL );
}

if ( defined( 'QMEDIAFLOW_PRIVATE_DIR' ) && ! defined( 'MEDIAFLOW_PRIVATE_DIR' ) ) {
    define( 'MEDIAFLOW_PRIVATE_DIR', QMEDIAFLOW_PRIVATE_DIR );
} elseif ( defined( 'MEDIAFLOW_PRIVATE_DIR' ) && ! defined( 'QMEDIAFLOW_PRIVATE_DIR' ) ) {
    define( 'QMEDIAFLOW_PRIVATE_DIR', MEDIAFLOW_PRIVATE_DIR );
}

if ( ! defined( 'QMEDIAFLOW_MAX_OUTPUT_PIXELS' ) ) {
    define( 'QMEDIAFLOW_MAX_OUTPUT_PIXELS', defined( 'MEDIAFLOW_MAX_OUTPUT_PIXELS' ) ? MEDIAFLOW_MAX_OUTPUT_PIXELS : 8000000 );
}
if ( ! defined( 'MEDIAFLOW_MAX_OUTPUT_PIXELS' ) ) {
    define( 'MEDIAFLOW_MAX_OUTPUT_PIXELS', QMEDIAFLOW_MAX_OUTPUT_PIXELS );
}

if ( ! defined( 'QMEDIAFLOW_MAX_SOURCE_PIXELS' ) ) {
    define( 'QMEDIAFLOW_MAX_SOURCE_PIXELS', defined( 'MEDIAFLOW_MAX_SOURCE_PIXELS' ) ? MEDIAFLOW_MAX_SOURCE_PIXELS : 24000000 );
}
if ( ! defined( 'MEDIAFLOW_MAX_SOURCE_PIXELS' ) ) {
    define( 'MEDIAFLOW_MAX_SOURCE_PIXELS', QMEDIAFLOW_MAX_SOURCE_PIXELS );
}

if ( ! defined( 'QMEDIAFLOW_MAX_GENERATORS' ) ) {
    define( 'QMEDIAFLOW_MAX_GENERATORS', defined( 'MEDIAFLOW_MAX_GENERATORS' ) ? MEDIAFLOW_MAX_GENERATORS : 2 );
}
if ( ! defined( 'MEDIAFLOW_MAX_GENERATORS' ) ) {
    define( 'MEDIAFLOW_MAX_GENERATORS', QMEDIAFLOW_MAX_GENERATORS );
}

require_once QMEDIAFLOW_DIR . 'includes/class-budget.php';
require_once QMEDIAFLOW_DIR . 'includes/class-encoding.php';
require_once QMEDIAFLOW_DIR . 'includes/class-paths.php';
require_once QMEDIAFLOW_DIR . 'includes/class-settings.php';
require_once QMEDIAFLOW_DIR . 'includes/class-upload-optimizer.php';
require_once QMEDIAFLOW_DIR . 'includes/class-manifest-store.php';
require_once QMEDIAFLOW_DIR . 'includes/class-variant.php';
require_once QMEDIAFLOW_DIR . 'includes/class-resolver.php';
require_once QMEDIAFLOW_DIR . 'includes/class-processor.php';
require_once QMEDIAFLOW_DIR . 'includes/class-request-handler.php';
require_once QMEDIAFLOW_DIR . 'includes/class-responsive.php';
require_once QMEDIAFLOW_DIR . 'includes/class-admin.php';
require_once QMEDIAFLOW_DIR . 'includes/class-cli.php';
require_once QMEDIAFLOW_DIR . 'includes/class-plugin.php';
require_once QMEDIAFLOW_DIR . 'includes/class-branding.php';

register_activation_hook( __FILE__, array( 'MediaFlow\\Plugin', 'activate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        MediaFlow\Plugin::instance()->boot();
        MediaFlow\Branding::register();
    },
    1
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    add_action(
        'cli_init',
        static function (): void {
            \WP_CLI::add_command( 'qmediaflow', MediaFlow\CLI::class );
        }
    );
}

/**
 * Canonical public helper: return a QMediaFlow variant URL.
 *
 * @param int   $attachment_id WordPress attachment ID.
 * @param array $args          width, height, crop, quality, format.
 * @return string|false
 */
if ( ! function_exists( 'qmediaflow_url' ) ) {
    function qmediaflow_url( int $attachment_id, array $args = array() ) {
        if ( ! MediaFlow\Plugin::network_enabled() ) {
            return false;
        }
        return MediaFlow\Plugin::instance()->resolver()->custom_url( $attachment_id, $args );
    }
}

/**
 * Canonical public helper: render a responsive QMediaFlow image using WordPress markup.
 * WordPress remains responsible for loading/fetchpriority decisions.
 *
 * @param int          $attachment_id WordPress attachment ID.
 * @param string|array $size          Registered size name or [width, height].
 * @param array        $attr          Extra HTML attributes.
 * @return string
 */
if ( ! function_exists( 'qmediaflow_image' ) ) {
    function qmediaflow_image( int $attachment_id, $size = 'large', array $attr = array() ): string {
        return (string) wp_get_attachment_image( $attachment_id, $size, false, $attr );
    }
}

/** Legacy API alias retained for backward compatibility. */
if ( ! function_exists( 'mediaflow_url' ) ) {
    function mediaflow_url( int $attachment_id, array $args = array() ) {
        return qmediaflow_url( $attachment_id, $args );
    }
}

/** Legacy API alias retained for backward compatibility. */
if ( ! function_exists( 'mediaflow_image' ) ) {
    function mediaflow_image( int $attachment_id, $size = 'large', array $attr = array() ): string {
        return qmediaflow_image( $attachment_id, $size, $attr );
    }
}
