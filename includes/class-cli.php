<?php
namespace MediaFlow;

final class CLI {
    public static function register(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'mediaflow', self::class );
        }
    }

    /**
     * Show MediaFlow runtime information without scanning the cache tree.
     */
    /** Verify native JPEG and PNG progressive encoding on this server. */
    public function encoding_check(): void {
        \WP_CLI::line( Encoding::check( Plugin::instance()->paths() ) );
    }

    public function status(): void {
        $plugin   = Plugin::instance();
        $paths    = $plugin->paths();
        $settings = $plugin->settings();

        \WP_CLI::line( 'MediaFlow ' . MEDIAFLOW_VERSION );
        \WP_CLI::line( 'Cache: ' . $paths->cache_dir() );
        \WP_CLI::line( 'Private runtime: ' . $paths->private_dir() );
        \WP_CLI::line( 'Namespace: ' . $settings->cache_namespace() );
        \WP_CLI::line( 'Core size mode: ' . $settings->core_size_mode() );
        \WP_CLI::line( 'Placeholder: ' . $settings->placeholder_mode() );
        if ( in_array( $settings->placeholder_mode(), array( 'auto', 'lqip' ), true ) ) {
            \WP_CLI::line( 'LQIP: ' . $settings->lqip_width() . 'px; quality ' . $settings->lqip_quality() . '; max/image ' . $settings->lqip_max_bytes() . ' bytes; max/page ' . $settings->lqip_max_per_request() . '; total/page ' . $settings->lqip_max_total_bytes() . ' bytes' );
        }
        \WP_CLI::success( 'Status read without enumerating attachment cache directories.' );
    }

    /**
     * Rotate the logical cache namespace in O(1).
     */
    public function rotate(): void {
        $namespace = Plugin::instance()->settings()->rotate_cache_namespace();
        \WP_CLI::success( 'MediaFlow namespace rotated to ' . $namespace . '.' );
    }

    /**
     * Inspect a filesystem manifest.
     *
     * ## OPTIONS
     *
     * <attachment-id>
     */
    public function inspect( array $args ): void {
        $id = absint( $args[0] ?? 0 );
        if ( $id < 1 ) {
            \WP_CLI::error( 'A valid attachment ID is required.' );
        }

        $plugin   = Plugin::instance();
        $manifest = $plugin->manifests()->get( $id );
        if ( ! $manifest ) {
            \WP_CLI::error( 'No MediaFlow manifest exists for attachment ' . $id . '.' );
        }

        \WP_CLI::line( 'Manifest: ' . $plugin->paths()->manifest_path( $id ) );
        \WP_CLI::line( wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    }

    /**
     * Rebuild one attachment manifest after source replacement or migration.
     *
     * ## OPTIONS
     * <attachment-id>
     */
    public function repair( array $args ): void {
        $id = absint( $args[0] ?? 0 );
        if ( ! $id ) { \WP_CLI::error( 'Supply an attachment ID.' ); }
        $store = Plugin::instance()->manifests();
        $manifest = $store->build( $id, null, true );
        if ( ! $manifest ) { \WP_CLI::error( 'Cannot rebuild this attachment manifest.' ); }
        \WP_CLI::success( 'Rebuilt manifest for attachment ' . $id . '. Purge cached HTML after source changes.' );
    }

    /**
     * Rebuild progressive LQIPs for a bounded slice of the Media Library.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum attachments to inspect in this run. Default 100.
     *
     * [--after=<attachment-id>]
     * : Resume after an attachment ID from the previous run.
     */
    public function lqip_rebuild( array $args, array $assoc_args ): void {
        if ( ! in_array( Plugin::instance()->settings()->placeholder_mode(), array( 'auto', 'lqip' ), true ) ) {
            \WP_CLI::error( 'Select Auto LQIP or Inline LQIP in MediaFlow settings before pre-warming previews.' );
        }
        $limit = max( 1, min( 10000, absint( $assoc_args['limit'] ?? 100 ) ) );
        $after = absint( $assoc_args['after'] ?? 0 );
        global $wpdb;
        $mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
        $placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d AND post_mime_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d";
        $params = array_merge( array( $after ), $mimes, array( $limit ) );
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
        $store = Plugin::instance()->manifests();
        $ready = 0; $failed = 0; $cursor = $after;
        foreach ( (array) $ids as $id ) {
            $cursor = absint( $id );
            if ( $store->rebuild_lqip( $cursor ) ) { ++$ready; }
            else { ++$failed; }
        }
        \WP_CLI::line( 'Processed ' . count( (array) $ids ) . '; LQIP ready ' . $ready . '; failed ' . $failed . '; resume --after=' . $cursor . '.' );
        if ( count( (array) $ids ) < $limit ) { \WP_CLI::success( 'Reached the end of the supported image attachment set.' ); }
        else { \WP_CLI::success( 'Batch complete. Run again with --after=' . $cursor . ' to continue.' ); }
    }

    /**
     * Warm one critical image without waiting for a visitor request.
     *
     * ## OPTIONS
     * <attachment-id>
     * [--width=<pixels>]
     */
    public function warm( array $args, array $assoc_args ): void {
        $plugin = Plugin::instance();
        $id = absint( $args[0] ?? 0 );
        $manifest = $plugin->manifests()->ensure_for_attachment( $id );
        if ( ! $id || ! $manifest ) { \WP_CLI::error( 'Attachment is unavailable.' ); }
        $spec = $plugin->resolver()->spec_for_size( array( absint( $assoc_args['width'] ?? 1200 ), 0 ), $manifest );
        $variant = $spec ? $plugin->resolver()->variant_for( $id, $manifest, $spec ) : null;
        if ( ! $variant ) { \WP_CLI::error( 'Transformation or signing storage is unavailable.' ); }
        $processor = new Processor( $plugin->paths(), $plugin->manifests(), $plugin->resolver() );
        $result = $processor->generate( $id, $manifest, $variant );
        if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
        \WP_CLI::success( $result );
    }

    /**
     * Stream through cache shards and remove old namespace directories.
     * Memory usage stays bounded; no recursive glob of the whole media library is used.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum stale namespace directories to remove in this run. Default 1000.
     *
     * [--scan-limit=<number>]
     * : Maximum attachment roots inspected. Default 1000.
     * [--after=<cursor>]
     * : Resume after the shard/attachment cursor printed by the previous run.
     * [--retention-days=<days>]
     * : Minimum age for removal. Default 7; minimum 1.
     * [--dry-run]
     * : Report stale namespaces without deleting them.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function purge_stale( array $args, array $assoc_args ): void {
        $plugin    = Plugin::instance();
        $cache_dir = $plugin->paths()->cache_dir();
        $current   = $plugin->settings()->cache_namespace();
        $limit     = max( 1, min( 100000, absint( $assoc_args['limit'] ?? 1000 ) ) );
        $dry_run   = isset( $assoc_args['dry-run'] );

        if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
            \WP_CLI::confirm( 'Remove up to ' . $limit . ' stale MediaFlow namespace directories? Cached pages that still reference them may fall back to the original image.' );
        }

        $scan_limit = max( 1, min( 100000, absint( $assoc_args['scan-limit'] ?? 1000 ) ) );
        $after = (string) ( $assoc_args['after'] ?? '' );
        if ( '' !== $after && ! preg_match( '#^\d{2}/\d{2}/\d+$#', $after ) ) { \WP_CLI::error( 'Invalid resume cursor.' ); }
        $cutoff = time() - max( 1, absint( $assoc_args['retention-days'] ?? 7 ) ) * 86400;
        $scanned = 0;
        $cursor = $after;
        $seen = 0;
        $removed = 0;
        foreach ( $this->attachment_roots( $cache_dir ) as $attachment_root ) {
            $relative = substr( $attachment_root, strlen( $cache_dir ) + 1 );
            if ( '' !== $after && strcmp( $relative, $after ) <= 0 ) { continue; }
            if ( $scanned >= $scan_limit ) { break; }
            ++$scanned;
            $cursor = $relative;
            $manifest = $plugin->manifests()->get( (int) basename( $attachment_root ) );
            $namespaces = @scandir( $attachment_root );
            if ( ! is_array( $namespaces ) ) {
                continue;
            }
            foreach ( $namespaces as $namespace ) {
                if ( '.' === $namespace || '..' === $namespace ) {
                    continue;
                }
                if ( 'lqip' === $namespace ) { continue; }
                $path = $attachment_root . '/' . $namespace;
                if ( ! is_dir( $path ) || is_link( $path ) ) {
                    continue;
                }
                if ( $namespace === $current ) {
                    if ( ! $manifest ) { continue; }
                    foreach ( new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS ) as $revision ) {
                        if ( ! $revision->isDir() || $revision->isLink() || $revision->getFilename() === (string) $manifest['revision'] || $revision->getMTime() >= $cutoff ) { continue; }
                        if ( $dry_run ) { \WP_CLI::line( $revision->getPathname() ); }
                        else { $this->delete_tree( $revision->getPathname(), $cache_dir ); ++$removed; }
                        if ( ++$seen >= $limit ) { $cursor = ''; break 3; }
                    }
                    continue;
                }
                if ( (int) @filemtime( $path ) >= $cutoff ) { continue; }
                ++$seen;
                if ( $dry_run ) {
                    \WP_CLI::line( $path );
                } else {
                    $this->delete_tree( $path, $cache_dir );
                    ++$removed;
                }
                if ( $seen >= $limit ) {
                    $cursor = ''; // Restart scan when stopping inside an attachment.
                    break 2;
                }
            }
        }

        \WP_CLI::line( 'Scanned: ' . $scanned . '; resume --after=' . $cursor . ' (empty means restart).' );
        if ( $dry_run ) {
            \WP_CLI::success( 'Dry run found ' . $seen . ' stale namespace directories (limit ' . $limit . ').' );
        } else {
            \WP_CLI::success( 'Removed ' . $removed . ' stale namespace directories (limit ' . $limit . ').' );
        }
    }

    /** @return \Generator<string> */
    private function attachment_roots( string $cache_dir ): \Generator {
        $level_one = @scandir( $cache_dir );
        foreach ( is_array( $level_one ) ? $level_one : array() as $one ) {
            if ( ! preg_match( '/^\d{2}$/', $one ) ) {
                continue;
            }
            $one_path = $cache_dir . '/' . $one;
            if ( ! is_dir( $one_path ) || is_link( $one_path ) ) {
                continue;
            }

            $level_two = @scandir( $one_path );
            foreach ( is_array( $level_two ) ? $level_two : array() as $two ) {
                if ( ! preg_match( '/^\d{2}$/', $two ) ) {
                    continue;
                }
                $two_path = $one_path . '/' . $two;
                if ( ! is_dir( $two_path ) || is_link( $two_path ) ) {
                    continue;
                }

                $attachments = @scandir( $two_path );
                foreach ( is_array( $attachments ) ? $attachments : array() as $attachment ) {
                    if ( ! ctype_digit( $attachment ) ) {
                        continue;
                    }
                    $attachment_path = $two_path . '/' . $attachment;
                    if ( is_dir( $attachment_path ) && ! is_link( $attachment_path ) ) {
                        yield $attachment_path;
                    }
                }
            }
        }
    }

    private function delete_tree( string $path, string $cache_root ): void {
        $cache_real = realpath( $cache_root );
        $real       = realpath( $path );
        if ( false === $cache_real || false === $real ) {
            return;
        }
        $cache_real = rtrim( wp_normalize_path( $cache_real ), '/' );
        $real       = wp_normalize_path( $real );
        if ( ! str_starts_with( $real, $cache_real . '/' ) || is_link( $real ) ) {
            return;
        }

        $items = @scandir( $real );
        foreach ( is_array( $items ) ? $items : array() as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $child = $real . '/' . $item;
            if ( is_dir( $child ) && ! is_link( $child ) ) {
                $this->delete_tree( $child, $cache_root );
            } else {
                @unlink( $child );
            }
        }
        @rmdir( $real );
    }
}
