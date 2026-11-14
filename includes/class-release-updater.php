<?php
namespace MediaFlow;

final class Release_Updater {
    private const URI = 'https://github.com/indigiti/qmediaflow';
    private const API = 'https://api.github.com/repos/indigiti/qmediaflow/releases';
    private const ASSET = 'qmediaflow.zip';

    public static function register(): void {
        add_filter( 'update_plugins_github.com', array( self::class, 'offer' ), 10, 4 );
        add_filter( 'upgrader_pre_download', array( self::class, 'verify_download' ), 10, 4 );
    }

    public static function offer( $update, array $data, string $file, array $locales ) {
        if ( plugin_basename( QMEDIAFLOW_FILE ) !== $file || self::URI !== ( $data['UpdateURI'] ?? '' ) ) { return $update; }
        // The release archive keeps this path stable during upgrades.
        if ( 'mediaflow/mediaflow.php' !== $file ) { return false; }
        $report = self::report();
        $release = $report['release'];
        return $release && version_compare( $release['version'], (string) ( $data['Version'] ?? QMEDIAFLOW_VERSION ), '>' ) ? $release : false;
    }

    public static function channel(): string {
        $value = defined( 'QMEDIAFLOW_UPDATE_CHANNEL' ) ? QMEDIAFLOW_UPDATE_CHANNEL : get_site_option( 'qmediaflow_update_channel', 'stable' );
        return 'testing' === $value ? 'testing' : 'stable';
    }

    public static function can_manage(): bool {
        return current_user_can( 'update_plugins' ) && current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
    }

    public static function canonical_install(): bool {
        return 'mediaflow/mediaflow.php' === plugin_basename( QMEDIAFLOW_FILE );
    }

    public static function report( bool $refresh = false ): array {
        $testing = 'testing' === self::channel();
        $key = 'qmediaflow_release_report_' . ( $testing ? 'testing' : 'stable' );
        $cached = get_site_transient( $key );
        if ( ! $refresh && is_array( $cached ) ) { return $cached; }
        $response = wp_safe_remote_get( self::API . ( $testing ? '?per_page=30' : '/latest' ), array(
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 1048576,
            'headers' => array( 'Accept' => 'application/vnd.github+json' ),
        ) );
        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $report = array( 'checked' => time(), 'release' => false, 'error' => false );
        if ( 404 !== $code || $testing ) {
            $json = 200 === $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
            if ( ! is_array( $json ) ) { $report['error'] = true; }
            else { $report['release'] = self::select( $testing ? $json : array( $json ), '0.0.0', $testing ); }
        }
        set_site_transient( $key, $report, ( $report['error'] ? 5 : 30 ) * MINUTE_IN_SECONDS );
        return $report;
    }

    public static function checksum_matches( string $file, string $text ): bool {
        if ( ! preg_match( '/\A([a-f0-9]{64})[ \t]+\*?qmediaflow\.zip\r?\n?\z/i', $text, $m ) ) { return false; }
        $actual = hash_file( 'sha256', $file );
        return is_string( $actual ) && hash_equals( strtolower( $m[1] ), $actual );
    }

    public static function verify_download( $reply, $package, $upgrader, $context ) {
        if ( ( $context['plugin'] ?? '' ) !== plugin_basename( QMEDIAFLOW_FILE ) || ! is_string( $package )
            || ! str_starts_with( $package, self::URI . '/releases/download/' ) ) { return $reply; }
        $report = self::report();
        $release = $report['release'];
        if ( ! self::canonical_install() || ! $release || $package !== $release['package']
            || ! version_compare( $release['version'], QMEDIAFLOW_VERSION, '>' ) ) {
            return new \WP_Error( 'qmf_release_package', 'QMediaFlow release is no longer eligible for this channel. Recheck updates.' );
        }
        $checksum = wp_safe_remote_get( $package . '.sha256', array( 'timeout' => 10, 'limit_response_size' => 1024 ) );
        if ( is_wp_error( $checksum ) || 200 !== wp_remote_retrieve_response_code( $checksum ) ) {
            return new \WP_Error( 'qmf_release_checksum', 'QMediaFlow release checksum could not be retrieved.' );
        }
        if ( is_wp_error( $reply ) ) { return $reply; }
        if ( false !== $reply ) { return new \WP_Error( 'qmf_release_download', 'Another downloader intercepted the QMediaFlow package.' ); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url( $package, 60 );
        if ( is_wp_error( $file ) ) { return $file; }
        if ( ! self::checksum_matches( $file, wp_remote_retrieve_body( $checksum ) ) ) {
            wp_delete_file( $file );
            return new \WP_Error( 'qmf_release_checksum', 'QMediaFlow package checksum did not match. The update was blocked.' );
        }
        return $file;
    }

    public static function select( array $releases, string $installed, bool $testing ) {
        $best = false;
        foreach ( $releases as $release ) {
            if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) || ! is_bool( $release['prerelease'] ?? null ) ) { continue; }
            $tag = (string) ( $release['tag_name'] ?? '' );
            if ( ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+(?:-(?:alpha|beta|RC)\.[0-9]+)?)$/D', $tag, $m ) ) { continue; }
            $preview = str_contains( $tag, '-' );
            if ( $preview !== $release['prerelease'] || ( $preview && ! $testing ) ) { continue; }
            $version = $m[1];
            if ( ! version_compare( $version, $best ? $best['version'] : $installed, '>' ) ) { continue; }
            $package = self::URI . '/releases/download/' . $tag . '/' . self::ASSET;
            foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
                if ( self::ASSET !== ( $asset['name'] ?? '' ) || 'uploaded' !== ( $asset['state'] ?? '' )
                    || $package !== ( $asset['browser_download_url'] ?? '' ) || (int) ( $asset['size'] ?? 0 ) < 1 ) { continue; }
                $best = array(
                    'id' => self::URI,
                    'slug' => 'mediaflow',
                    'version' => $version,
                    'url' => self::URI . '/releases/tag/' . $tag,
                    'package' => $package,
                    'requires' => '6.5',
                    'requires_php' => '8.1',
                );
                break;
            }
        }
        return $best;
    }
}
