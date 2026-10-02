<?php
namespace MediaFlow;

/**
 * QMediaFlow public-brand compatibility layer.
 *
 * Internal MediaFlow-era identifiers remain stable for upgrade safety. This
 * class changes only the user-visible wp-admin product label on the legacy
 * Tools page; action names, nonce names, settings and cache identities are not
 * modified.
 */
final class Branding {
    public static function register(): void {
        add_action( 'admin_menu', array( self::class, 'rename_tools_menu' ), 999 );
        add_action( 'load-tools_page_mediaflow', array( self::class, 'buffer_admin_page' ), 0 );
    }

    public static function rename_tools_menu(): void {
        remove_submenu_page( 'tools.php', 'mediaflow' );
        add_management_page(
            'QMediaFlow',
            'QMediaFlow',
            'manage_options',
            'mediaflow',
            static fn() => Plugin::instance()->admin()->render()
        );
    }

    public static function buffer_admin_page(): void {
        ob_start(
            static function ( string $html ): string {
                return str_replace( 'MediaFlow', 'QMediaFlow', $html );
            }
        );
    }
}
