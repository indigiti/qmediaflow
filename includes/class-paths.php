<?php
namespace MediaFlow;

final class Paths {
    private const PUBLIC_BLOCK_BEGIN = '# BEGIN MediaFlow';
    private const PUBLIC_BLOCK_END   = '# END MediaFlow';

    private string $cache_root;
    private string $private_root;
    private int $site_id;
    private string $cache_dir;
    private string $cache_url;
    private string $private_dir;
    private string $manifest_dir;
    private string $cache_url_path;
    private bool $ensured = false;
    private bool $guards_checked = false;

    public function __construct() {
        $this->site_id = is_multisite() ? (int) get_current_blog_id() : 0;
        $scope = $this->site_id ? '/sites/' . $this->site_id : '';
        $this->cache_root = untrailingslashit( WP_CONTENT_DIR ) . '/image-cache';
        $this->cache_dir = $this->cache_root . $scope;
        $this->cache_url = untrailingslashit( content_url( '/image-cache' ) ) . $scope;

        $private = defined( 'MEDIAFLOW_PRIVATE_DIR' ) ? (string) MEDIAFLOW_PRIVATE_DIR : untrailingslashit( WP_CONTENT_DIR ) . '/.mediaflow-private';
        $this->private_root = untrailingslashit( $private );
        $this->private_dir = $this->private_root . $scope;
        $this->manifest_dir = $this->private_dir . '/manifests';

        $path                 = (string) wp_parse_url( $this->cache_url, PHP_URL_PATH );
        $this->cache_url_path = '/' . trim( $path, '/' );
    }

    public function ensure(): bool {
        // Filesystem guard/setup work is request-local and idempotent. A successful
        // ensure is memoized for this site-scoped Paths instance; failures may retry.
        if ( $this->ensured ) {
            return true;
        }
        if ( ! wp_mkdir_p( $this->cache_dir ) || ! wp_mkdir_p( $this->manifest_dir ) ) {
            return false;
        }

        $this->upgrade_guards_if_needed();
        $this->write_public_guard_files();
        $this->write_private_guard_files();

        $ready = is_dir( $this->cache_dir )
            && is_writable( $this->cache_dir )
            && is_dir( $this->private_dir )
            && is_writable( $this->private_dir );
        if ( $ready ) {
            $this->ensured = true;
        }
        return $ready;
    }

    /**
     * Repair v0.2.2 Apache guard files and install an explicit cold-cache route.
     *
     * This is intentionally filesystem-versioned instead of option-versioned so
     * ordinary MediaFlow runtime paths do not gain a database query. The repair
     * scans every existing multisite cache directory because a broken child
     * .htaccess can return HTTP 500 before WordPress/PHP has an opportunity to
     * switch into that site and repair it.
     */
    public function upgrade_guards_if_needed(): void {
        if ( $this->guards_checked ) {
            return;
        }
        if ( ! is_dir( $this->cache_root ) ) {
            wp_mkdir_p( $this->cache_root );
        }
        if ( ! is_dir( $this->private_root ) ) {
            wp_mkdir_p( $this->private_root );
        }

        $marker = $this->private_root . '/.routing-version';
        $root_htaccess = $this->cache_root . '/.htaccess';
        $installed = is_readable( $marker ) ? trim( (string) @file_get_contents( $marker ) ) : '';
        $pending_ready = $this->write_lqip_pending_file();

        $routing_version = defined( 'MEDIAFLOW_ROUTING_SCHEMA_VERSION' ) ? (string) MEDIAFLOW_ROUTING_SCHEMA_VERSION : '3';

        if ( $routing_version === $installed && is_readable( $root_htaccess ) && $pending_ready ) {
            $this->guards_checked = true;
            return;
        }

        $public_migrated = $this->migrate_legacy_public_guards();
        $root_ready      = $this->write_public_root_htaccess();
        $private_ready   = $this->migrate_legacy_private_guards();

        if ( $public_migrated && $root_ready && $private_ready && $pending_ready && is_dir( $this->private_root ) && is_writable( $this->private_root ) ) {
            @file_put_contents( $marker, $routing_version . "\n", LOCK_EX );
            @chmod( $marker, 0644 );
            $this->guards_checked = true;
        }
    }

    public function site_id(): int { return $this->site_id; }

    public function processing_lock_dir(): string { return $this->private_root . '/locks'; }

    public function cache_root_url_path(): string {
        return '/' . trim( (string) wp_parse_url( content_url( '/image-cache' ), PHP_URL_PATH ), '/' );
    }

    public function cache_dir(): string {
        return $this->cache_dir;
    }

    public function cache_url(): string {
        return $this->cache_url;
    }

