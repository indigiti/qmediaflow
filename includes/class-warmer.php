<?php
namespace MediaFlow;

/** Queue a tiny, policy-driven set of variants when content becomes traffic-critical. */
final class Warmer {
    private Derivative_Queue $queue;
    private static array $warmed_posts = array();

    public function __construct( Derivative_Queue $queue ) { $this->queue = $queue; }

    public function hooks(): void {
        add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
        add_action( 'save_post', array( $this, 'on_save' ), 50, 3 );
    }

    public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( 'publish' === $new_status && 'publish' !== $old_status ) { $this->warm_post( (int) $post->ID ); }
    }

    public function on_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) { return; }
        $this->warm_post( $post_id );
    }

    public function warm_post( int $post_id ): int {
        $key = ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $post_id;
        if ( ! Runtime_Config::warm_enabled() || $post_id < 1 || isset( self::$warmed_posts[ $key ] ) ) { return 0; }
        self::$warmed_posts[ $key ] = true;
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) { return 0; }

        $max = Runtime_Config::max_warm_variants();
        if ( $max < 1 ) { return 0; }
        $widths = Runtime_Config::warm_widths();
        $jobs = 0;
        $featured = absint( get_post_thumbnail_id( $post_id ) );

        if ( $featured ) {
            foreach ( $this->critical_widths( $widths, 2 ) as $width ) {
                if ( $jobs >= $max ) { break; }
                if ( $this->queue->enqueue( $featured, array( 'width' => $width, 'height' => 0, 'crop' => false ), 'critical' ) ) { ++$jobs; }
            }
        }

        foreach ( $this->content_attachment_ids( $post ) as $attachment_id ) {
            if ( $jobs >= $max ) { break; }
            if ( $attachment_id === $featured ) { continue; }
            $width = $this->closest_width( $widths, 768 );
            if ( $this->queue->enqueue( $attachment_id, array( 'width' => $width, 'height' => 0, 'crop' => false ), 'high' ) ) { ++$jobs; }
        }

        if ( 'product' === $post->post_type ) {
            foreach ( $this->woocommerce_gallery_ids( $post_id ) as $attachment_id ) {
                if ( $jobs >= $max ) { break; }
                if ( $attachment_id === $featured ) { continue; }
                $width = $this->closest_width( $widths, 1200 );
                if ( $this->queue->enqueue( $attachment_id, array( 'width' => $width, 'height' => 0, 'crop' => false ), 'high' ) ) { ++$jobs; }
            }
        }

        Telemetry::event( 'warm_jobs_enqueued', $jobs );
        do_action( 'qmediaflow_post_warmed', $post_id, $jobs );
        return $jobs;
    }

    /** @return int[] */
    private function content_attachment_ids( \WP_Post $post ): array {
        $ids = array();
        if ( preg_match_all( '/(?:wp-image-|"id"\s*:\s*)(\d+)/', (string) $post->post_content, $matches ) ) {
            foreach ( (array) ( $matches[1] ?? array() ) as $id ) {
                $id = absint( $id );
                if ( $id > 0 && wp_attachment_is_image( $id ) ) { $ids[] = $id; }
            }
        }
        return array_slice( array_values( array_unique( $ids ) ), 0, 4 );
    }

    /** @return int[] */
    private function woocommerce_gallery_ids( int $post_id ): array {
        $raw = (string) get_post_meta( $post_id, '_product_image_gallery', true );
        if ( '' === $raw ) { return array(); }
        return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
    }

    /** @param int[] $widths @return int[] */
    private function critical_widths( array $widths, int $count ): array {
        if ( empty( $widths ) ) { return array( 768, 1200 ); }
        $ranked = $widths;
        usort( $ranked, static fn( int $a, int $b ): int => abs( $a - 1200 ) <=> abs( $b - 1200 ) );
        $chosen = array_slice( $ranked, 0, max( 1, $count ) );
        sort( $chosen, SORT_NUMERIC );
        return $chosen;
    }

    /** @param int[] $widths */
    private function closest_width( array $widths, int $target ): int {
        if ( empty( $widths ) ) { return $target; }
        usort( $widths, static fn( int $a, int $b ): int => abs( $a - $target ) <=> abs( $b - $target ) );
        return max( 64, (int) $widths[0] );
    }
}
