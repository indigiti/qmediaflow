<?php
namespace MediaFlow;

/** Manual focal point metadata; post meta is authoritative, runtime reads are filesystem-only. */
final class Focal_Point {
    private static array $cache = array();

    public function hooks(): void {
        add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 20, 2 );
        add_filter( 'attachment_fields_to_save', array( $this, 'save' ), 20, 2 );
    }

    /** Admin/editor authoritative value. @return array{0:int,1:int} */
    public static function get( int $attachment_id ): array {
        $key = self::cache_key( $attachment_id );
        if ( isset( self::$cache[ $key ] ) ) { return self::$cache[ $key ]; }
        $x = get_post_meta( $attachment_id, '_qmediaflow_focal_x', true );
        $y = get_post_meta( $attachment_id, '_qmediaflow_focal_y', true );
        $x = '' === $x ? 50 : max( 0, min( 100, (int) $x ) );
        $y = '' === $y ? 50 : max( 0, min( 100, (int) $y ) );
        self::$cache[ $key ] = array( $x, $y );
        self::persist_runtime( $attachment_id, $x, $y );
        return self::$cache[ $key ];
    }

    /**
     * Delivery-plane value. Absence means center and never falls back to the DB.
     * Only custom/non-center focal points consume filesystem entries.
     *
     * @return array{0:int,1:int}
     */
    public static function runtime( int $attachment_id ): array {
        $key = self::cache_key( $attachment_id );
        if ( isset( self::$cache[ $key ] ) ) { return self::$cache[ $key ]; }
        $path = self::runtime_path( $attachment_id );
        if ( $path && is_readable( $path ) ) {
            $raw = trim( (string) @file_get_contents( $path ) );
            if ( preg_match( '/^(\d{1,3}),(\d{1,3})$/', $raw, $m ) ) {
                $x = max( 0, min( 100, (int) $m[1] ) );
                $y = max( 0, min( 100, (int) $m[2] ) );
                return self::$cache[ $key ] = array( $x, $y );
            }
        }
        return self::$cache[ $key ] = array( 50, 50 );
    }

    public static function delete_runtime( int $attachment_id ): void {
        unset( self::$cache[ self::cache_key( $attachment_id ) ] );
        $path = self::runtime_path( $attachment_id );
        if ( ! $path || ! is_file( $path ) ) { return; }
        @unlink( $path );
        $root = Plugin::instance()->paths()->private_dir() . '/focal';
        $two = dirname( $path );
        $one = dirname( $two );
        foreach ( array( $two, $one ) as $dir ) {
            if ( $dir === $root || ! str_starts_with( wp_normalize_path( $dir ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) { continue; }
            $items = @scandir( $dir );
            if ( is_array( $items ) && count( $items ) <= 2 ) { @rmdir( $dir ); }
        }
    }

    public function fields( array $fields, \WP_Post $attachment ): array {
        if ( ! wp_attachment_is_image( $attachment->ID ) ) { return $fields; }
        [ $x, $y ] = self::get( (int) $attachment->ID );
        $fields['qmediaflow_focal_x'] = array(
            'label' => 'QMediaFlow focal X',
            'input' => 'html',
            'html'  => '<input type="number" min="0" max="100" step="1" name="attachments[' . (int) $attachment->ID . '][qmediaflow_focal_x]" value="' . esc_attr( (string) $x ) . '"> %',
            'helps' => 'Horizontal focal point for cropped QMediaFlow variants. 0 = left, 50 = center, 100 = right.',
        );
        $fields['qmediaflow_focal_y'] = array(
            'label' => 'QMediaFlow focal Y',
            'input' => 'html',
            'html'  => '<input type="number" min="0" max="100" step="1" name="attachments[' . (int) $attachment->ID . '][qmediaflow_focal_y]" value="' . esc_attr( (string) $y ) . '"> %',
            'helps' => 'Vertical focal point for cropped QMediaFlow variants. 0 = top, 50 = center, 100 = bottom.',
        );
        return $fields;
    }

    public function save( array $post, array $attachment ): array {
        $id = absint( $post['ID'] ?? 0 );
        if ( $id < 1 || ! current_user_can( 'edit_post', $id ) ) { return $post; }
        $old = self::get( $id );
        $x = isset( $attachment['qmediaflow_focal_x'] ) ? max( 0, min( 100, absint( $attachment['qmediaflow_focal_x'] ) ) ) : $old[0];
        $y = isset( $attachment['qmediaflow_focal_y'] ) ? max( 0, min( 100, absint( $attachment['qmediaflow_focal_y'] ) ) ) : $old[1];
        update_post_meta( $id, '_qmediaflow_focal_x', $x );
        update_post_meta( $id, '_qmediaflow_focal_y', $y );
        self::$cache[ self::cache_key( $id ) ] = array( $x, $y );
        self::persist_runtime( $id, $x, $y );
        if ( $old[0] !== $x || $old[1] !== $y ) {
            Telemetry::event( 'focal_point_changed' );
            do_action( 'qmediaflow_focal_changed', $id, $x, $y, $old );
        }
        return $post;
    }

    private static function persist_runtime( int $attachment_id, int $x, int $y ): void {
        $path = self::runtime_path( $attachment_id );
        if ( ! $path ) { return; }
        if ( 50 === $x && 50 === $y ) {
            if ( is_file( $path ) ) { @unlink( $path ); }
            return;
        }
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return; }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        if ( false === @file_put_contents( $temp, $x . ',' . $y . "\n" ) ) { return; }
        @chmod( $temp, 0600 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return; }
        @chmod( $path, 0600 );
    }

    private static function runtime_path( int $attachment_id ): string {
        if ( $attachment_id < 1 || ! class_exists( Plugin::class ) ) { return ''; }
        $paths = Plugin::instance()->paths();
        return $paths->private_dir() . '/focal/' . $paths->attachment_shard( $attachment_id ) . '/' . $attachment_id . '.txt';
    }

    private static function cache_key( int $attachment_id ): string {
        $site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        return $site_id . ':' . $attachment_id;
    }
}
