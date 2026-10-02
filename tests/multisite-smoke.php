<?php
/** Run ONLY with WordPress loaded: wp eval-file wp-content/plugins/mediaflow/tests/multisite-smoke.php
 * Creates normal per-site runtime files if absent; never changes options or attachment data.
 * Requires two existing healthy sites and network activation. Does not test HTTP or image encoding.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! is_multisite() || ! \MediaFlow\Plugin::network_enabled() ) { WP_CLI::error( 'Network activate MediaFlow on multisite first.' ); }
$sites = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 2, 'deleted' => 0, 'archived' => 0, 'spam' => 0 ) );
if ( count( $sites ) < 2 ) { WP_CLI::error( 'Two healthy sites are required.' ); }
$assert = static function ( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } WP_CLI::line( 'PASS: ' . $message ); };
$original = get_current_blog_id();
$contexts = array();
$manifest = array( 'width' => 1600, 'height' => 900, 'mime' => 'image/jpeg', 'revision' => '0123456789ab' );
try {
    foreach ( $sites as $site ) {
        switch_to_blog( (int) $site->blog_id );
        try {
            $plugin = \MediaFlow\Plugin::instance();
            $paths = $plugin->paths();
            $assert( $paths->site_id() === get_current_blog_id(), 'Service context follows site switch' );
            $key = $plugin->settings()->signing_key();
            $assert( strlen( $key ) >= 32, 'Signing storage is ready' );
            $variant = $plugin->resolver()->variant_for( 123, $manifest, array( 'width' => 640, 'height' => 0, 'format' => 'jpeg', 'quality' => 82 ) );
            $assert( $variant instanceof \MediaFlow\Variant, 'Can sign variant' );
            $contexts[] = array( 'id' => get_current_blog_id(), 'cache' => $paths->attachment_root_dir( 123 ), 'manifest' => $paths->manifest_path( 123 ), 'settings' => $paths->settings_path(), 'secret' => $paths->secret_path(), 'locks' => $paths->processing_lock_dir(), 'key' => $key, 'variant' => $variant, 'namespace' => $plugin->settings()->cache_namespace(), 'resolver' => $plugin->resolver(), 'url' => $plugin->resolver()->url( 123, $manifest, $variant ) );
        } finally { restore_current_blog(); }
        $assert( \MediaFlow\Plugin::instance()->paths()->site_id() === $original, 'Service context restores original site' );
    }
    foreach ( array( 'cache', 'manifest', 'settings', 'secret', 'key', 'url' ) as $field ) {
        $assert( $contexts[0][$field] !== $contexts[1][$field], 'Sites have separate ' . $field );
    }
    $assert( $contexts[0]['locks'] === $contexts[1]['locks'], 'Processing admission is shared across sites' );
    $a = $contexts[0]; $b = $contexts[1];
    $assert( $a['resolver']->validate_signature( 123, $manifest['revision'], $a['namespace'], $a['variant'] ), 'Own-site signature validates' );
    $assert( ! $b['resolver']->validate_signature( 123, $manifest['revision'], $a['namespace'], $a['variant'] ), 'Cross-site replay rejected' );
    // Also prove site identity is signed if an administrator accidentally copies the same secret.
    $property = new ReflectionProperty( \MediaFlow\Settings::class, 'signing_key_runtime' );
    switch_to_blog( $b['id'] );
    try {
        $settings = \MediaFlow\Plugin::instance()->settings();
        $property->setValue( $settings, $a['key'] );
        $assert( ! \MediaFlow\Plugin::instance()->resolver()->validate_signature( 123, $manifest['revision'], $a['namespace'], $a['variant'] ), 'Site binding rejects replay even with equal secrets' );
    } finally { restore_current_blog(); }
    WP_CLI::success( 'Multisite context and signature tests passed. Separately test HTTP cold/warm delivery on every domain/path configuration.' );
} catch ( Throwable $e ) {
    WP_CLI::error( $e->getMessage() );
}
