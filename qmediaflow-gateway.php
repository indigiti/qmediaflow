<?php
/**
 * QMediaFlow standalone cold-miss gateway.
 *
 * This file intentionally does not load WordPress on the normal path. It reads
 * the same private settings/key/manifests used by the plugin, validates the same
 * immutable signature, performs one bounded encode, and atomically publishes the
 * file. Warm hits never reach this script because the web server serves them.
 */

declare(strict_types=1);

$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
    qmf_fail( 405, 'Method not allowed.' );
}

$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
$path = (string) parse_url( $request_uri, PHP_URL_PATH );
$needle = '/image-cache/';
$pos = strpos( $path, $needle );
if ( false === $pos ) {
    qmf_fail( 404, 'Invalid QMediaFlow request.' );
}
$relative = ltrim( substr( $path, $pos + strlen( $needle ) ), '/' );
$pattern = '#^(?:sites/(?<site>[1-9][0-9]{0,9})/)?(?<s1>\d{2})/(?<s2>\d{2})/(?<id>\d+)/(?<ns>[A-Za-z0-9_-]{4,32})/(?<rev>[a-f0-9]{12})/w(?<w>\d+)-h(?<h>\d+)-c(?<crop>[01])-q(?<q>\d+)(?:-fx(?<fx>\d{1,3})-fy(?<fy>\d{1,3}))?-s(?<sig>[a-f0-9]{32})\.(?<ext>avif|webp|jpe?g|png)$#i';
if ( ! preg_match( $pattern, $relative, $m ) ) {
    qmf_fail( 404, 'Invalid QMediaFlow image request.' );
}

$site_id = isset( $m['site'] ) && '' !== (string) $m['site'] ? (int) $m['site'] : 0;
$id      = (int) $m['id'];
$width   = (int) $m['w'];
$height  = (int) $m['h'];
$crop    = '1' === (string) $m['crop'];
$quality = (int) $m['q'];
$focal_x = isset( $m['fx'] ) && '' !== (string) $m['fx'] ? max( 0, min( 100, (int) $m['fx'] ) ) : 50;
$focal_y = isset( $m['fy'] ) && '' !== (string) $m['fy'] ? max( 0, min( 100, (int) $m['fy'] ) ) : 50;
$signature = strtolower( (string) $m['sig'] );
$revision  = strtolower( (string) $m['rev'] );
$namespace = (string) $m['ns'];
$extension = strtolower( (string) $m['ext'] );
$format = match ( $extension ) {
    'avif' => 'avif',
    'webp' => 'webp',
    'png'  => 'png',
    'jpg', 'jpeg' => 'jpeg',
    default => 'jpeg',
};

if ( $id < 1 || $width < 1 || $width > 8192 || $height < 0 || $height > 8192 || $quality < 1 || $quality > 100 ) {
    qmf_fail( 400, 'QMediaFlow transformation is outside the allowed limits.' );
}
$level_one = intdiv( $id, 10000 ) % 100;
$level_two = intdiv( $id, 100 ) % 100;
if ( sprintf( '%02d/%02d', $level_one, $level_two ) !== $m['s1'] . '/' . $m['s2'] ) {
    qmf_fail( 404, 'Invalid QMediaFlow shard.' );
}

// Standard plugin layout: wp-content/plugins/qmediaflow/qmediaflow-gateway.php.
$content_root = dirname( __DIR__, 2 );
$cache_root = $content_root . '/image-cache';
$private_root = $content_root . '/.mediaflow-private';
$config_file = $cache_root . '/__qmediaflow-gateway-config.php';
if ( is_readable( $config_file ) ) {
    $config = include $config_file;
    if ( is_array( $config ) ) {
        if ( ! empty( $config['cache_root'] ) ) { $cache_root = rtrim( (string) $config['cache_root'], '/\\' ); }
        if ( ! empty( $config['private_root'] ) ) { $private_root = rtrim( (string) $config['private_root'], '/\\' ); }
    }
}
$config = isset( $config ) && is_array( $config ) ? $config : array();
$scope = $site_id > 0 ? '/sites/' . $site_id : '';
$private_dir = $private_root . $scope;
$cache_dir   = $cache_root . $scope;
$manifest_path = $private_dir . '/manifests/' . $m['s1'] . '/' . $m['s2'] . '/' . $id . '.json';
$secret_path   = $private_dir . '/secret.php';
$settings_path = $private_dir . '/settings.json';

