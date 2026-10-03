<?php
/**
 * QMediaFlow standalone edge wrapper.
 *
 * Keeps the cold gateway WordPress-free while registering one tiny shutdown hook
 * that mirrors successfully published derivatives into the existing filesystem
 * distribution queue. The real encoder/gateway remains qmediaflow-gateway.php.
 */

declare(strict_types=1);

$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
$request_path = (string) parse_url( $request_uri, PHP_URL_PATH );
$content_root = dirname( __DIR__, 2 );
$cache_root = $content_root . '/image-cache';
$private_root = $content_root . '/.mediaflow-private';
$config_file = $cache_root . '/__qmediaflow-gateway-config.php';
$config = array();
if ( is_readable( $config_file ) ) {
    $loaded = include $config_file;
    if ( is_array( $loaded ) ) { $config = $loaded; }
}
if ( ! empty( $config['cache_root'] ) ) { $cache_root = rtrim( (string) $config['cache_root'], '/\\' ); }
if ( ! empty( $config['private_root'] ) ) { $private_root = rtrim( (string) $config['private_root'], '/\\' ); }

$needle = '/image-cache/';
$pos = strpos( $request_path, $needle );
$relative = false === $pos ? '' : ltrim( substr( $request_path, $pos + strlen( $needle ) ), '/' );
$match = array();
$valid = '' !== $relative && preg_match(
    '#^(?:sites/(?<site>[1-9][0-9]{0,9})/)?[0-9]{2}/[0-9]{2}/[0-9]+/[A-Za-z0-9_-]{4,32}/[a-f0-9]{12}/w[0-9]+-h[0-9]+-c[01]-q[0-9]+(?:-fx[0-9]{1,3}-fy[0-9]{1,3})?-s[a-f0-9]{32}\.(?<ext>avif|webp|jpe?g|png)$#i',
    $relative,
    $match
);

if ( $valid && ! empty( $config['object_store'] ) && in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
    $state = array(
        'cache_root'   => $cache_root,
        'private_root' => $private_root,
        'relative'     => $relative,
        'site_id'      => isset( $match['site'] ) && '' !== (string) $match['site'] ? (int) $match['site'] : 0,
        'extension'    => strtolower( (string) ( $match['ext'] ?? '' ) ),
    );

    register_shutdown_function( static function () use ( $state ): void {
        $status = http_response_code();
        if ( 0 !== $status && 200 !== $status ) { return; }

        $target = rtrim( (string) $state['cache_root'], '/\\' ) . '/' . (string) $state['relative'];
        if ( ! is_file( $target ) || ! is_readable( $target ) || (int) @filesize( $target ) < 1 ) { return; }
        $normalized_target = str_replace( '\\', '/', $target );
        $normalized_root = rtrim( str_replace( '\\', '/', (string) $state['cache_root'] ), '/' );
        if ( ! str_starts_with( $normalized_target, $normalized_root . '/' ) ) { return; }

        $site_id = (int) $state['site_id'];
        $private_dir = rtrim( (string) $state['private_root'], '/\\' ) . ( $site_id > 0 ? '/sites/' . $site_id : '' );
        $queue_root = $private_dir . '/distribution-queue';
        $key = ltrim( (string) $state['relative'], '/' );
        $identity = hash( 'sha256', $key . '|' . (string) @filemtime( $target ) . '|' . (string) @filesize( $target ) );
        $job_path = $queue_root . '/' . substr( $identity, 0, 2 ) . '/' . substr( $identity, 2, 2 ) . '/' . $identity . '.json';
        $job_dir = dirname( $job_path );
        if ( ! is_dir( $job_dir ) && ! @mkdir( $job_dir, 0700, true ) && ! is_dir( $job_dir ) ) { return; }

        $mime = match ( (string) $state['extension'] ) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
        $now = time();
        $job = array(
            'path'        => $normalized_target,
            'key'         => $key,
            'mime'        => $mime,
            'kind'        => 'derivative',
            'identity'    => $identity,
            'attempts'    => 0,
            'queued_at'   => $now,
            'updated_at'  => $now,
            'retry_after' => 0,
        );

        if ( ! is_readable( $job_path ) ) {
            $handle = @fopen( $job_path, 'x' );
            if ( is_resource( $handle ) ) {
                $json = json_encode( $job, JSON_UNESCAPED_SLASHES );
                if ( ! is_string( $json ) || false === @fwrite( $handle, $json ) ) { @fclose( $handle ); @unlink( $job_path ); return; }
                @fclose( $handle );
                @chmod( $job_path, 0600 );
            }
        }

        if ( is_readable( $job_path ) ) {
            if ( ! is_dir( $private_dir ) ) { @mkdir( $private_dir, 0700, true ); }
            @touch( $private_dir . '/distribution-pending' );
            @chmod( $private_dir . '/distribution-pending', 0600 );
        }
    } );
}

require __DIR__ . '/qmediaflow-gateway.php';
