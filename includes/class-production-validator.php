<?php
namespace MediaFlow;

/** Release-gate diagnostics and CLI entry points for staging/production validation. */
final class Production_Validator {
    private static bool $registered = false;

    public static function boot(): void {
        if ( self::$registered || ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
        self::$registered = true;
        \WP_CLI::add_command( 'qmediaflow validate production', array( self::class, 'cli_validate' ) );
        \WP_CLI::add_command( 'qmediaflow focal sync', array( self::class, 'cli_focal_sync' ) );
        \WP_CLI::add_command( 'qmediaflow intelligence analyze', array( self::class, 'cli_intelligence_analyze' ) );
        \WP_CLI::add_command( 'qmediaflow intelligence show', array( self::class, 'cli_intelligence_show' ) );
        \WP_CLI::add_command( 'qmediaflow predictive status', array( self::class, 'cli_predictive_status' ) );
        \WP_CLI::add_command( 'qmediaflow predictive warm', array( self::class, 'cli_predictive_warm' ) );
    }

    /** @return array<string,mixed> */
    public static function run( bool $deep = false ): array {
        $features = Features::instance();
        $health = $features->health()->run( $deep );
        $metrics = $features->telemetry()->snapshot();
        $checks = array();

        self::check( $checks, 'cache_writable', ! empty( $health['storage']['cache_writable'] ), 'Public derivative cache must be writable.' );
        self::check( $checks, 'private_writable', ! empty( $health['storage']['private_writable'] ), 'Private runtime state must be writable.' );
        self::check( $checks, 'signing_key', ! empty( $health['storage']['signing_key'] ), 'Immutable URL signing key must be available.' );
        self::check( $checks, 'gateway_file', ! empty( $health['routing']['gateway_file'] ), 'Standalone encoder gateway must be readable.' );
        self::check( $checks, 'edge_wrapper_file', ! empty( $health['routing']['edge_wrapper_file'] ), 'Standalone edge wrapper must be readable.' );
        self::check( $checks, 'edge_routed', ! empty( $health['routing']['edge_routed'] ), 'Cold derivative routes must enter through qmediaflow-edge.php.' );
        self::check( $checks, 'gateway_config', ! empty( $health['routing']['gateway_config'] ), 'Standalone gateway runtime config must be published.' );
        self::check( $checks, 'focal_route', ! empty( $health['routing']['focal_cold_route'] ), 'Focal cold-miss route must be installed.' );
        self::check( $checks, 'immutable_headers', ! empty( $health['routing']['immutable_headers'] ), 'Warm derivatives need immutable cache headers.' );
        self::check( $checks, 'pending_no_store', ! empty( $health['routing']['pending_no_store'] ), 'Cold fallback responses must not be cached.' );
        self::check( $checks, 'webp_encoder', ! empty( $health['encoders']['webp'] ), 'WebP support is required for the default delivery path.' );

        $queue = is_array( $health['workers']['queue'] ?? null ) ? $health['workers']['queue'] : array();
        $failed = absint( $queue['failed'] ?? 0 );
        self::check( $checks, 'queue_failed_jobs', 0 === $failed, 0 === $failed ? 'No failed derivative jobs.' : $failed . ' derivative jobs are failed.' );
        $oldest = absint( $queue['oldest_seconds'] ?? 0 );
        if ( $oldest > 900 ) { self::check( $checks, 'queue_age', false, 'Oldest queued derivative is ' . $oldest . ' seconds old.' ); }
        elseif ( $oldest > 120 ) { self::warn( $checks, 'queue_age', 'Oldest queued derivative is ' . $oldest . ' seconds old.' ); }
        else { self::pass( $checks, 'queue_age', 'Derivative queue age is within the release target.' ); }

        if ( Runtime_Config::object_store_enabled() && $deep ) {
            $store = is_array( $health['distribution']['object_store'] ?? null ) ? $health['distribution']['object_store'] : array();
            self::check( $checks, 'object_store_health', ! empty( $store['ok'] ), (string) ( $store['message'] ?? 'Object-store health check failed.' ) );
        } elseif ( Runtime_Config::object_store_enabled() ) {
            self::warn( $checks, 'object_store_health', 'Object storage is configured; run with --deep to verify credentials/network.' );
        }

        $generation = self::generation_latency( $metrics );
        if ( null === $generation ) {
            self::warn( $checks, 'generation_p95', 'No generation latency sample is available yet.' );
        } elseif ( $generation > 2500 ) {
            self::warn( $checks, 'generation_p95', 'Observed generation p95 bucket is ' . $generation . ' ms; tune encoders/workers on staging.' );
        } else {
            self::pass( $checks, 'generation_p95', 'Observed generation p95 bucket is ' . $generation . ' ms.' );
        }

        $inventory = self::cache_inventory( 20000 );
        $predictive = Predictive_Cache::status( 1000 );
        $fails = count( array_filter( $checks, static fn( array $c ): bool => 'fail' === $c['status'] ) );
        $warnings = count( array_filter( $checks, static fn( array $c ): bool => 'warn' === $c['status'] ) );

        return array(
            'version'          => QMEDIAFLOW_VERSION,
            'production_ready' => 0 === $fails,
            'status'           => $fails > 0 ? 'fail' : ( $warnings > 0 ? 'warn' : 'pass' ),
            'checks'           => $checks,
            'health'           => $health,
            'metrics'          => $metrics,
            'cache_inventory'  => $inventory,
            'predictive'       => $predictive,
            'load_validation'  => array(
                'executed' => false,
                'tool'     => 'tools/qmediaflow-load-test.py',
                'note'     => 'Concurrent HTTP validation must be run against the real staging/production web server; this CLI does not fabricate those results.',
            ),
        );
    }

    public static function cli_validate( array $args = array(), array $assoc = array() ): void {
        self::json( self::run( isset( $assoc['deep'] ) ) );
    }

    public static function cli_focal_sync( array $args = array(), array $assoc = array() ): void {
        self::json( Release_Hardening::sync_focal_batch( absint( $assoc['limit'] ?? 250 ) ?: 250, absint( $assoc['offset'] ?? 0 ) ) );
    }

    public static function cli_intelligence_analyze( array $args = array(), array $assoc = array() ): void {
        $id = absint( $args[0] ?? 0 );
        if ( $id < 1 ) { \WP_CLI::error( 'Supply an image attachment ID.' ); }
        $data = Image_Intelligence::analyze( $id );
        if ( ! $data ) { \WP_CLI::error( 'Image analysis could not be produced.' ); }
        self::json( $data );
    }

    public static function cli_intelligence_show( array $args = array(), array $assoc = array() ): void {
        $id = absint( $args[0] ?? 0 );
        if ( $id < 1 ) { \WP_CLI::error( 'Supply an image attachment ID.' ); }
        $data = Image_Intelligence::runtime( $id );
        if ( ! $data ) { \WP_CLI::error( 'No stored image intelligence exists for this attachment.' ); }
        self::json( $data );
    }

    public static function cli_predictive_status( array $args = array(), array $assoc = array() ): void {
        self::json( Predictive_Cache::status( absint( $assoc['limit'] ?? 1000 ) ?: 1000 ) );
    }

    public static function cli_predictive_warm( array $args = array(), array $assoc = array() ): void {
        $id = absint( $args[0] ?? 0 );
        if ( $id < 1 ) { \WP_CLI::error( 'Supply a published post/product ID.' ); }
        self::json( array( 'post_id' => $id, 'jobs_enqueued' => Predictive_Cache::force_warm( $id ) ) );
    }

    private static function generation_latency( array $metrics ): ?int {
        $derived = is_array( $metrics['derived'] ?? null ) ? $metrics['derived'] : array();
        $values = array();
        foreach ( array( 'gateway_generation_ms', 'generation_ms', 'queue_generation_ms' ) as $key ) {
            $value = $derived[$key]['p95_ms'] ?? null;
            if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) { $values[] = (int) $value; }
            elseif ( is_string( $value ) && str_starts_with( $value, '>' ) ) { $values[] = 10001; }
        }
        return $values ? max( $values ) : null;
    }

