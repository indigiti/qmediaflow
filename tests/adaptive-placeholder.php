<?php
/** Standalone v0.2.7 Auto-LQIP queue/gradient smoke test. */
$root = sys_get_temp_dir() . '/mediaflow-auto-' . bin2hex( random_bytes( 5 ) );
@mkdir( $root . '/wp-content', 0777, true );
define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', '4' );
define( 'MEDIAFLOW_MAX_GENERATORS', 2 );
define( 'MEDIAFLOW_MAX_SOURCE_PIXELS', 24000000 );
define( 'MEDIAFLOW_MAX_OUTPUT_PIXELS', 8000000 );

final class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} }
final class WP_Post {
    public function __construct( public string $post_type, public string $post_status, public string $post_password = '', public int $post_parent = 0 ) {}
}
$GLOBALS['qmf_parent'] = new WP_Post( 'post', 'publish' );
$GLOBALS['qmf_visibility'] = '';
function get_post( int $id ): ?WP_Post { return 12345 === $id ? new WP_Post( 'attachment', 'inherit', '', 1 ) : ( 1 === $id ? $GLOBALS['qmf_parent'] : null ); }
function get_post_meta( $id, $key, $single ): string { return $GLOBALS['qmf_visibility']; }
function is_post_publicly_viewable( WP_Post $post ): bool { return 'publish' === $post->post_status; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function absint( $value ): int { return abs( (int) $value ); }
function is_multisite(): bool { return false; }
function get_current_blog_id(): int { return 1; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function content_url( string $path = '' ): string { return 'https://example.test/wp-content' . $path; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
function wp_get_upload_dir(): array { return array( 'basedir' => WP_CONTENT_DIR . '/uploads' ); }
function wp_image_editor_supports( array $args ): bool { return true; }
final class MF_Auto_Editor {
    private int $width = 256; private int $quality = 24;
    public function get_size(): array { return array( 'width' => $this->width, 'height' => $this->width ); }
    public function resize( int $width, $height = null, bool $crop = false ) { $this->width = $width; return true; }
    public function set_quality( int $quality ) { $this->quality = $quality; return true; }
    public function save( string $path, string $mime ) { file_put_contents( $path, str_repeat( 'z', 600 ) ); return array( 'path' => $path ); }
}
function wp_get_image_editor( string $source ) { return new MF_Auto_Editor(); }
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_url_raw( string $url ): string { return $url; }
$GLOBALS['mf_scheduled'] = array();
function wp_next_scheduled( string $hook, array $args = array() ) {
    $key = $hook . '|' . json_encode( $args );
    return $GLOBALS['mf_scheduled'][ $key ] ?? false;
}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ) {
    $key = $hook . '|' . json_encode( $args );
    $GLOBALS['mf_scheduled'][ $key ] = $timestamp;
    return true;
}

require dirname( __DIR__ ) . '/includes/class-paths.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-variant.php';
require dirname( __DIR__ ) . '/includes/class-budget.php';
require dirname( __DIR__ ) . '/includes/class-manifest-store.php';
require dirname( __DIR__ ) . '/includes/class-responsive.php';

$fail = static function ( string $message ) use ( $root ): void {
    fwrite( STDERR, "FAIL: {$message}\n" );
    passthru( 'rm -rf ' . escapeshellarg( $root ) );
    exit( 1 );
};
$pass = static function ( string $message ): void { echo "PASS: {$message}\n"; };

$paths = new \MediaFlow\Paths();
$settings_ref = new ReflectionClass( \MediaFlow\Settings::class );
$settings = $settings_ref->newInstanceWithoutConstructor();
$values_prop = $settings_ref->getProperty( 'values' );
$values_prop->setAccessible( true );
$values = \MediaFlow\Settings::defaults();
$values['placeholder_mode'] = 'auto';
$values['cache_namespace'] = 'vtest123';
$values_prop->setValue( $settings, $values );

$store = new \MediaFlow\Manifest_Store( $paths, $settings );
$manifest = array(
    'id' => 12345,
    'revision' => 'abcdef123456',
    'source' => '2026/09/test.jpg',
    'source_path' => '/tmp/nonexistent.jpg',
    'source_url' => 'https://example.test/uploads/test.jpg',
    'mime' => 'image/jpeg',
    'width' => 1200,
    'height' => 800,
    'placeholder_type' => 'auto',
    'placeholder_signature' => $settings->lqip_signature(),
    'lqip_extension' => 'webp',
);

if ( in_array( '--without-link', $argv, true ) ) {
    if ( function_exists( 'link' ) ) { $fail( 'run this regression with link disabled' ); }
    $signature = $settings->lqip_signature();
    if ( false !== $store->queue_lqip( 12345, $manifest ) ) { $fail( 'queue must decline safely without link' ); }
    if ( is_file( $paths->lqip_queue_path( 12345, 'abcdef123456', $signature ) ) || ! empty( $GLOBALS['mf_scheduled'] ) ) {
        $fail( 'disabled link must not create a marker or schedule work' );
    }
    if ( '' !== $store->lqip_url( 12345, $manifest ) ) { $fail( 'cold preview must use the gradient without link' ); }
    $warm = $store->lqip_path_for_request( 12345, 'abcdef123456', $signature, 'webp' );
    if ( ! wp_mkdir_p( dirname( $warm ) ) || false === file_put_contents( $warm, 'preview fixture' ) ) { $fail( 'cannot create warm preview fixture' ); }
    clearstatcache( true, $warm );
    if ( '' === $store->lqip_url( 12345, $manifest ) ) { $fail( 'warm preview should stay available without link' ); }
    $pass( 'Auto-LQIP degrades safely without link and preserves warm previews' );
    passthru( 'rm -rf ' . escapeshellarg( $root ) );
    exit( 0 );
}

$url = $store->lqip_url( 12345, $manifest );
if ( ! str_contains( $url, '/lqip/abcdef123456/' . $settings->lqip_signature() . '.webp' ) ) {
    $fail( 'Auto-LQIP URL must include source revision and LQIP settings signature' );
}
$pass( 'revisioned immutable Auto-LQIP URL' );

if ( true !== $store->queue_lqip( 12345, $manifest ) ) { $fail( 'first missing LQIP should enqueue' ); }
if ( false !== $store->queue_lqip( 12345, $manifest ) ) { $fail( 'duplicate visitor should not enqueue duplicate marker' ); }
if ( 1 !== count( $GLOBALS['mf_scheduled'] ) ) { $fail( 'one site-scoped worker event should be scheduled' ); }
$queue = $paths->lqip_queue_path( 12345, 'abcdef123456', $settings->lqip_signature() );
if ( ! is_readable( $queue ) ) { $fail( 'queue marker should exist in private runtime' ); }
$data = json_decode( (string) file_get_contents( $queue ), true );
if ( ! is_array( $data ) || 'queued' !== ( $data['state'] ?? '' ) ) { $fail( 'queue marker state should be queued' ); }
$pass( 'filesystem anti-stampede queue deduplicates first-visit generation' );
$GLOBALS['qmf_parent']->post_password = 'protected';
if ( \MediaFlow\Manifest_Store::public_media_allowed( 12345 ) ) { $fail( 'password-protected parent allowed' ); }
$GLOBALS['qmf_parent']->post_password = '';
$GLOBALS['qmf_parent']->post_status = 'private';
if ( \MediaFlow\Manifest_Store::public_media_allowed( 12345 ) ) { $fail( 'private parent allowed' ); }
$GLOBALS['qmf_parent']->post_status = 'publish';
$GLOBALS['qmf_visibility'] = 'private';
if ( \MediaFlow\Manifest_Store::public_media_allowed( 12345 ) ) { $fail( 'explicitly protected media allowed' ); }
$GLOBALS['qmf_visibility'] = '';
if ( ! \MediaFlow\Manifest_Store::public_media_allowed( 12345 ) ) { $fail( 'public attachment refused' ); }
$pass( 'public eligibility denies protected parent and explicit opt-out' );

$fixture = dirname( __DIR__ ) . '/tests/fixtures/plain.png';
$manifest['source_path'] = $fixture;
if ( null !== $store->source_path( $manifest ) ) { $fail( 'source outside uploads was accepted' ); }
$uploads = WP_CONTENT_DIR . '/uploads';
wp_mkdir_p( $uploads );
symlink( $fixture, $uploads . '/escape.png' );
$manifest['source_path'] = $uploads . '/escape.png';
if ( null !== $store->source_path( $manifest ) ) { $fail( 'uploads symlink escape was accepted' ); }
$manifest['source_path'] = $uploads . '/source.png';
copy( $fixture, $manifest['source_path'] );
$generate = ( new ReflectionClass( \MediaFlow\Manifest_Store::class ) )->getMethod( 'generate_external_lqip' );
$generate->setAccessible( true );
if ( true !== $generate->invoke( $store, 12345, $manifest, $settings->lqip_signature() ) ) { $fail( 'background LQIP generation should publish static preview' ); }
$target = $paths->lqip_path( 12345, 'abcdef123456', $settings->lqip_signature(), 'webp' );
if ( ! is_readable( $target ) || filesize( $target ) < 1 ) { $fail( 'static Auto-LQIP file should exist after background generation' ); }
$pass( 'background worker path publishes a static LQIP atomically' );

$lock1 = $paths->lqip_job_lock_path( 12345, 'abcdef123456', $settings->lqip_signature() );
$lock2 = $paths->lqip_job_lock_path( 99999, '111111111111', $settings->lqip_signature() );
if ( ! preg_match( '#/generation-locks/slot-\d{2}\.lock$#', $lock1 ) || ! preg_match( '#/generation-locks/slot-\d{2}\.lock$#', $lock2 ) ) {
    $fail( 'LQIP generation should use bounded hash lock slots' );
}
$pass( 'bounded generation lock pool avoids per-image lock inode growth' );

$responsive_ref = new ReflectionClass( \MediaFlow\Responsive::class );
$responsive = $responsive_ref->newInstanceWithoutConstructor();
$settings_prop = $responsive_ref->getProperty( 'settings' );
$settings_prop->setAccessible( true );
$settings_prop->setValue( $responsive, $settings );
$gradient = $responsive_ref->getMethod( 'gradient_css' );
$gradient->setAccessible( true );
$one = $gradient->invoke( $responsive, 12345, $manifest );
$two = $gradient->invoke( $responsive, 12345, $manifest );
$three = $gradient->invoke( $responsive, 54321, $manifest );
if ( $one !== $two || ! str_starts_with( $one, 'linear-gradient(' ) ) { $fail( 'gradient fallback should be deterministic' ); }
if ( $one === $three ) { $fail( 'different attachment seeds should normally produce different gradients' ); }
$pass( 'stable generated gradient fallback is deterministic per image' );

// A stale manifest extension must not make cached HTML point at a file the
// current background encoder will never publish.
$stale_extension = $manifest;
$stale_extension['lqip_extension'] = 'jpg';
$current_url = $store->lqip_url( 12345, $stale_extension );
if ( ! str_ends_with( $current_url, '.webp' ) ) { $fail( 'Auto-LQIP URL should use the current encoder extension' ); }
$pass( 'Auto-LQIP URL follows the current encoder extension instead of stale manifest metadata' );

$manifest_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-manifest-store.php' );
if ( ! str_contains( $manifest_source, 'Migrating them into Auto mode must not perform attachment DB lookups' ) ) {
    $fail( 'existing manifest Auto migration should stay off attachment DB/source decode path' );
}
$pass( 'existing manifest Auto migration avoids attachment DB/source fingerprint work' );

passthru( 'rm -rf ' . escapeshellarg( $root ) );
echo "PASS: adaptive placeholder smoke test complete\n";