if ( ! is_readable( $manifest_path ) || ! is_readable( $secret_path ) ) {
    qmf_metric( $private_dir, 'gateway_manifest_missing' );
    qmf_fail( 404, 'QMediaFlow manifest is unavailable.' );
}
$manifest_json = @file_get_contents( $manifest_path );
$manifest = is_string( $manifest_json ) ? json_decode( $manifest_json, true ) : null;
if ( ! is_array( $manifest ) || ! hash_equals( (string) ( $manifest['revision'] ?? '' ), $revision ) ) {
    qmf_metric( $private_dir, 'gateway_revision_missing' );
    qmf_fail( 404, 'QMediaFlow source revision was not found.' );
}
$key = include $secret_path;
if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
    qmf_fail( 503, 'QMediaFlow signing storage is unavailable.' );
}

$payload = array(
    $site_id > 0 ? 'mf3-site-' . $site_id : 'mf2',
    $id,
    $revision,
    $namespace,
    $width,
    $height,
    $crop ? 1 : 0,
    $quality,
    $format,
);
if ( 50 !== $focal_x || 50 !== $focal_y ) {
    $payload[] = $focal_x;
    $payload[] = $focal_y;
}
$expected = substr( hash_hmac( 'sha256', implode( '|', $payload ), $key ), 0, 32 );
if ( ! hash_equals( $expected, $signature ) ) {
    qmf_metric( $private_dir, 'gateway_signature_rejected' );
    qmf_fail( 403, 'Invalid QMediaFlow transformation signature.' );
}

$source = (string) ( $manifest['source_path'] ?? '' );
$source_real = '' !== $source ? realpath( $source ) : false;
$cache_real = realpath( $cache_root );
$private_real = realpath( $private_root );
if ( false === $source_real || ! is_readable( $source_real ) ) {
    qmf_fallback( $private_dir, $manifest, 'source_missing' );
}
$normalized_source = str_replace( '\\', '/', $source_real );
foreach ( array_filter( array( $cache_real, $private_real ) ) as $forbidden ) {
    $forbidden = rtrim( str_replace( '\\', '/', (string) $forbidden ), '/' );
    if ( $normalized_source === $forbidden || str_starts_with( $normalized_source, $forbidden . '/' ) ) {
        qmf_fail( 403, 'QMediaFlow refused an unsafe source path.' );
    }
}

$source_w = max( 1, (int) ( $manifest['width'] ?? 0 ) );
$source_h = max( 1, (int) ( $manifest['height'] ?? 0 ) );
$max_source_pixels = max( 1, (int) ( $config['max_source_pixels'] ?? 24000000 ) );
$max_output_pixels = max( 1, (int) ( $config['max_output_pixels'] ?? 8000000 ) );
if ( $source_w * $source_h > $max_source_pixels ) {
    qmf_fallback( $private_dir, $manifest, 'source_too_large' );
}
list( $out_w, $out_h ) = qmf_output_dimensions( $source_w, $source_h, $width, $height, $crop );
if ( $out_w * $out_h > $max_output_pixels ) {
    qmf_fail( 400, 'QMediaFlow output exceeds the processing pixel limit.' );
}

$target = $cache_root . '/' . $relative;
$target_dir = dirname( $target );
if ( is_readable( $target ) ) {
    qmf_serve( $target, qmf_mime( $format ), $method );
}
if ( ! is_dir( $target_dir ) && ! @mkdir( $target_dir, 0755, true ) && ! is_dir( $target_dir ) ) {
    qmf_fallback( $private_dir, $manifest, 'cache_dir' );
}

$variant_lock_path = $target_dir . '/.lock-' . hash( 'sha256', basename( $target ) );
$variant_lock = @fopen( $variant_lock_path, 'c' );
if ( ! is_resource( $variant_lock ) || ! @flock( $variant_lock, LOCK_EX | LOCK_NB ) ) {
    if ( is_resource( $variant_lock ) ) { @fclose( $variant_lock ); }
    qmf_metric( $private_dir, 'gateway_fallback_busy' );
    qmf_fallback( $private_dir, $manifest, 'busy' );
}

