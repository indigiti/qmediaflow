<?php
namespace MediaFlow;

final class Release_Updater {
    private const URI = 'https://github.com/indigiti/qmediaflow';
    private const API = 'https://api.github.com/repos/indigiti/qmediaflow/releases';
    private const ASSET = 'qmediaflow.zip';

    public static function register(): void {
        add_filter( 'update_plugins_github.com', array( self::class, 'offer' ), 10, 4 );
    }

    public static function offer( $update, array $data, string $file, array $locales ) {
        if ( plugin_basename( QMEDIAFLOW_FILE ) !== $file || self::URI !== ( $data['UpdateURI'] ?? '' ) ) { return $update; }
        // The release archive keeps this path stable during upgrades.
        if ( 'mediaflow/mediaflow.php' !== $file ) { return false; }
        $testing = defined( 'QMEDIAFLOW_UPDATE_CHANNEL' ) && 'testing' === QMEDIAFLOW_UPDATE_CHANNEL;
        $key = 'qmediaflow_release_' . ( $testing ? 'testing' : 'stable' );
        $releases = get_site_transient( $key );
        if ( ! is_array( $releases ) ) {
            $response = wp_safe_remote_get( self::API . ( $testing ? '?per_page=30' : '/latest' ), array(
                'timeout' => 5,
                'redirection' => 0,
                'limit_response_size' => 1048576,
                'headers' => array( 'Accept' => 'application/vnd.github+json' ),
            ) );
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return false; }
            $json = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $json ) ) { return false; }
            $releases = $testing ? $json : array( $json );
            set_site_transient( $key, $releases, 30 * MINUTE_IN_SECONDS );
        }
        return self::select( $releases, (string) ( $data['Version'] ?? QMEDIAFLOW_VERSION ), $testing );
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
