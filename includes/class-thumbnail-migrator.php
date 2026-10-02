<?php
namespace MediaFlow;

/**
 * Bounded legacy thumbnail migration. Candidates are quarantined, metadata is
 * updated per attachment, and rollback manifests retain enough information to
 * restore files. Nothing recursively scans the whole library in one request.
 */
final class Thumbnail_Migrator {
    private Paths $paths;
    private Settings $settings;

    public function __construct( Paths $paths, Settings $settings ) {
        $this->paths = $paths;
        $this->settings = $settings;
    }

    public function audit( int $limit = 100, int $after = 0 ): array {
        $limit = max( 1, min( 1000, $limit ) );
        $ids = $this->attachment_ids( $limit, $after );
        $summary = array( 'attachments' => 0, 'candidates' => 0, 'referenced' => 0, 'preserved' => 0, 'bytes' => 0, 'cursor' => $after, 'done' => count( $ids ) < $limit );
        $items = array();
        foreach ( $ids as $id ) {
            $summary['cursor'] = $id;
            ++$summary['attachments'];
            $row = $this->audit_attachment( $id );
            $items[] = $row;
            foreach ( $row['sizes'] as $size ) {
                if ( $size['preserved'] ) { ++$summary['preserved']; continue; }
                if ( $size['referenced'] ) { ++$summary['referenced']; continue; }
                ++$summary['candidates'];
                $summary['bytes'] += (int) $size['bytes'];
            }
        }
        $summary['code_reference_note'] = 'Database content/meta references are checked. Hard-coded theme/plugin filesystem URLs cannot be proven absent automatically; migration quarantines files and keeps rollback metadata.';
        return array( 'summary' => $summary, 'items' => $items );
    }

    public function migrate( int $limit = 25, int $after = 0, bool $dry_run = true ): array {
        if ( 'safe' === $this->settings->core_size_mode() && ! $dry_run ) {
            return array( 'error' => 'Switch QMediaFlow physical size mode to Adaptive or Strict before migrating physical thumbnails.' );
        }
        $audit = $this->audit( max( 1, min( 250, $limit ) ), $after );
        $result = array( 'processed' => 0, 'moved' => 0, 'bytes' => 0, 'failed' => 0, 'cursor' => $after, 'done' => (bool) $audit['summary']['done'], 'dry_run' => $dry_run );
        foreach ( $audit['items'] as $item ) {
            $id = (int) $item['id'];
            $result['cursor'] = $id;
            ++$result['processed'];
            if ( $dry_run ) {
                foreach ( $item['sizes'] as $size ) {
                    if ( ! $size['preserved'] && ! $size['referenced'] && $size['exists'] ) { ++$result['moved']; $result['bytes'] += (int) $size['bytes']; }
                }
                continue;
            }
            $migrated = $this->migrate_attachment( $item );
            $result['moved'] += $migrated['moved'];
            $result['bytes'] += $migrated['bytes'];
            $result['failed'] += $migrated['failed'];
        }
        Telemetry::event( 'thumbnail_migration_files', $result['moved'] );
        Telemetry::bytes( 'thumbnail_migration_bytes', $result['bytes'] );
        return $result;
    }