$slot = null;
$max_generators = max( 1, min( 16, (int) ( $config['max_generators'] ?? 2 ) ) );
$lock_root = $private_root . '/locks';
if ( ! is_dir( $lock_root ) ) { @mkdir( $lock_root, 0700, true ); }
for ( $i = 0; $i < $max_generators; ++$i ) {
    $candidate = @fopen( $lock_root . '/slot-' . $i, 'c' );
    if ( is_resource( $candidate ) && @flock( $candidate, LOCK_EX | LOCK_NB ) ) { $slot = $candidate; break; }
    if ( is_resource( $candidate ) ) { @fclose( $candidate ); }
}
if ( ! is_resource( $slot ) ) {
    @flock( $variant_lock, LOCK_UN ); @fclose( $variant_lock );
    qmf_metric( $private_dir, 'gateway_fallback_capacity' );
    qmf_fallback( $private_dir, $manifest, 'capacity' );
}

$started = microtime( true );
try {
    clearstatcache( true, $target );
    if ( is_readable( $target ) ) {
        qmf_serve( $target, qmf_mime( $format ), $method );
    }
    $settings = array();
    if ( is_readable( $settings_path ) ) {
        $raw_settings = @file_get_contents( $settings_path );
        $settings = is_string( $raw_settings ) ? json_decode( $raw_settings, true ) : array();
        if ( ! is_array( $settings ) ) { $settings = array(); }
    }
    $temp = $target_dir . '/.qmf-' . bin2hex( random_bytes( 6 ) ) . '.' . ( 'jpeg' === $format ? 'jpg' : $format );
    $ok = qmf_generate_imagick( $source_real, $temp, $source_w, $source_h, $out_w, $out_h, $crop, $quality, $format, $focal_x, $focal_y, $settings );
    if ( ! $ok ) {
        $ok = qmf_generate_gd( $source_real, $temp, (string) ( $manifest['mime'] ?? '' ), $source_w, $source_h, $out_w, $out_h, $crop, $quality, $format, $focal_x, $focal_y, $settings );
    }
    if ( ! $ok || ! is_readable( $temp ) || @filesize( $temp ) < 1 ) {
        @unlink( $temp );
        qmf_metric( $private_dir, 'gateway_generation_failed' );
        qmf_fallback( $private_dir, $manifest, 'encode_failed' );
    }
    if ( ! @rename( $temp, $target ) ) {
        @unlink( $temp );
        qmf_metric( $private_dir, 'gateway_atomic_publish_failed' );
        qmf_fallback( $private_dir, $manifest, 'publish_failed' );
    }
    @chmod( $target, 0644 );
    qmf_metric( $private_dir, 'gateway_generated' );
    qmf_timing( $private_dir, 'gateway_generation_ms', ( microtime( true ) - $started ) * 1000 );
    qmf_counter_add( $private_dir, 'generated_bytes', (int) @filesize( $target ) );
} finally {
    if ( is_resource( $slot ) ) { @flock( $slot, LOCK_UN ); @fclose( $slot ); }
    if ( is_resource( $variant_lock ) ) { @flock( $variant_lock, LOCK_UN ); @fclose( $variant_lock ); }
}
qmf_serve( $target, qmf_mime( $format ), $method );

function qmf_output_dimensions( int $source_w, int $source_h, int $width, int $height, bool $crop ): array {
    if ( $crop && $height > 0 ) { return array( min( $width, $source_w ), min( $height, $source_h ) ); }
    $ratio = min( $width / $source_w, $height > 0 ? $height / $source_h : PHP_FLOAT_MAX, 1 );
    if ( PHP_FLOAT_MAX === $ratio ) { $ratio = min( $width / $source_w, 1 ); }
    return array( max( 1, (int) round( $source_w * $ratio ) ), max( 1, (int) round( $source_h * $ratio ) ) );
}

function qmf_crop_box( int $source_w, int $source_h, int $out_w, int $out_h, int $focal_x, int $focal_y ): array {
    $target_ratio = $out_w / max( 1, $out_h );
    $source_ratio = $source_w / max( 1, $source_h );
    if ( $source_ratio > $target_ratio ) { $crop_h = $source_h; $crop_w = max( 1, (int) round( $source_h * $target_ratio ) ); }
    else { $crop_w = $source_w; $crop_h = max( 1, (int) round( $source_w / $target_ratio ) ); }
    $center_x = ( $focal_x / 100 ) * $source_w;
    $center_y = ( $focal_y / 100 ) * $source_h;
    $x = max( 0, min( $source_w - $crop_w, (int) round( $center_x - $crop_w / 2 ) ) );
    $y = max( 0, min( $source_h - $crop_h, (int) round( $center_y - $crop_h / 2 ) ) );
    return array( $x, $y, $crop_w, $crop_h );
}

