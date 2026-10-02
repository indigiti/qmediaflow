<?php
namespace MediaFlow;

/** Minimal S3-compatible SigV4 adapter for generated derivative mirroring. */
final class S3_Store {
    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $access_key;
    private string $secret_key;
    private bool $path_style;

    public function __construct() {
        $this->endpoint   = rtrim( (string) ( defined( 'QMEDIAFLOW_S3_ENDPOINT' ) ? QMEDIAFLOW_S3_ENDPOINT : '' ), '/' );
        $this->bucket     = trim( (string) ( defined( 'QMEDIAFLOW_S3_BUCKET' ) ? QMEDIAFLOW_S3_BUCKET : '' ) );
        $this->region     = trim( (string) ( defined( 'QMEDIAFLOW_S3_REGION' ) ? QMEDIAFLOW_S3_REGION : 'us-east-1' ) );
        $this->access_key = (string) ( defined( 'QMEDIAFLOW_S3_ACCESS_KEY' ) ? QMEDIAFLOW_S3_ACCESS_KEY : '' );
        $this->secret_key = (string) ( defined( 'QMEDIAFLOW_S3_SECRET_KEY' ) ? QMEDIAFLOW_S3_SECRET_KEY : '' );
        $this->path_style = ! defined( 'QMEDIAFLOW_S3_PATH_STYLE' ) || (bool) QMEDIAFLOW_S3_PATH_STYLE;
    }

    public function configured(): bool {
        return '' !== $this->endpoint && '' !== $this->bucket && '' !== $this->access_key && '' !== $this->secret_key;
    }

    public function put( string $key, string $path, string $mime ): bool {
        if ( ! $this->configured() || ! is_readable( $path ) ) { return false; }
        $body = @file_get_contents( $path );
        if ( ! is_string( $body ) ) { return false; }
        $response = $this->request( 'PUT', $key, $body, $mime );
        return $this->successful( $response );
    }

    public function head( string $key ): bool {
        if ( ! $this->configured() ) { return false; }
        return $this->successful( $this->request( 'HEAD', $key, '', 'application/octet-stream' ) );
    }

    public function delete( string $key ): bool {
        if ( ! $this->configured() ) { return false; }
        $response = $this->request( 'DELETE', $key, '', 'application/octet-stream' );
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        return in_array( $code, array( 200, 202, 204, 404 ), true );
    }

    public function health(): array {
        if ( ! $this->configured() ) {
            return array( 'configured' => false, 'ok' => false, 'message' => 'S3-compatible object storage is not configured.' );
        }
        $key = 'qmediaflow-health/' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 20 ) . '.txt';
        $tmp = wp_tempnam( 'qmediaflow-s3-health' );
        if ( ! $tmp ) { return array( 'configured' => true, 'ok' => false, 'message' => 'Could not allocate a health-check temp file.' ); }
        @file_put_contents( $tmp, 'qmediaflow' );
        $put = $this->put( $key, $tmp, 'text/plain' );
        $head = $put ? $this->head( $key ) : false;
        $deleted = $put ? $this->delete( $key ) : false;
        @unlink( $tmp );
        return array(
            'configured' => true,
            'ok'         => $put && $head && $deleted,
            'put'        => $put,
            'head'       => $head,
            'delete'     => $deleted,
            'message'    => $put && $head && $deleted ? 'S3-compatible write/read/delete cycle succeeded.' : 'S3-compatible health cycle failed.',
        );
    }

    public function public_base_url(): string {
        if ( defined( 'QMEDIAFLOW_OBJECT_PUBLIC_BASE_URL' ) ) {
            return rtrim( (string) QMEDIAFLOW_OBJECT_PUBLIC_BASE_URL, '/' );
        }
        return '';
    }

    private function request( string $method, string $key, string $body, string $content_type ) {
        $key = ltrim( str_replace( array( '..', '\\' ), '', $key ), '/' );
        $url = $this->object_url( $key );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return new \WP_Error( 'qmediaflow_s3_url', 'Invalid object-store endpoint.' ); }

        $host = (string) $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
        $canonical_uri = (string) ( $parts['path'] ?? '/' );
        $payload_hash = hash( 'sha256', $body );
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date = substr( $amz_date, 0, 8 );
        $canonical_headers = 'content-type:' . trim( $content_type ) . "\n"
            . 'host:' . strtolower( $host ) . "\n"
            . 'x-amz-content-sha256:' . $payload_hash . "\n"
            . 'x-amz-date:' . $amz_date . "\n";
        $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonical_request = implode( "\n", array( $method, $canonical_uri, '', $canonical_headers, $signed_headers, $payload_hash ) );
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
        $k_date = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
        $k_region = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

        return wp_remote_request( $url, array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Content-Type'         => $content_type,
                'Host'                 => $host,
                'X-Amz-Content-Sha256' => $payload_hash,
                'X-Amz-Date'           => $amz_date,
                'Authorization'        => $authorization,
            ),
            'body'    => 'HEAD' === $method || 'DELETE' === $method ? null : $body,
        ) );
    }

    private function object_url( string $key ): string {
        $encoded_key = implode( '/', array_map( 'rawurlencode', array_filter( explode( '/', $key ), static fn( string $part ): bool => '' !== $part ) ) );
        $parts = wp_parse_url( $this->endpoint );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        $prefix = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) : '';
        if ( $this->path_style ) {
            return $scheme . '://' . $host . $port . $prefix . '/' . rawurlencode( $this->bucket ) . '/' . $encoded_key;
        }
        return $scheme . '://' . rawurlencode( $this->bucket ) . '.' . $host . $port . $prefix . '/' . $encoded_key;
    }

    private function successful( $response ): bool {
        if ( is_wp_error( $response ) ) { return false; }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }
}