    /** @return array{files:int,bytes:int,truncated:bool,scan_limit:int} */
    private static function cache_inventory( int $limit ): array {
        $root = Plugin::instance()->paths()->cache_dir();
        $files = 0;
        $bytes = 0;
        if ( is_dir( $root ) ) {
            try {
                $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
                foreach ( $iterator as $file ) {
                    if ( ! $file->isFile() ) { continue; }
                    $name = $file->getFilename();
                    if ( str_starts_with( $name, '.' ) || str_ends_with( $name, '.php' ) ) { continue; }
                    ++$files;
                    $bytes += max( 0, (int) $file->getSize() );
                    if ( $files >= $limit ) { break; }
                }
            } catch ( \Throwable $e ) {
                // Inventory is advisory and must not fail the release validator.
            }
        }
        return array( 'files' => $files, 'bytes' => $bytes, 'truncated' => $files >= $limit, 'scan_limit' => $limit );
    }

    private static function check( array &$checks, string $name, bool $condition, string $detail ): void {
        $checks[] = array( 'name' => $name, 'status' => $condition ? 'pass' : 'fail', 'detail' => $detail );
    }

    private static function warn( array &$checks, string $name, string $detail ): void {
        $checks[] = array( 'name' => $name, 'status' => 'warn', 'detail' => $detail );
    }

    private static function pass( array &$checks, string $name, string $detail ): void {
        $checks[] = array( 'name' => $name, 'status' => 'pass', 'detail' => $detail );
    }

    private static function json( array $data ): void {
        \WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
}