function qmf_generate_imagick( string $source, string $temp, int $source_w, int $source_h, int $out_w, int $out_h, bool $crop, int $quality, string $format, int $focal_x, int $focal_y, array $settings ): bool {
    if ( ! class_exists( 'Imagick' ) ) { return false; }
    try {
        $image = new Imagick( $source );
        if ( method_exists( $image, 'setIteratorIndex' ) ) { $image->setIteratorIndex( 0 ); }
        if ( method_exists( $image, 'autoOrientImage' ) ) { $image->autoOrientImage(); }
        if ( $crop && $out_h > 0 ) {
            list( $x, $y, $crop_w, $crop_h ) = qmf_crop_box( $source_w, $source_h, $out_w, $out_h, $focal_x, $focal_y );
            $image->cropImage( $crop_w, $crop_h, $x, $y );
            $image->setImagePage( 0, 0, 0, 0 );
            $image->resizeImage( $out_w, $out_h, Imagick::FILTER_LANCZOS, 1, false );
        } else {
            $image->resizeImage( $out_w, $out_h, Imagick::FILTER_LANCZOS, 1, true );
        }
        $image->setImageFormat( 'jpeg' === $format ? 'JPEG' : strtoupper( $format ) );
        $image->setImageCompressionQuality( $quality );
        if ( 'jpeg' === $format && ! empty( $settings['progressive_jpeg'] ) && defined( 'Imagick::INTERLACE_PLANE' ) ) { $image->setInterlaceScheme( Imagick::INTERLACE_PLANE ); }
        if ( 'png' === $format && ! empty( $settings['interlaced_png'] ) && defined( 'Imagick::INTERLACE_PNG' ) ) { $image->setInterlaceScheme( Imagick::INTERLACE_PNG ); }
        if ( method_exists( $image, 'stripImage' ) ) { $image->stripImage(); }
        $ok = $image->writeImage( $temp );
        $image->clear();
        $image->destroy();
        return (bool) $ok;
    } catch ( Throwable $e ) {
        @unlink( $temp );
        return false;
    }
}

function qmf_generate_gd( string $source, string $temp, string $source_mime, int $source_w, int $source_h, int $out_w, int $out_h, bool $crop, int $quality, string $format, int $focal_x, int $focal_y, array $settings ): bool {
    $loader = match ( $source_mime ) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
        default      => '',
    };
    if ( '' === $loader || ! function_exists( $loader ) || ! function_exists( 'imagecreatetruecolor' ) ) { return false; }
    $src = @$loader( $source );
    if ( ! $src ) { return false; }
    $dst = imagecreatetruecolor( $out_w, $out_h );
    if ( ! $dst ) { imagedestroy( $src ); return false; }
    if ( in_array( $format, array( 'png', 'webp', 'avif' ), true ) ) {
        imagealphablending( $dst, false ); imagesavealpha( $dst, true );
        $transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 ); imagefilledrectangle( $dst, 0, 0, $out_w, $out_h, $transparent );
    }
    if ( $crop && $out_h > 0 ) { list( $x, $y, $crop_w, $crop_h ) = qmf_crop_box( $source_w, $source_h, $out_w, $out_h, $focal_x, $focal_y ); }
    else { $x = 0; $y = 0; $crop_w = $source_w; $crop_h = $source_h; }
    $ok = imagecopyresampled( $dst, $src, 0, 0, $x, $y, $out_w, $out_h, $crop_w, $crop_h );
    if ( $ok && ( ( 'jpeg' === $format && ! empty( $settings['progressive_jpeg'] ) ) || ( 'png' === $format && ! empty( $settings['interlaced_png'] ) ) ) ) { imageinterlace( $dst, true ); }
    if ( $ok ) {
        $ok = match ( $format ) {
            'webp' => function_exists( 'imagewebp' ) ? imagewebp( $dst, $temp, $quality ) : false,
            'avif' => function_exists( 'imageavif' ) ? imageavif( $dst, $temp, $quality ) : false,
            'png'  => imagepng( $dst, $temp, max( 0, min( 9, 9 - (int) round( $quality / 100 * 9 ) ) ) ),
            default => imagejpeg( $dst, $temp, $quality ),
        };
    }
    imagedestroy( $dst ); imagedestroy( $src );
    return (bool) $ok;
}

function qmf_mime( string $format ): string {
    return match ( $format ) { 'avif' => 'image/avif', 'webp' => 'image/webp', 'png' => 'image/png', default => 'image/jpeg' };
}