    public function cache_url_path(): string {
        return $this->cache_url_path;
    }

    public function private_dir(): string {
        return $this->private_dir;
    }

    public function manifest_dir(): string {
        return $this->manifest_dir;
    }

    public function settings_path(): string {
        return $this->private_dir . '/settings.json';
    }

    public function secret_path(): string {
        return $this->private_dir . '/secret.php';
    }

    public function temp_dir(): string {
        return $this->private_dir . '/tmp';
    }

    /**
     * Two decimal shard levels keep large sites from creating huge flat directories.
     * Example: attachment 123456 => 12/34/123456.
     */
    public function attachment_shard( int $attachment_id ): string {
        $attachment_id = max( 1, $attachment_id );
        $level_one     = intdiv( $attachment_id, 10000 ) % 100;
        $level_two     = intdiv( $attachment_id, 100 ) % 100;
        return sprintf( '%02d/%02d', $level_one, $level_two );
    }

    public function manifest_path( int $attachment_id ): string {
        return $this->manifest_dir . '/' . $this->attachment_shard( $attachment_id ) . '/' . $attachment_id . '.json';
    }

    public function attachment_root_dir( int $attachment_id ): string {
        return $this->cache_dir . '/' . $this->attachment_shard( $attachment_id ) . '/' . $attachment_id;
    }

    public function attachment_root_url( int $attachment_id ): string {
        return $this->cache_url . '/' . $this->attachment_shard( $attachment_id ) . '/' . $attachment_id;
    }

    public function lqip_path( int $attachment_id, string $revision, string $signature, string $extension ): string {
        return $this->attachment_root_dir( $attachment_id ) . '/lqip/' . $this->sanitize_hex_token( $revision ) . '/' . $this->sanitize_hex_token( $signature ) . '.' . $this->sanitize_lqip_extension( $extension );
    }

    public function lqip_url( int $attachment_id, string $revision, string $signature, string $extension ): string {
        return $this->attachment_root_url( $attachment_id ) . '/lqip/' . $this->sanitize_hex_token( $revision ) . '/' . $this->sanitize_hex_token( $signature ) . '.' . $this->sanitize_lqip_extension( $extension );
    }

    public function lqip_queue_root(): string {
        return $this->private_dir . '/lqip-queue';
    }

    public function lqip_queue_path( int $attachment_id, string $revision, string $signature ): string {
        return $this->lqip_queue_root() . '/' . $this->attachment_shard( $attachment_id ) . '/' . $attachment_id . '-' . $this->sanitize_hex_token( $revision ) . '-' . $this->sanitize_hex_token( $signature ) . '.json';
    }

    public function lqip_job_lock_path( int $attachment_id, string $revision, string $signature ): string {
        // Bounded lock pool avoids one permanent inode per attachment while still
        // preventing duplicate generation of the same LQIP in normal concurrency.
        $hash = sprintf( '%u', crc32( $attachment_id . '|' . $revision . '|' . $signature ) );
        $slot = (int) $hash % 64;
        return $this->lqip_queue_root() . '/generation-locks/slot-' . sprintf( '%02d', $slot ) . '.lock';
    }

    public function lqip_worker_lock_path(): string {
        return $this->lqip_queue_root() . '/worker.lock';
    }

    public function attachment_cache_dir( int $attachment_id, string $namespace, string $revision ): string {
        return $this->attachment_root_dir( $attachment_id ) . '/' . $this->sanitize_namespace( $namespace ) . '/' . $revision;
    }

    public function attachment_cache_url( int $attachment_id, string $namespace, string $revision ): string {
        return $this->cache_url . '/' . $this->attachment_shard( $attachment_id ) . '/' . $attachment_id . '/' . $this->sanitize_namespace( $namespace ) . '/' . $revision;
    }

    public function legacy_manifest_path( int $attachment_id ): string {
        return $this->cache_dir . '/.mf/manifests/' . $attachment_id . '.json';
    }

    public function legacy_settings_path(): string {
        return $this->cache_dir . '/.mf/settings.json';
    }

    public function legacy_secret_path(): string {
        return $this->cache_dir . '/.mf/secret.php';
    }

    public function is_inside_cache( string $path ): bool {
        return $this->is_inside( $this->cache_dir, $path );
    }

    public function is_inside_private( string $path ): bool {
        return $this->is_inside( $this->private_dir, $path );
    }

    public function is_inside_any_cache( string $path ): bool { return $this->is_inside( $this->cache_root, $path ); }
    public function is_inside_any_private( string $path ): bool { return $this->is_inside( $this->private_root, $path ); }

