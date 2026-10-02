<?php
namespace MediaFlow;

/** Registers roadmap operational commands under the canonical qmediaflow CLI. */
final class Ops_CLI {
    public static function register(): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
        \WP_CLI::add_command( 'qmediaflow metrics', array( self::class, 'metrics' ) );
        \WP_CLI::add_command( 'qmediaflow queue status', array( self::class, 'queue_status' ) );
        \WP_CLI::add_command( 'qmediaflow queue run', array( self::class, 'queue_run' ) );
        \WP_CLI::add_command( 'qmediaflow health', array( self::class, 'health' ) );
        \WP_CLI::add_command( 'qmediaflow distribution health', array( self::class, 'distribution_health' ) );
        \WP_CLI::add_command( 'qmediaflow distribution run', array( self::class, 'distribution_run' ) );
        \WP_CLI::add_command( 'qmediaflow thumbnails audit', array( self::class, 'thumbnail_audit' ) );
        \WP_CLI::add_command( 'qmediaflow thumbnails migrate', array( self::class, 'thumbnail_migrate' ) );
        \WP_CLI::add_command( 'qmediaflow thumbnails rollback', array( self::class, 'thumbnail_rollback' ) );
        \WP_CLI::add_command( 'qmediaflow thumbnails purge', array( self::class, 'thumbnail_purge' ) );
    }

    public static function metrics( array $args = array(), array $assoc = array() ): void {
        $features = Features::instance();
        if ( isset( $assoc['reset'] ) ) {
            $features->telemetry()->reset();
            \WP_CLI::success( 'QMediaFlow metrics reset.' );
            return;
        }
        self::json( $features->telemetry()->snapshot() );
    }

    public static function queue_status( array $args = array(), array $assoc = array() ): void {
        self::json( Features::instance()->queue()->status( absint( $assoc['scan-limit'] ?? 5000 ) ?: 5000 ) );
    }

    public static function queue_run( array $args = array(), array $assoc = array() ): void {
        $limit = max( 1, min( 100, absint( $assoc['limit'] ?? Runtime_Config::queue_batch() ) ) );
        $budget = max( 1.0, min( 60.0, (float) ( $assoc['time-budget'] ?? Runtime_Config::queue_time_budget() ) ) );
        self::json( Features::instance()->queue()->process( $limit, $budget ) );
    }

    public static function health( array $args = array(), array $assoc = array() ): void {
        self::json( Features::instance()->health()->run( isset( $assoc['deep'] ) ) );
    }

    public static function distribution_health( array $args = array(), array $assoc = array() ): void {
        self::json( Features::instance()->distribution()->health() );
    }

    public static function distribution_run( array $args = array(), array $assoc = array() ): void {
        self::json( Features::instance()->distribution()->process_queue( Plugin::instance()->paths()->site_id(), max( 1, min( 50, absint( $assoc['limit'] ?? 4 ) ) ) ) );
    }

    public static function thumbnail_audit( array $args = array(), array $assoc = array() ): void {
        self::json( Features::instance()->thumbnail_migrator()->audit( absint( $assoc['limit'] ?? 100 ), absint( $assoc['after'] ?? 0 ) ) );
    }

    public static function thumbnail_migrate( array $args = array(), array $assoc = array() ): void {
        $dry_run = ! isset( $assoc['yes'] );
        if ( $dry_run ) {
            \WP_CLI::warning( 'Dry run only. Add --yes after reviewing the audit to quarantine eligible physical thumbnails.' );
        }
        $result = Features::instance()->thumbnail_migrator()->migrate( absint( $assoc['limit'] ?? 25 ), absint( $assoc['after'] ?? 0 ), $dry_run );
        self::json( $result );
        if ( isset( $result['error'] ) ) { \WP_CLI::error( (string) $result['error'] ); }
    }

    public static function thumbnail_rollback( array $args = array(), array $assoc = array() ): void {
        $id = absint( $args[0] ?? 0 );
        if ( $id < 1 ) { \WP_CLI::error( 'Supply an attachment ID.' ); }
        self::json( Features::instance()->thumbnail_migrator()->rollback( $id ) );
    }

    public static function thumbnail_purge( array $args = array(), array $assoc = array() ): void {
        $dry_run = ! isset( $assoc['yes'] );
        if ( $dry_run ) { \WP_CLI::warning( 'Dry run only. Add --yes to permanently delete old quarantined files.' ); }
        self::json( Features::instance()->thumbnail_migrator()->purge_quarantine( absint( $assoc['limit'] ?? 100 ), absint( $assoc['retention-days'] ?? 30 ), $dry_run ) );
    }

    private static function json( array $data ): void {
        \WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }
}
