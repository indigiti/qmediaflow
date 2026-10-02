<?php
/**
 * Standalone browser-upload server-contract smoke test.
 * Does not load WordPress or perform browser image encoding.
 */

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

$valid = array( 'name' => 'photo.webp', 'type' => 'image/webp', 'tmp_name' => '/tmp/mediaflow-test.webp', 'size' => 500000, 'error' => 0 );
$result = $optimizer->validate_upload( $valid );
empty( $result['error'] ) ? $pass( '500 KB WebP is accepted' ) : $fail( '500 KB WebP should be accepted' );

$oversize = $valid;
$oversize['size'] = 500001;
$result = $optimizer->validate_upload( $oversize );
! empty( $result['error'] ) ? $pass( '500 KB hard ceiling rejects larger WebP' ) : $fail( 'Oversized WebP was accepted' );

$jpeg = array( 'name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/mediaflow-test.jpg', 'size' => 120000, 'error' => 0 );
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