    private function is_inside( string $base, string $path ): bool {
        $base_real = realpath( $base );
        $real      = realpath( $path );
        if ( false === $base_real || false === $real ) {
            return false;
        }
        $base_real = rtrim( wp_normalize_path( $base_real ), '/' );
        $real      = wp_normalize_path( $real );
        return $real === $base_real || str_starts_with( $real, $base_real . '/' );
    }

    private function sanitize_namespace( string $namespace ): string {
        $namespace = preg_replace( '/[^a-zA-Z0-9_-]/', '', $namespace );
        return is_string( $namespace ) && '' !== $namespace ? substr( $namespace, 0, 32 ) : 'v0200';
    }

    private function sanitize_hex_token( string $token ): string {
        $token = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $token ) ?: '' );
        return 12 === strlen( $token ) ? $token : str_repeat( '0', 12 );
    }

    private function sanitize_lqip_extension( string $extension ): string {
        $extension = strtolower( ltrim( $extension, '.' ) );
        return in_array( $extension, array( 'webp', 'jpg', 'jpeg' ), true ) ? ( 'jpeg' === $extension ? 'jpg' : $extension ) : 'webp';
    }

    private function write_lqip_pending_file(): bool {
        $pending = $this->cache_root . '/__mediaflow-lqip-pending.svg';
        if ( is_readable( $pending ) ) { return true; }
        if ( ! is_dir( $this->cache_root ) && ! wp_mkdir_p( $this->cache_root ) ) { return false; }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" viewBox="0 0 1 1"></svg>';
        if ( false === @file_put_contents( $pending, $svg, LOCK_EX ) ) { return false; }
        @chmod( $pending, 0644 );
        clearstatcache( true, $pending );
        return is_readable( $pending );
    }

    private function write_public_guard_files(): void {
        $this->write_lqip_pending_file();
        foreach ( array_unique( array( $this->cache_root, $this->cache_dir ) ) as $guard_dir ) {
            $index = $guard_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n" );
            }
        }

        // One shared .htaccess handles static cache headers and cold misses for
        // both single-site and multisite. Child site directories deliberately do
        // not receive Apache Options directives.
        $this->write_public_root_htaccess();
    }

    private function write_public_root_htaccess(): bool {
        if ( ! is_dir( $this->cache_root ) ) {
            return false;
        }

        $path = $this->cache_root . '/.htaccess';
        $existing = is_readable( $path ) ? (string) @file_get_contents( $path ) : '';
        $existing = str_replace( array( "\r\n", "\r" ), "\n", $existing );
        $existing = $this->strip_managed_public_block( $existing );
        $existing = $this->strip_legacy_public_block( $existing );

        $block = $this->public_htaccess_block();
        $content = trim( $existing );
        $content = ( '' !== $content ? $content . "\n\n" : '' ) . $block . "\n";

        if ( is_readable( $path ) && $content === (string) @file_get_contents( $path ) ) {
            return true;
        }
        if ( false === @file_put_contents( $path, $content, LOCK_EX ) ) {
            return false;
        }
        @chmod( $path, 0644 );
        clearstatcache( true, $path );
        return is_readable( $path ) && $content === (string) @file_get_contents( $path );
    }

    private function public_htaccess_block(): string {
        $front = $this->front_controller_url_path();
        return self::PUBLIC_BLOCK_BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "  RewriteEngine On\n"
            . "  RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "  RewriteRule ^(?:sites/[1-9][0-9]{0,9}/)?[0-9]{2}/[0-9]{2}/[0-9]+/lqip/[a-fA-F0-9]{12}/[a-fA-F0-9]{12}\\.(?:webp|jpe?g)$ __mediaflow-lqip-pending.svg [L]\n"
            . "  RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$\n"
            . "  RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "  RewriteRule ^(?:sites/[1-9][0-9]{0,9}/)?[0-9]{2}/[0-9]{2}/[0-9]+/[A-Za-z0-9_-]{4,32}/[a-fA-F0-9]{12}/w[0-9]+-h[0-9]+-c[01]-q[0-9]+-s[a-fA-F0-9]{32}\\.(?:avif|webp|jpe?g|png)$ " . $front . " [L]\n"
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            . "  <Files \"__mediaflow-lqip-pending.svg\">\n"
            . "    Header set Cache-Control \"no-store, max-age=0\"\n"
            . "    Header set X-Content-Type-Options \"nosniff\"\n"
            . "  </Files>\n"
            . "  <FilesMatch \"\\.(?:avif|webp|jpe?g|png)$\">\n"
            . "    Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
            . "    Header set X-Content-Type-Options \"nosniff\"\n"
            . "  </FilesMatch>\n"
            . "</IfModule>\n"
            . self::PUBLIC_BLOCK_END;
    }

    private function front_controller_url_path(): string {
        // URL/option helpers for another blog can switch blogs and recurse.
        // Read site metadata instead; only the front-controller PATH is required.
        if ( defined( 'MEDIAFLOW_FRONT_CONTROLLER_PATH' ) ) {
            $path = (string) MEDIAFLOW_FRONT_CONTROLLER_PATH;
        } elseif ( is_multisite() ) {
            $main = get_site( (int) get_main_site_id() );
            $path = rtrim( $main ? (string) $main->path : '/', '/' ) . '/index.php';
        } else {
            $path = (string) wp_parse_url( home_url( '/index.php' ), PHP_URL_PATH );
        }
        if ( '' === $path ) {
            $path = '/index.php';
        }

        $parts = array_filter( explode( '/', trim( $path, '/' ) ), static fn( string $part ): bool => '' !== $part );
        $parts = array_map( static fn( string $part ): string => rawurlencode( rawurldecode( $part ) ), $parts );
        return '/' . implode( '/', $parts );
    }

    private function migrate_legacy_public_guards(): bool {
        $targets = array( $this->cache_root . '/.htaccess' );
        $ok = true;
        $sites = $this->cache_root . '/sites';
        if ( is_dir( $sites ) ) {
            foreach ( (array) @scandir( $sites ) as $entry ) {
                if ( preg_match( '/^[1-9][0-9]{0,9}$/', (string) $entry ) ) {
                    $targets[] = $sites . '/' . $entry . '/.htaccess';
                }
            }
        }

        foreach ( array_unique( $targets ) as $path ) {
            if ( ! is_readable( $path ) ) {
                continue;
            }
            $content = str_replace( array( "\r\n", "\r" ), "\n", (string) @file_get_contents( $path ) );
            $cleaned = $this->strip_legacy_public_block( $content );
            if ( $cleaned === $content ) {
                continue;
            }
            if ( '' === trim( $cleaned ) ) {
                if ( ! @unlink( $path ) && file_exists( $path ) ) {
                    $ok = false;
                }
            } elseif ( false === @file_put_contents( $path, rtrim( $cleaned ) . "\n", LOCK_EX ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function strip_legacy_public_block( string $content ): string {
        $legacy = "Options -Indexes\n<IfModule mod_headers.c>\n  <FilesMatch \"\\.(?:avif|webp|jpe?g|png)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n    Header set X-Content-Type-Options \"nosniff\"\n  </FilesMatch>\n</IfModule>";
        return str_replace( $legacy, '', $content );
    }

    private function strip_managed_public_block( string $content ): string {
        $begin = preg_quote( self::PUBLIC_BLOCK_BEGIN, '/' );
        $end   = preg_quote( self::PUBLIC_BLOCK_END, '/' );
        $result = preg_replace( '/(?:^|\n)' . $begin . '.*?' . $end . '(?=\n|$)/s', '', $content );
        return is_string( $result ) ? trim( $result ) : $content;
    }

    private function write_private_guard_files(): void {
        foreach ( array_unique( array( $this->private_root, $this->private_dir ) ) as $guard_dir ) {
            if ( ! is_dir( $guard_dir ) ) {
                wp_mkdir_p( $guard_dir );
            }

            $index = $guard_dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n" );
            }

            $htaccess = $guard_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents( $htaccess, $this->private_htaccess_content(), LOCK_EX );
            }

            $webconfig = $guard_dir . '/web.config';
            if ( ! file_exists( $webconfig ) ) {
                @file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n" );
            }
        }
    }

    private function migrate_legacy_private_guards(): bool {
        $targets = array( $this->private_root . '/.htaccess' );
        $ok = true;
        $sites = $this->private_root . '/sites';
        if ( is_dir( $sites ) ) {
            foreach ( (array) @scandir( $sites ) as $entry ) {
                if ( preg_match( '/^[1-9][0-9]{0,9}$/', (string) $entry ) ) {
                    $targets[] = $sites . '/' . $entry . '/.htaccess';
                }
            }
        }

        $legacy = "Options -Indexes\n" . $this->private_htaccess_content();
        foreach ( array_unique( $targets ) as $path ) {
            if ( ! is_readable( $path ) ) {
                continue;
            }
            $content = str_replace( array( "\r\n", "\r" ), "\n", (string) @file_get_contents( $path ) );
            if ( trim( $content ) === trim( $legacy ) && false === @file_put_contents( $path, $this->private_htaccess_content(), LOCK_EX ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    private function private_htaccess_content(): string {
        return "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
    }
}