function qmf_serve( string $path, string $mime, string $method ): never {
    if ( ! is_readable( $path ) ) { qmf_fail( 404, 'QMediaFlow derivative is unavailable.' ); }
    $size = (int) @filesize( $path ); $mtime = (int) @filemtime( $path );
    http_response_code( 200 );
    header( 'Content-Type: ' . $mime );
    header( 'Content-Length: ' . $size );
    header( 'Cache-Control: public, max-age=31536000, immutable' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-QMediaFlow-Gateway: 1' );
    header( sprintf( 'ETag: W/"%x-%x"', $size, $mtime ) );
    if ( 'HEAD' !== $method ) { readfile( $path ); }
    exit;
}

function qmf_fallback( string $private_dir, array $manifest, string $reason ): never {
    $url = trim( (string) ( $manifest['source_url'] ?? '' ) );
    qmf_metric( $private_dir, 'gateway_fallback' );
    if ( '' === $url || ! preg_match( '#^(?:https?://|/)#i', $url ) ) { qmf_fail( 503, 'QMediaFlow source fallback is unavailable.' ); }
    http_response_code( 302 );
    header( 'Location: ' . $url );
    header( 'Cache-Control: no-store, max-age=0' );
    header( 'X-QMediaFlow-Fallback: ' . preg_replace( '/[^a-z0-9_-]/', '', strtolower( $reason ) ) );
    header( 'X-Content-Type-Options: nosniff' );
    exit;
}

function qmf_fail( int $status, string $message ): never {
    http_response_code( $status );
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Cache-Control: no-store, max-age=0' );
    header( 'X-Content-Type-Options: nosniff' );
    echo htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    exit;
}

function qmf_metric( string $private_dir, string $name ): void { qmf_counter_add( $private_dir, $name, 1 ); }
function qmf_counter_add( string $private_dir, string $name, int $delta ): void {
    $path = $private_dir . '/gateway-metrics.json';
    $lock = @fopen( $private_dir . '/gateway-metrics.lock', 'c' );
    if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) { if ( is_resource( $lock ) ) @fclose( $lock ); return; }
    try {
        $data = array( 'version' => 1, 'counters' => array(), 'timings' => array(), 'updated_at' => 0 );
        if ( is_readable( $path ) ) { $raw = @file_get_contents( $path ); $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null; if ( is_array( $decoded ) ) $data = $decoded; }
        $data['counters'][ $name ] = (int) ( $data['counters'][ $name ] ?? 0 ) + $delta;
        $data['updated_at'] = time();
        @file_put_contents( $path, json_encode( $data, JSON_UNESCAPED_SLASHES ), LOCK_EX ); @chmod( $path, 0600 );
    } finally { @flock( $lock, LOCK_UN ); @fclose( $lock ); }
}
function qmf_timing( string $private_dir, string $name, float $ms ): void {
    $path = $private_dir . '/gateway-metrics.json'; $lock = @fopen( $private_dir . '/gateway-metrics.lock', 'c' );
    if ( ! is_resource( $lock ) || ! @flock( $lock, LOCK_EX ) ) { if ( is_resource( $lock ) ) @fclose( $lock ); return; }
    try {
        $data = array( 'version' => 1, 'counters' => array(), 'timings' => array(), 'updated_at' => 0 );
        if ( is_readable( $path ) ) { $raw = @file_get_contents( $path ); $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null; if ( is_array( $decoded ) ) $data = $decoded; }
        $timing = is_array( $data['timings'][ $name ] ?? null ) ? $data['timings'][ $name ] : array( 'count' => 0, 'total_ms' => 0, 'max_ms' => 0, 'buckets' => array() );
        ++$timing['count']; $timing['total_ms'] += $ms; $timing['max_ms'] = max( $timing['max_ms'], $ms );
        $bucket = 'inf'; foreach ( array( 10,25,50,100,250,500,1000,2500,5000,10000 ) as $limit ) { if ( $ms <= $limit ) { $bucket = (string) $limit; break; } }
        $timing['buckets'][ $bucket ] = (int) ( $timing['buckets'][ $bucket ] ?? 0 ) + 1; $data['timings'][ $name ] = $timing; $data['updated_at'] = time();
        @file_put_contents( $path, json_encode( $data, JSON_UNESCAPED_SLASHES ), LOCK_EX ); @chmod( $path, 0600 );
    } finally { @flock( $lock, LOCK_UN ); @fclose( $lock ); }
}
