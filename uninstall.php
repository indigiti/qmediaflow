<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Intentionally keep wp-content/image-cache/ and all original media.
// Cached files are harmless static assets and may still be referenced by page caches.
delete_option( 'mediaflow_settings' );
