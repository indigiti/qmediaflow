<?php
namespace MediaFlow;

/** Release-gate fixes that stay off the static derivative delivery path. */
final class Release_Hardening {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) { return; }
        self::$booted = true;

        add_filter( 'rest_pre_dispatch', array( self::class, 'guard_rest_image' ), 20, 3 );
        add_action( 'added_post_meta', array( self::class, 'focal_meta_changed' ), 30, 4 );
        add_action( 'updated_post_meta', array( self::class, 'focal_meta_changed' ), 30, 4 );
        add_action( 'deleted_post_meta', array( self::class, 'focal_meta_changed' ), 30, 4 );
        add_action( 'init', array( self::class, 'resume_gateway_distribution' ), 1 );
    }

    /**
     * The REST route remains cacheable for genuinely public attachments, while
     * authenticated users can still read media they are authorized to access.
     */
    public static function guard_rest_image( $result, $server, $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) { return $result; }
        $route = (string) $request->get_route();
        if ( ! preg_match( '#^/qmediaflow/v1/image/(\d+)$#', $route, $match ) ) { return $result; }

        $id = absint( $match[1] );
        if ( $id < 1 ) { return $result; }
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( 'read_post', $id ) ) { return $result; }

        $post = get_post( $id );
        if ( ! $post || 'attachment' !== $post->post_type || ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) {
            return new \WP_Error( 'qmediaflow_not_found', 'Image attachment is unavailable.', array( 'status' => 404 ) );
        }

        $parent_id = (int) $post->post_parent;
        if ( $parent_id > 0 ) {
            $public = function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $parent_id ) : 'publish' === get_post_status( $parent_id );
            if ( $public ) { return $result; }
        } elseif ( (bool) apply_filters( 'qmediaflow_rest_public_unattached', false, $post ) ) {
            return $result;
        }

        return new \WP_Error( 'qmediaflow_forbidden', 'This image is not publicly readable through QMediaFlow.', array( 'status' => 403 ) );
    }

    public static function focal_meta_changed( $meta_id, int $object_id, string $meta_key, $value ): void {
        if ( ! in_array( $meta_key, array( '_qmediaflow_focal_x', '_qmediaflow_focal_y' ), true ) ) { return; }
        if ( 'attachment' !== get_post_type( $object_id ) ) { return; }
        self::sync_focal_runtime( $object_id );
    }

    /** @return array{id:int,x:int,y:int,custom:bool,written:bool} */
    public static function sync_focal_runtime( int $attachment_id ): array {
        $x_raw = get_post_meta( $attachment_id, '_qmediaflow_focal_x', true );
        $y_raw = get_post_meta( $attachment_id, '_qmediaflow_focal_y', true );
        $x = '' === $x_raw ? 50 : max( 0, min( 100, (int) $x_raw ) );
        $y = '' === $y_raw ? 50 : max( 0, min( 100, (int) $y_raw ) );
        $paths = Plugin::instance()->paths();
        $root = $paths->private_dir() . '/focal';
        $path = $root . '/' . $paths->attachment_shard( $attachment_id ) . '/' . $attachment_id . '.txt';

        if ( 50 === $x && 50 === $y ) {
            $written = ! is_file( $path ) || @unlink( $path );
            self::clean_empty_parents( $path, $root );
            return array( 'id' => $attachment_id, 'x' => $x, 'y' => $y, 'custom' => false, 'written' => $written );
        }

        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return array( 'id' => $attachment_id, 'x' => $x, 'y' => $y, 'custom' => true, 'written' => false );
        }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        $written = false !== @file_put_contents( $temp, $x . ',' . $y . "\n" );
        if ( $written ) {
            @chmod( $temp, 0600 );
            $written = @rename( $temp, $path );
            if ( $written ) { @chmod( $path, 0600 ); }
        }
        if ( is_file( $temp ) ) { @unlink( $temp ); }
        return array( 'id' => $attachment_id, 'x' => $x, 'y' => $y, 'custom' => true, 'written' => (bool) $written );
    }

    /** @return array{processed:int,written:int,total:int,offset:int,limit:int} */
    public static function sync_focal_batch( int $limit = 250, int $offset = 0 ): array {
        $limit = max( 1, min( 2000, $limit ) );
        $offset = max( 0, $offset );
        $query = new \WP_Query( array(
            'post_type'              => 'attachment',
            'post_status'            => 'any',
            'post_mime_type'         => 'image',
            'fields'                 => 'ids',
            'posts_per_page'         => $limit,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'OR',
                array( 'key' => '_qmediaflow_focal_x', 'compare' => 'EXISTS' ),
                array( 'key' => '_qmediaflow_focal_y', 'compare' => 'EXISTS' ),
            ),
        ) );
        $processed = 0;
        $written = 0;
        foreach ( (array) $query->posts as $id ) {
            $result = self::sync_focal_runtime( absint( $id ) );
            ++$processed;
            if ( ! empty( $result['written'] ) ) { ++$written; }
        }
        return array( 'processed' => $processed, 'written' => $written, 'total' => (int) $query->found_posts, 'offset' => $offset, 'limit' => $limit );
    }

    /**
     * The standalone gateway cannot load Action Scheduler/WP-Cron. It leaves one
     * tiny marker after publishing an object-store job; the next WordPress request
     * converts that marker into a background distribution action without scanning.
     */
    public static function resume_gateway_distribution(): void {
        if ( ! Runtime_Config::object_store_enabled() ) { return; }
        $paths = Plugin::instance()->paths();
        $marker = $paths->private_dir() . '/distribution-pending';
        if ( ! is_readable( $marker ) ) { return; }
        $args = array( $paths->site_id() );
        $scheduled = false;
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'qmediaflow_distribution_sync', $args, 'qmediaflow', true );
            $scheduled = true;
        } elseif ( ! wp_next_scheduled( 'qmediaflow_distribution_sync', $args ) ) {
            $scheduled = (bool) wp_schedule_single_event( time() + 1, 'qmediaflow_distribution_sync', $args );
        } else {
            $scheduled = true;
        }
        if ( $scheduled ) { @unlink( $marker ); }
    }

    private static function clean_empty_parents( string $path, string $root ): void {
        $normalized_root = rtrim( wp_normalize_path( $root ), '/' );
        foreach ( array( dirname( $path ), dirname( dirname( $path ) ) ) as $dir ) {
            $normalized = wp_normalize_path( $dir );
            if ( $normalized === $normalized_root || ! str_starts_with( $normalized, $normalized_root . '/' ) ) { continue; }
            $items = @scandir( $dir );
            if ( is_array( $items ) && count( $items ) <= 2 ) { @rmdir( $dir ); }
        }
    }
}
