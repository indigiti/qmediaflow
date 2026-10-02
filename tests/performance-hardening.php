<?php
/**
 * Standalone v0.2.6.1 performance-hardening smoke test.
 * Does not load WordPress or a real GD/Imagick editor.
 */

$root = sys_get_temp_dir() . '/mediaflow-perf-' . bin2hex( random_bytes( 5 ) );
@mkdir( $root . '/wp-content', 0777, true );

define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'MEDIAFLOW_MAX_GENERATORS', 2 );
define( 'MEDIAFLOW_MAX_SOURCE_PIXELS', 24000000 );
define( 'MEDIAFLOW_MAX_OUTPUT_PIXELS', 8000000 );
define( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION', '3' );

final class WP_Error {
    public function __construct( public string $code = '', public string $message = '' ) {}
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function absint( $value ): int { return abs( (int) $value ); }
function is_multisite(): bool { return false; }
function get_current_blog_id(): int { return 1; }
function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function content_url( string $path = '' ): string { return 'https://example.test/wp-content' . $path; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_image_editor_supports( array $args ): bool { return true; }

$GLOBALS['mf_decode_calls'] = 0;
$GLOBALS['mf_resize_calls'] = array();
$GLOBALS['mf_save_calls']   = array();

final class MF_Fake_Editor {
    private int $width = 256;
    private int $quality = 24;

    public function get_size(): array { return array( 'width' => $this->width, 'height' => $this->width ); }
    public function resize( int $width, $height = null, bool $crop = false ) {
        $GLOBALS['mf_resize_calls'][] = $width;
        $this->width = $width;
        return true;
    }
    public function set_quality( int $quality ) { $this->quality = $quality; return true; }
    public function save( string $path, string $mime ) {
        $GLOBALS['mf_save_calls'][] = array( $this->width, $this->quality );
        // Force two oversized 64px attempts, then allow 32px to fit.
        if ( $this->width >= 64 ) {
            $size = $this->quality >= 24 ? 1400 : 1100;
        } else {
            $size = 600;
        }
        file_put_contents( $path, str_repeat( 'x', $size ) );
        return array( 'path' => $path );
    }
}
function wp_get_image_editor( string $source ) {
    ++$GLOBALS['mf_decode_calls'];
    return new MF_Fake_Editor();
}

require dirname( __DIR__ ) . '/includes/class-variant.php';
require dirname( __DIR__ ) . '/includes/class-budget.php';
require dirname( __DIR__ ) . '/includes/class-paths.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-manifest-store.php';

$fail = static function ( string $message ) use ( $root ): void {
    fwrite( STDERR, "FAIL: {$message}\n" );
    passthru( 'rm -rf ' . escapeshellarg( $root ) );
    exit( 1 );
};
$pass = static function ( string $message ): void { echo "PASS: {$message}\n"; };

// Budget::check() needs a real image header. Use the existing 16x16 fixture.
$source = dirname( __DIR__ ) . '/tests/fixtures/plain.png';
$paths  = new \MediaFlow\Paths();
$settings_ref = new ReflectionClass( \MediaFlow\Settings::class );
$settings = $settings_ref->newInstanceWithoutConstructor();
$values_prop = $settings_ref->getProperty( 'values' );
$values_prop->setAccessible( true );
$values = \MediaFlow\Settings::defaults();
$values['placeholder_mode'] = 'lqip';
$values['lqip_width'] = 64;
$values['lqip_quality'] = 24;
$values['lqip_max_bytes'] = 1000;
$values['lqip_max_per_request'] = 2;
$values['lqip_max_total_bytes'] = 4096;
$values_prop->setValue( $settings, $values );

$store = new \MediaFlow\Manifest_Store( $paths, $settings );
$method = ( new ReflectionClass( \MediaFlow\Manifest_Store::class ) )->getMethod( 'make_placeholder' );
$method->setAccessible( true );
$uri = $method->invoke( $store, $source );
if ( ! is_string( $uri ) || ! str_starts_with( $uri, 'data:image/webp;base64,' ) ) { $fail( 'LQIP fallback produced an inline data URI' ); }
if ( 1 !== $GLOBALS['mf_decode_calls'] ) { $fail( 'source image decoded exactly once across fallback attempts' ); }
if ( array( 64, 32 ) !== $GLOBALS['mf_resize_calls'] ) { $fail( 'editor progressively downscaled without reopening source' ); }
if ( 3 !== count( $GLOBALS['mf_save_calls'] ) ) { $fail( 'expected quality/size fallback save attempts' ); }
$pass( 'single source decode reused across adaptive LQIP fallbacks' );

// Load Responsive after its dependencies exist; invoke only its private budget method.
require dirname( __DIR__ ) . '/includes/class-responsive.php';
$responsive_ref = new ReflectionClass( \MediaFlow\Responsive::class );
$count_prop = $responsive_ref->getProperty( 'inline_lqip_count' );
$bytes_prop = $responsive_ref->getProperty( 'inline_lqip_bytes' );
$count_prop->setAccessible( true ); $count_prop->setValue( 0 );
$bytes_prop->setAccessible( true ); $bytes_prop->setValue( 0 );
$settings_prop = $responsive_ref->getProperty( 'settings' );
$settings_prop->setAccessible( true );
$budget_method = $responsive_ref->getMethod( 'can_inline_lqip' );
$budget_method->setAccessible( true );

$one = $responsive_ref->newInstanceWithoutConstructor();
$settings_prop->setValue( $one, $settings );
if ( true !== $budget_method->invoke( $one, str_repeat( 'a', 3000 ) ) ) { $fail( 'first inline LQIP accepted within byte budget' ); }
if ( false !== $budget_method->invoke( $one, str_repeat( 'b', 2000 ) ) ) { $fail( 'total inline byte ceiling rejects overflow' ); }
if ( true !== $budget_method->invoke( $one, str_repeat( 'c', 1000 ) ) ) { $fail( 'remaining request byte budget remains usable' ); }

$two = $responsive_ref->newInstanceWithoutConstructor();
$settings_prop->setValue( $two, $settings );
if ( false !== $budget_method->invoke( $two, 'd' ) ) { $fail( 'request-global image count survives Responsive reconstruction' ); }
$pass( 'request-global LQIP count/byte budget survives context reconstruction' );

passthru( 'rm -rf ' . escapeshellarg( $root ) );
echo "PASS: performance hardening smoke test complete\n";
