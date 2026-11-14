<?php
/**
 * Standalone browser-upload server-contract smoke test.
 * Does not load WordPress or perform browser image encoding.
 */

function apply_filters( $name, $value ) { return 'qmediaflow_newsroom_exempt_sideload' === $name && ! empty( $GLOBALS['qmf_exempt'] ) ? true : $value; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_get_image_mime( string $path ): string { return str_ends_with( $path, '.webp' ) ? 'image/webp' : 'image/jpeg'; }

require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-upload-optimizer.php';

$settings_ref = new ReflectionClass( \MediaFlow\Settings::class );
$settings = $settings_ref->newInstanceWithoutConstructor();
$values_prop = $settings_ref->getProperty( 'values' );
$values_prop->setAccessible( true );
$values_prop->setValue( $settings, \MediaFlow\Settings::defaults() );
$optimizer = new \MediaFlow\Upload_Optimizer( $settings );

$pass = static function ( string $message ): void { echo "PASS: {$message}\n"; };
$fail = static function ( string $message ): void { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); };

$dir = sys_get_temp_dir() . '/qmf-upload-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700 );
register_shutdown_function( static function () use ( $dir ): void {
    foreach ( glob( $dir . '/*' ) as $path ) { unlink( $path ); }
    rmdir( $dir );
} );
$webp = $dir . '/source.webp';
$jpg = $dir . '/source.jpg';
file_put_contents( $webp, str_repeat( 'x', 500000 ) );
file_put_contents( $jpg, 'jpeg test fixture' );
$valid = array( 'name' => 'photo.webp', 'type' => 'image/webp', 'tmp_name' => $webp, 'size' => 500000, 'error' => 0 );
$result = $optimizer->validate_upload( $valid );
empty( $result['error'] ) ? $pass( '500 KB WebP is accepted' ) : $fail( '500 KB WebP should be accepted' );

$oversize = $valid;
file_put_contents( $webp, 'x', FILE_APPEND ); // Metadata still claims 500000 bytes.
$result = $optimizer->validate_upload( $oversize );
! empty( $result['error'] ) ? $pass( '500 KB hard ceiling rejects larger WebP' ) : $fail( 'Oversized WebP was accepted' );

$jpeg = array( 'name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => $jpg, 'size' => 120000, 'error' => 0 );
$result = $optimizer->validate_upload( $jpeg );
! empty( $result['error'] ) ? $pass( 'classic image upload rejects non-WebP final source' ) : $fail( 'JPEG final source was accepted' );

$document = array( 'name' => 'report.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/report.pdf', 'size' => 800000, 'error' => 0 );
$result = $optimizer->validate_upload( $document );
empty( $result['error'] ) ? $pass( 'non-image attachments remain outside MediaFlow upload validation' ) : $fail( 'Non-image attachment was blocked' );

unset( $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] );
$result = $optimizer->validate_sideload( $jpeg );
empty( $result['error'] ) ? $pass( 'unmarked server sideload remains compatible' ) : $fail( 'Unmarked sideload was blocked' );

$_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] = '1';
$result = $optimizer->validate_sideload( $jpeg );
! empty( $result['error'] ) ? $pass( 'marked REST browser upload enforces final WebP contract' ) : $fail( 'Marked REST JPEG was accepted' );

define( 'QMEDIAFLOW_NEWSROOM_UPLOAD_POLICY', true );
unset( $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] );
$result = $optimizer->validate_sideload( $jpeg );
empty( $result['error'] ) ? $pass( 'unmarked sideload accepts actual JPEG under newsroom policy' ) : $fail( 'JPEG was rejected under newsroom policy' );
$result = $optimizer->validate_sideload( $oversize );
! empty( $result['error'] ) ? $pass( 'unmarked sideload enforces actual byte ceiling' ) : $fail( 'Oversized sideload bypassed policy' );
$result = $optimizer->validate_upload( array_replace( $valid, array( 'tmp_name' => $dir . '/missing.webp' ) ) );
! empty( $result['error'] ) ? $pass( 'claimed MIME does not substitute for an actual file' ) : $fail( 'Missing file was accepted' );

$config = \MediaFlow\Settings::defaults();
$config['newsroom_mimes'] = array( 'image/jpeg' );
$config['newsroom_max_bytes'] = 10;
$values_prop->setValue( $settings, $config );
$result = $optimizer->validate_sideload( $jpeg );
! empty( $result['error'] ) ? $pass( 'configured ceiling is used for JPEG sideloads' ) : $fail( 'configured ceiling was not enforced' );
file_put_contents( $webp, 'x' );
$result = $optimizer->validate_upload( $valid );
! empty( $result['error'] ) ? $pass( 'excluded MIME rejected even below configured ceiling' ) : $fail( 'excluded format was accepted' );

$GLOBALS['qmf_exempt'] = true;
unset( $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] );
$result = $optimizer->validate_sideload( $valid );
empty( $result['error'] ) ? $pass( 'trusted exemption allows excluded MIME on sideload' ) : $fail( 'trusted exemption did not apply' );
$_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] = '1';
$result = $optimizer->validate_sideload( $valid );
! empty( $result['error'] ) ? $pass( 'browser-marked sideload cannot use importer exemption' ) : $fail( 'browser upload bypassed policy' );
