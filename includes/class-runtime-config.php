<?php
namespace MediaFlow;

/**
 * Feature configuration that deliberately avoids adding DB reads to delivery paths.
 * New performance features are controlled by constants/filters so existing installs
 * can upgrade without rewriting the legacy settings payload.
 */
final class Runtime_Config {
    private const ROUTE_BEGIN = '# BEGIN QMediaFlow Extensions';
    private const ROUTE_END   = '# END QMediaFlow Extensions';

    public static function enabled( string $constant, bool $default = false ): bool {
        if ( defined( $constant ) ) { return (bool) constant( $constant ); }
        return $default;
    }

    public static function integer( string $constant, int $default, int $min, int $max ): int {
        $value = defined( $constant ) ? (int) constant( $constant ) : $default;
        return max( $min, min( $max, $value ) );
    }

    public static function telemetry_enabled(): bool { return self::enabled( 'QMEDIAFLOW_TELEMETRY', true ); }
    public static function warm_enabled(): bool { return self::enabled( 'QMEDIAFLOW_CRITICAL_WARMING', true ); }
    public static function max_warm_variants(): int { return self::integer( 'QMEDIAFLOW_MAX_WARM_VARIANTS', 4, 0, 16 ); }

    /** @return int[] */
    public static function warm_widths(): array {
        $raw = defined( 'QMEDIAFLOW_WARM_WIDTHS' ) ? constant( 'QMEDIAFLOW_WARM_WIDTHS' ) : array( 480, 768, 1200, 1600 );
        if ( is_string( $raw ) ) { $raw = preg_split( '/[\s,]+/', $raw ) ?: array(); }
        $widths = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ), static fn( int $w ): bool => $w >= 64 && $w <= 8192 ) ) );
        sort( $widths, SORT_NUMERIC );
        if ( function_exists( 'apply_filters' ) ) {
            $widths = (array) apply_filters( 'qmediaflow_warm_widths', $widths );
            $widths = array_values( array_unique( array_filter( array_map( 'absint', $widths ), static fn( int $w ): bool => $w >= 64 && $w <= 8192 ) ) );
            sort( $widths, SORT_NUMERIC );
        }
        return $widths ?: array( 768, 1200 );
    }

    public static function queue_batch(): int { return self::integer( 'QMEDIAFLOW_QUEUE_BATCH', 4, 1, 32 ); }
    public static function queue_time_budget(): float {
        $value = defined( 'QMEDIAFLOW_QUEUE_TIME_BUDGET' ) ? (float) constant( 'QMEDIAFLOW_QUEUE_TIME_BUDGET' ) : 8.0;
        return max( 1.0, min( 30.0, $value ) );
    }
    public static function content_aware_enabled(): bool { return self::enabled( 'QMEDIAFLOW_CONTENT_AWARE_ENCODING', false ); }
    public static function dual_format_enabled(): bool { return self::enabled( 'QMEDIAFLOW_DUAL_FORMAT', false ); }
    public static function max_picture_candidates(): int { return self::integer( 'QMEDIAFLOW_MAX_PICTURE_CANDIDATES', 6, 2, 12 ); }
    public static function upload_workers(): int { return self::integer( 'QMEDIAFLOW_UPLOAD_WORKERS', 2, 1, 4 ); }
    public static function adaptive_concurrency_enabled(): bool { return self::enabled( 'QMEDIAFLOW_ADAPTIVE_CONCURRENCY', true ); }

    public static function generator_limit(): int {
        $hard = max( 1, min( 16, defined( 'QMEDIAFLOW_MAX_GENERATORS' ) ? (int) QMEDIAFLOW_MAX_GENERATORS : ( defined( 'MEDIAFLOW_MAX_GENERATORS' ) ? (int) MEDIAFLOW_MAX_GENERATORS : 2 ) ) );
        if ( ! self::adaptive_concurrency_enabled() ) { return $hard; }

        $memory_slots = $hard;
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' !== $raw && '-1' !== $raw ) {
            $unit = strtolower( substr( $raw, -1 ) );
            $bytes = (float) $raw * match ( $unit ) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
            $memory_slots = max( 1, (int) floor( $bytes / 201326592 ) );
        }

        $cpu_slots = $hard;
        foreach ( array( 'QMEDIAFLOW_CPU_COUNT', 'NUMBER_OF_PROCESSORS', 'NPROC' ) as $name ) {
            $value = defined( $name ) ? constant( $name ) : getenv( $name );
            if ( false !== $value && is_numeric( $value ) && (int) $value > 0 ) {
                $cpu_slots = max( 1, (int) floor( (int) $value / 2 ) );
                break;
            }
        }

        $limit = max( 1, min( $hard, $memory_slots, $cpu_slots ) );
        return function_exists( 'apply_filters' ) ? max( 1, min( $hard, (int) apply_filters( 'qmediaflow_generator_limit', $limit, $hard ) ) ) : $limit;
    }

    public static function cdn_base_url(): string {
        $url = defined( 'QMEDIAFLOW_CDN_BASE_URL' ) ? trim( (string) QMEDIAFLOW_CDN_BASE_URL ) : '';
        if ( function_exists( 'apply_filters' ) ) { $url = (string) apply_filters( 'qmediaflow_cdn_base_url', $url ); }
        return rtrim( $url, '/' );
    }

    public static function object_store_enabled(): bool {
        return defined( 'QMEDIAFLOW_S3_ENDPOINT' )
            && defined( 'QMEDIAFLOW_S3_BUCKET' )
            && defined( 'QMEDIAFLOW_S3_ACCESS_KEY' )
            && defined( 'QMEDIAFLOW_S3_SECRET_KEY' )
            && '' !== trim( (string) QMEDIAFLOW_S3_ENDPOINT )
            && '' !== trim( (string) QMEDIAFLOW_S3_BUCKET );
    }

    public static function mirror_originals(): bool { return self::enabled( 'QMEDIAFLOW_S3_MIRROR_ORIGINALS', false ); }

    /**
     * Publish a tiny executable config next to the public cache so the standalone
     * gateway can discover custom private roots and hard limits without loading WP.
     * The file contains no signing key or object-store credentials.
     */
    public static function sync_gateway_config( Paths $paths ): bool {
        $cache_dir    = rtrim( $paths->cache_dir(), '/\\' );
        $private_dir  = rtrim( $paths->private_dir(), '/\\' );
        $cache_root   = $paths->site_id() ? dirname( dirname( $cache_dir ) ) : $cache_dir;
        $private_root = $paths->site_id() ? dirname( dirname( $private_dir ) ) : $private_dir;
        if ( ! is_dir( $cache_root ) && ! wp_mkdir_p( $cache_root ) ) { return false; }

        $config = array(
            'version'           => 2,
            'cache_root'        => $cache_root,
            'private_root'      => $private_root,
            'max_source_pixels' => (int) MEDIAFLOW_MAX_SOURCE_PIXELS,
            'max_output_pixels' => (int) MEDIAFLOW_MAX_OUTPUT_PIXELS,
            'max_generators'    => self::generator_limit(),
            'telemetry'         => self::telemetry_enabled(),
            'generated_at'      => time(),
        );

        $path = $cache_root . '/__qmediaflow-gateway-config.php';
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        $php  = "<?php\nreturn " . var_export( $config, true ) . ";\n";
        if ( false === @file_put_contents( $temp, $php, LOCK_EX ) ) { return false; }
        @chmod( $temp, 0644 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return false; }
        @chmod( $path, 0644 );

        $route_ready = self::ensure_extended_route( $cache_root );
        return is_readable( $path ) && $route_ready;
    }

    /**
     * The historical MediaFlow managed rewrite block remains untouched. This small
     * additional block only adds the new focal-token shape, so legacy upgrades and
     * rollbacks do not churn or replace administrator directives.
     */
    public static function ensure_extended_route( string $cache_root ): bool {
        $htaccess = rtrim( $cache_root, '/\\' ) . '/.htaccess';
        $existing = is_readable( $htaccess ) ? (string) @file_get_contents( $htaccess ) : '';
        $existing = str_replace( array( "\r\n", "\r" ), "\n", $existing );
        $begin = preg_quote( self::ROUTE_BEGIN, '/' );
        $end   = preg_quote( self::ROUTE_END, '/' );
        $existing = preg_replace( '/(?:^|\n)' . $begin . '.*?' . $end . '(?=\n|$)/s', '', $existing );
        $existing = is_string( $existing ) ? trim( $existing ) : '';

        $front = defined( 'QMEDIAFLOW_FRONT_CONTROLLER_PATH' ) ? (string) QMEDIAFLOW_FRONT_CONTROLLER_PATH : ( defined( 'MEDIAFLOW_FRONT_CONTROLLER_PATH' ) ? (string) MEDIAFLOW_FRONT_CONTROLLER_PATH : '/wp-content/plugins/qmediaflow/qmediaflow-gateway.php' );
        $front_parts = array_filter( explode( '/', trim( $front, '/' ) ), static fn( string $part ): bool => '' !== $part );
        $front_parts = array_map( static fn( string $part ): string => rawurlencode( rawurldecode( $part ) ), $front_parts );
        $front = '/' . implode( '/', $front_parts );

        $block = self::ROUTE_BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "  RewriteRule ^(?:sites/[1-9][0-9]{0,9}/)?[0-9]{2}/[0-9]{2}/[0-9]+/[A-Za-z0-9_-]{4,32}/[a-fA-F0-9]{12}/w[0-9]+-h[0-9]+-c1-q[0-9]+-fx[0-9]{1,3}-fy[0-9]{1,3}-s[a-fA-F0-9]{32}\\.(?:avif|webp|jpe?g|png)$ " . $front . " [L]\n"
            . "</IfModule>\n"
            . self::ROUTE_END;
        $content = ( '' !== $existing ? $existing . "\n\n" : '' ) . $block . "\n";
        if ( is_readable( $htaccess ) && $content === (string) @file_get_contents( $htaccess ) ) { return true; }
        if ( false === @file_put_contents( $htaccess, $content, LOCK_EX ) ) { return false; }
        @chmod( $htaccess, 0644 );
        return is_readable( $htaccess ) && $content === (string) @file_get_contents( $htaccess );
    }
}
