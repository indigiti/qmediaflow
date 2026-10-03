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
    private static array $gateway_synced = array();

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
     * Publish standalone-gateway runtime data only when semantic state changes.
     * Normal WordPress requests do one tiny marker read and zero writes.
     */
    public static function sync_gateway_config( Paths $paths ): bool {
        $cache_dir    = rtrim( $paths->cache_dir(), '/\\' );
        $private_dir  = rtrim( $paths->private_dir(), '/\\' );
        $cache_root   = $paths->site_id() ? dirname( dirname( $cache_dir ) ) : $cache_dir;
        $private_root = $paths->site_id() ? dirname( dirname( $private_dir ) ) : $private_dir;
        if ( ! is_dir( $cache_root ) && ! wp_mkdir_p( $cache_root ) ) { return false; }
        if ( ! is_dir( $private_root ) && ! wp_mkdir_p( $private_root ) ) { return false; }

        $front = self::front_controller_path();
        $config = array(
            'version'           => 3,
            'routing_schema'    => defined( 'QMEDIAFLOW_ROUTING_SCHEMA_VERSION' ) ? (string) QMEDIAFLOW_ROUTING_SCHEMA_VERSION : '5',
            'front_controller'  => $front,
            'cache_root'        => $cache_root,
            'private_root'      => $private_root,
            'max_source_pixels' => (int) MEDIAFLOW_MAX_SOURCE_PIXELS,
            'max_output_pixels' => (int) MEDIAFLOW_MAX_OUTPUT_PIXELS,
            'max_generators'    => self::generator_limit(),
            'telemetry'         => self::telemetry_enabled(),
        );
        $state = hash( 'sha256', serialize( $config ) );
        $runtime_key = $cache_root . '|' . $state;
        if ( isset( self::$gateway_synced[ $runtime_key ] ) ) { return true; }

        $config_path = $cache_root . '/__qmediaflow-gateway-config.php';
        $state_path  = $private_root . '/.qmediaflow-gateway-state';
        $htaccess    = $cache_root . '/.htaccess';
        $installed   = is_readable( $state_path ) ? trim( (string) @file_get_contents( $state_path ) ) : '';
        if ( is_readable( $config_path ) && is_readable( $htaccess ) && hash_equals( $state, $installed ) ) {
            self::$gateway_synced[ $runtime_key ] = true;
            return true;
        }

        $published = $config;
        $published['generated_at'] = time();
        $temp = $config_path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        $php  = "<?php\nreturn " . var_export( $published, true ) . ";\n";
        if ( false === @file_put_contents( $temp, $php ) ) { return false; }
        @chmod( $temp, 0644 );
        if ( ! @rename( $temp, $config_path ) ) { @unlink( $temp ); return false; }
        @chmod( $config_path, 0644 );

        if ( ! self::ensure_extended_route( $cache_root, $front ) ) { return false; }
        $state_temp = $state_path . '.tmp-' . getmypid() . '-' . substr( $state, 0, 8 );
        if ( false === @file_put_contents( $state_temp, $state . "\n" ) ) { return false; }
        @chmod( $state_temp, 0600 );
        if ( ! @rename( $state_temp, $state_path ) ) { @unlink( $state_temp ); return false; }
        @chmod( $state_path, 0600 );
        self::$gateway_synced[ $runtime_key ] = true;
        return true;
    }

    /**
     * Historical routing remains intact. This extension block adds focal tokens
     * and denies direct HTTP access to the gateway path-discovery config.
     */
    public static function ensure_extended_route( string $cache_root, ?string $front = null ): bool {
        $htaccess = rtrim( $cache_root, '/\\' ) . '/.htaccess';
        $existing = is_readable( $htaccess ) ? (string) @file_get_contents( $htaccess ) : '';
        $existing = str_replace( array( "\r\n", "\r" ), "\n", $existing );
        $begin = preg_quote( self::ROUTE_BEGIN, '/' );
        $end   = preg_quote( self::ROUTE_END, '/' );
        $existing = preg_replace( '/(?:^|\n)' . $begin . '.*?' . $end . '(?=\n|$)/s', '', $existing );
        $existing = is_string( $existing ) ? trim( $existing ) : '';

        $front = null === $front ? self::front_controller_path() : $front;
        $block = self::ROUTE_BEGIN . "\n"
            . "<Files \"__qmediaflow-gateway-config.php\">\n"
            . "  <IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "  </IfModule>\n"
            . "</Files>\n"
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

    private static function front_controller_path(): string {
        $front = defined( 'QMEDIAFLOW_FRONT_CONTROLLER_PATH' ) ? (string) QMEDIAFLOW_FRONT_CONTROLLER_PATH : ( defined( 'MEDIAFLOW_FRONT_CONTROLLER_PATH' ) ? (string) MEDIAFLOW_FRONT_CONTROLLER_PATH : '/wp-content/plugins/qmediaflow/qmediaflow-gateway.php' );
        $parts = array_filter( explode( '/', trim( $front, '/' ) ), static fn( string $part ): bool => '' !== $part );
        $parts = array_map( static fn( string $part ): string => rawurlencode( rawurldecode( $part ) ), $parts );
        return '/' . implode( '/', $parts );
    }
}