    public function rollback( int $attachment_id ): array {
        $path = $this->manifest_path( $attachment_id );
        if ( ! is_readable( $path ) ) { return array( 'restored' => 0, 'failed' => 0, 'message' => 'No migration manifest exists.' ); }
        $raw = @file_get_contents( $path );
        $migration = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $migration ) ) { return array( 'restored' => 0, 'failed' => 1, 'message' => 'Migration manifest is invalid.' ); }
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $metadata = is_array( $metadata ) ? $metadata : array();
        $metadata['sizes'] = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
        $restored = 0; $failed = 0;
        foreach ( (array) ( $migration['files'] ?? array() ) as $entry ) {
            $from = (string) ( $entry['quarantine'] ?? '' );
            $to = (string) ( $entry['original'] ?? '' );
            $name = (string) ( $entry['size_name'] ?? '' );
            if ( '' === $from || '' === $to || '' === $name || ! is_readable( $from ) ) { ++$failed; continue; }
            $dir = dirname( $to );
            if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { ++$failed; continue; }
            if ( ! @rename( $from, $to ) ) { ++$failed; continue; }
            if ( is_array( $entry['metadata'] ?? null ) ) { $metadata['sizes'][ $name ] = $entry['metadata']; }
            ++$restored;
        }
        if ( $restored > 0 ) { wp_update_attachment_metadata( $attachment_id, $metadata ); }
        if ( 0 === $failed ) { @unlink( $path ); $this->remove_empty_parents( dirname( $path ), $this->manifest_root() ); }
        return array( 'restored' => $restored, 'failed' => $failed, 'message' => 0 === $failed ? 'Rollback complete.' : 'Rollback completed with failures.' );
    }

    public function purge_quarantine( int $limit = 100, int $retention_days = 30, bool $dry_run = true ): array {
        $limit = max( 1, min( 5000, $limit ) );
        $cutoff = time() - max( 1, $retention_days ) * DAY_IN_SECONDS;
        $result = array( 'files' => 0, 'bytes' => 0, 'dry_run' => $dry_run );
        $root = $this->quarantine_root();
        if ( ! is_dir( $root ) ) { return $result; }
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
        foreach ( $iterator as $file ) {
            if ( $result['files'] >= $limit ) { break; }
            if ( ! $file->isFile() || $file->isLink() || $file->getMTime() >= $cutoff ) { continue; }
            ++$result['files']; $result['bytes'] += $file->getSize();
            if ( ! $dry_run ) { @unlink( $file->getPathname() ); }
        }
        return $result;
    }

    private function audit_attachment( int $id ): array {
        $metadata = wp_get_attachment_metadata( $id );
        $metadata = is_array( $metadata ) ? $metadata : array();
        $source = get_attached_file( $id, true );
        $source_dir = is_string( $source ) ? dirname( $source ) : '';
        $preserved = $this->preserved_size_names( $id );
        $sizes = array();
        foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $definition ) {
            if ( ! is_array( $definition ) || empty( $definition['file'] ) ) { continue; }
            $file = $source_dir ? $source_dir . '/' . basename( (string) $definition['file'] ) : '';
            $exists = '' !== $file && is_file( $file );
            $filename = basename( (string) $definition['file'] );
            $sizes[] = array(
                'name'       => (string) $name,
                'file'       => $file,
                'filename'   => $filename,
                'exists'     => $exists,
                'bytes'      => $exists ? max( 0, (int) @filesize( $file ) ) : 0,
                'preserved'  => in_array( (string) $name, $preserved, true ) || str_starts_with( (string) $name, 'site_icon-' ),
                'referenced' => $filename ? $this->database_references_filename( $filename, $id ) : false,
                'metadata'   => $definition,
            );
        }
        return array( 'id' => $id, 'source' => $source, 'sizes' => $sizes );
    }

    private function migrate_attachment( array $item ): array {
        $id = (int) $item['id'];
        $metadata = wp_get_attachment_metadata( $id );
        $metadata = is_array( $metadata ) ? $metadata : array();
        $metadata['sizes'] = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
        $migration = array( 'version' => 1, 'attachment_id' => $id, 'created_at' => time(), 'files' => array() );
        $moved = 0; $bytes = 0; $failed = 0;
        foreach ( $item['sizes'] as $size ) {
            if ( $size['preserved'] || $size['referenced'] || ! $size['exists'] ) { continue; }
            $original = (string) $size['file'];
            $quarantine = $this->quarantine_path( $id, basename( $original ) );
            $dir = dirname( $quarantine );
            if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { ++$failed; continue; }
            if ( ! @rename( $original, $quarantine ) ) { ++$failed; continue; }
            $migration['files'][] = array( 'size_name' => (string) $size['name'], 'original' => $original, 'quarantine' => $quarantine, 'metadata' => $size['metadata'], 'bytes' => (int) $size['bytes'] );
            unset( $metadata['sizes'][ (string) $size['name'] ] );
            ++$moved; $bytes += (int) $size['bytes'];
        }
        if ( $moved > 0 ) {
            if ( ! $this->write_manifest( $id, $migration ) ) {
                // A rollback record is mandatory. Restore immediately if it cannot be published.
                foreach ( $migration['files'] as $entry ) { @rename( (string) $entry['quarantine'], (string) $entry['original'] ); }
                return array( 'moved' => 0, 'bytes' => 0, 'failed' => $failed + $moved );
            }
            wp_update_attachment_metadata( $id, $metadata );
        }
        return array( 'moved' => $moved, 'bytes' => $bytes, 'failed' => $failed );
    }

    /** @return int[] */
    private function attachment_ids( int $limit, int $after ): array {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit );
        return array_map( 'absint', (array) $wpdb->get_col( $sql ) );
    }

    private function database_references_filename( string $filename, int $attachment_id ): bool {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $filename ) . '%';
        $post = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID <> %d AND post_content LIKE %s LIMIT 1", $attachment_id, $like ) );
        if ( $post ) { return true; }
        $meta = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_value LIKE %s LIMIT 1", $attachment_id, $like ) );
        return (bool) $meta;
    }

    /** @return string[] */
    private function preserved_size_names( int $attachment_id ): array {
        $mode = $this->settings->core_size_mode();
        $names = 'adaptive' === $mode ? array( 'thumbnail' ) : array();
        $names = (array) apply_filters( 'mediaflow_preserved_physical_sizes', $names, $attachment_id, $mode );
        return array_values( array_unique( array_map( 'strval', $names ) ) );
    }

    private function manifest_root(): string { return $this->paths->private_dir() . '/thumbnail-migration/manifests'; }
    private function quarantine_root(): string { return $this->paths->private_dir() . '/thumbnail-migration/quarantine'; }
    private function manifest_path( int $id ): string { return $this->manifest_root() . '/' . $this->paths->attachment_shard( $id ) . '/' . $id . '.json'; }
    private function quarantine_path( int $id, string $filename ): string { return $this->quarantine_root() . '/' . $this->paths->attachment_shard( $id ) . '/' . $id . '/' . sanitize_file_name( $filename ); }

    private function write_manifest( int $id, array $data ): bool {
        $path = $this->manifest_path( $id ); $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { return false; }
        $tmp = $path . '.tmp-' . getmypid();
        if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) { return false; }
        @chmod( $tmp, 0600 );
        if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; }
        @chmod( $path, 0600 ); return true;
    }

    private function remove_empty_parents( string $path, string $stop ): void {
        $stop = rtrim( wp_normalize_path( $stop ), '/' );
        $path = rtrim( wp_normalize_path( $path ), '/' );
        while ( str_starts_with( $path, $stop . '/' ) ) {
            $items = @scandir( $path );
            if ( ! is_array( $items ) || count( $items ) > 2 ) { break; }
            @rmdir( $path ); $path = dirname( $path );
        }
    }
}
