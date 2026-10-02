<?php
/**
 * Plugin Name: MediaFlow
 * Description: On-demand WordPress image resizing with a disposable static cache and no database work on cached image delivery.
 * Version:     0.2.8
 * Author:      MediaFlow
 * License:     GPL-2.0-or-later
 * Text Domain: mediaflow
 * Requires at least: 6.5
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEDIAFLOW_VERSION', '0.2.8' );
define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', '4' );
define( 'MEDIAFLOW_FILE', __FILE__ );
define( 'MEDIAFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIAFLOW_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'MEDIAFLOW_MAX_OUTPUT_PIXELS' ) ) {
    define( 'MEDIAFLOW_MAX_OUTPUT_PIXELS', 8000000 );
}
if ( ! defined( 'MEDIAFLOW_MAX_SOURCE_PIXELS' ) ) {
    define( 'MEDIAFLOW_MAX_SOURCE_PIXELS', 24000000 );
}

if ( ! defined( 'MEDIAFLOW_MAX_GENERATORS' ) ) { define( 'MEDIAFLOW_MAX_GENERATORS', 2 ); }
require_once MEDIAFLOW_DIR . 'includes/class-budget.php';
require_once MEDIAFLOW_DIR . 'includes/class-encoding.php';
require_once MEDIAFLOW_DIR . 'includes/class-paths.php';
require_once MEDIAFLOW_DIR . 'includes/class-settings.php';
require_once MEDIAFLOW_DIR . 'includes/class-upload-optimizer.php';
require_once MEDIAFLOW_DIR . 'includes/class-manifest-store.php';
require_once MEDIAFLOW_DIR . 'includes/class-variant.php';
require_once MEDIAFLOW_DIR . 'includes/class-resolver.php';
require_once MEDIAFLOW_DIR . 'includes/class-processor.php';
require_once MEDIAFLOW_DIR . 'includes/class-request-handler.php';
require_once MEDIAFLOW_DIR . 'includes/class-responsive.php';
require_once MEDIAFLOW_DIR . 'includes/class-admin.php';
require_once MEDIAFLOW_DIR . 'includes/class-cli.php';
require_once MEDIAFLOW_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'MediaFlow\\Plugin', 'activate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        MediaFlow\Plugin::instance()->boot();
    },
    1
);

/**
 * Public helper: return a MediaFlow variant URL.
 *
 * @param int   $attachment_id WordPress attachment ID.
 * @param array $args          width, height, crop, quality, format.
 * @return string|false
 */
function mediaflow_url( int $attachment_id, array $args = array() ) {
    if ( ! MediaFlow\Plugin::network_enabled() ) { return false; }
    return MediaFlow\Plugin::instance()->resolver()->custom_url( $attachment_id, $args );
}

/**
 * Public helper: render a responsive MediaFlow image using WordPress markup.
 * WordPress remains responsible for loading/fetchpriority decisions.
 *
 * @param int          $attachment_id WordPress attachment ID.
 * @param string|array $size          Registered size name or [width, height].
 * @param array        $attr          Extra HTML attributes.
 * @return string
 */
function mediaflow_image( int $attachment_id, $size = 'large', array $attr = array() ): string {
    return (string) wp_get_attachment_image( $attachment_id, $size, false, $attr );
}
