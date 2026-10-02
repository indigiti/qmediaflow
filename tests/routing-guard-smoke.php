<?php
/**
 * Standalone filesystem smoke test for the v0.2.3 Apache routing migration.
 * Does not load WordPress and does not test a real web server.
 */

$root = sys_get_temp_dir() . '/mediaflow-routing-' . bin2hex( random_bytes( 5 ) );
@mkdir( $root . '/wp-content/image-cache/sites/1', 0777, true );
@mkdir( $root . '/wp-content/image-cache/sites/2', 0777, true );
@mkdir( $root . '/wp-content/.mediaflow-private/sites/1', 0777, true );

define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'MEDIAFLOW_VERSION', '0.2.7' );
define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', '4' );

function is_multisite(): bool { return true; }
function get_current_blog_id(): int { return 1; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function content_url( string $path = '' ): string { return 'https://example.test/wp-content' . $path; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function get_main_site_id(): int { return 1; }
function get_site( int $id ): object { return (object) array( 'path' => '/' ); }
function get_home_url( int $site_id, string $path = '' ): string { throw new RuntimeException( 'Routing must not switch sites via get_home_url' ); }
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }

require dirname( __DIR__ ) . '/includes/class-paths.php';

$legacy_public = "Options -Indexes\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.(?:avif|webp|jpe?g|png)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n    Header set X-Content-Type-Options \"nosniff\"\n  </FilesMatch>\n</IfModule>\n";
$legacy_private = "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";

file_put_contents( WP_CONTENT_DIR . '/image-cache/.htaccess', $legacy_public . "# CUSTOM keep-me\n" );
file_put_contents( WP_CONTENT_DIR . '/image-cache/sites/1/.htaccess', $legacy_public );
file_put_contents( WP_CONTENT_DIR . '/image-cache/sites/2/.htaccess', $legacy_public . "# SITE CUSTOM keep-me-too\n" );
file_put_contents( WP_CONTENT_DIR . '/.mediaflow-private/.htaccess', $legacy_private );
file_put_contents( WP_CONTENT_DIR . '/.mediaflow-private/sites/1/.htaccess', $legacy_private );

$fail = static function ( string $message ) use ( $root ): void {
    fwrite( STDERR, "FAIL: {$message}\n" );
    passthru( 'rm -rf ' . escapeshellarg( $root ) );
    exit( 1 );
};
$pass = static function ( string $message ): void { echo "PASS: {$message}\n"; };

$paths = new \MediaFlow\Paths();
$paths->upgrade_guards_if_needed();
$paths->upgrade_guards_if_needed(); // idempotence.

$root_rules = (string) file_get_contents( WP_CONTENT_DIR . '/image-cache/.htaccess' );
if ( str_contains( $root_rules, 'Options -Indexes' ) ) { $fail( 'legacy public Options directive removed' ); }
if ( 1 !== substr_count( $root_rules, '# BEGIN MediaFlow' ) ) { $fail( 'managed public block is singular' ); }
if ( ! str_contains( $root_rules, 'RewriteCond %{REQUEST_FILENAME} !-f' ) ) { $fail( 'cold misses are file-gated' ); }
if ( ! str_contains( $root_rules, 'RewriteRule ^(?:sites/' ) ) { $fail( 'multisite cache path is routed' ); }
if ( ! str_contains( $root_rules, '/lqip/' ) || ! str_contains( $root_rules, '__mediaflow-lqip-pending.svg' ) ) { $fail( 'missing Auto-LQIP files use the transparent static pending fallback' ); }
if ( ! str_contains( $root_rules, '/index.php [L]' ) ) { $fail( 'cold miss target is WordPress front controller' ); }
if ( ! is_readable( WP_CONTENT_DIR . '/image-cache/__mediaflow-lqip-pending.svg' ) ) { $fail( 'transparent LQIP pending fallback created during routing migration' ); }
if ( ! str_contains( $root_rules, '# CUSTOM keep-me' ) ) { $fail( 'custom root directives preserved' ); }
$pass( 'root public guard migrated, routed and custom directives preserved' );

if ( file_exists( WP_CONTENT_DIR . '/image-cache/sites/1/.htaccess' ) ) { $fail( 'exact legacy child guard removed' ); }
$site_two = (string) file_get_contents( WP_CONTENT_DIR . '/image-cache/sites/2/.htaccess' );
if ( str_contains( $site_two, 'Options -Indexes' ) || ! str_contains( $site_two, '# SITE CUSTOM keep-me-too' ) ) { $fail( 'child custom directives preserved without legacy block' ); }
$pass( 'multisite child guards repaired without overwriting custom rules' );

$private_root = (string) file_get_contents( WP_CONTENT_DIR . '/.mediaflow-private/.htaccess' );
$private_site = (string) file_get_contents( WP_CONTENT_DIR . '/.mediaflow-private/sites/1/.htaccess' );
if ( str_contains( $private_root, 'Options -Indexes' ) || str_contains( $private_site, 'Options -Indexes' ) ) { $fail( 'private legacy Options directive removed' ); }
if ( ! str_contains( $private_root, 'Require all denied' ) || ! str_contains( $private_site, 'Require all denied' ) ) { $fail( 'private deny rules retained' ); }
$pass( 'private runtime deny guards retained without Options directive' );

$marker = trim( (string) file_get_contents( WP_CONTENT_DIR . '/.mediaflow-private/.routing-version' ) );
if ( '4' !== $marker ) { $fail( 'filesystem migration marker written' ); }
$pass( 'filesystem migration is versioned and idempotent' );

passthru( 'rm -rf ' . escapeshellarg( $root ) );
echo "PASS: routing guard smoke test complete\n";
