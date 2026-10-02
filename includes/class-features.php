<?php
namespace MediaFlow;

/** Coordinates additive QMediaFlow roadmap modules without changing legacy APIs. */
final class Features {
    private static ?self $instance = null;
    private bool $booted = false;
    private Derivative_Queue $queue;
    private Warmer $warmer;
    private Telemetry $telemetry;
    private Distribution $distribution;
    private Smart_Delivery $smart_delivery;
    private Picture $picture;
    private Focal_Point $focal_point;
    private WooCommerce_Adapter $woocommerce;
    private REST_API $rest;
    private Thumbnail_Migrator $thumbnail_migrator;
    private Health $health;
    private Operations_Admin $operations_admin;

    public static function instance(): self {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function boot(): void { self::instance()->register(); }

    private function register(): void {
        if ( $this->booted ) { return; }
        $this->reload_context();

        // Plugin reloads core site-scoped services at priority 10. Rebuild this
        // additive graph after it so multisite switches cannot leak site state.
        add_action( 'switch_blog', array( $this, 'reload_context' ), 20, 0 );

        add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 20, 3 );
        add_action( 'save_post', array( $this, 'save_post' ), 50, 3 );
        add_action( 'woocommerce_update_product', array( $this, 'warm_product' ), 40, 1 );
        add_action( 'woocommerce_new_product', array( $this, 'warm_product' ), 40, 1 );
        add_filter( 'mediaflow_preserved_physical_sizes', array( $this, 'preserve_woocommerce_sizes' ), 20, 3 );
        add_filter( 'qmediaflow_warm_widths', array( $this, 'woocommerce_warm_widths' ) );

        add_filter( 'image_downsize', array( $this, 'smart_downsize' ), 7, 3 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'smart_attributes' ), 90, 3 );
        add_filter( 'wp_content_img_tag', array( $this, 'smart_content_tag' ), 90, 3 );
        if ( Runtime_Config::dual_format_enabled() ) {
            add_filter( 'wp_get_attachment_image', array( $this, 'picture_html' ), 90, 5 );
        }

        add_filter( 'attachment_fields_to_edit', array( $this, 'focal_fields' ), 20, 2 );
        add_filter( 'attachment_fields_to_save', array( $this, 'focal_save' ), 20, 2 );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        add_action( 'qmediaflow_process_derivative_queue', array( $this, 'process_queue' ), 10, 1 );
        add_action( 'mediaflow_process_derivative_queue', array( $this, 'process_queue' ), 10, 1 );
        add_action( 'qmediaflow_distribution_sync', array( $this, 'process_distribution' ), 10, 1 );
        if ( Runtime_Config::mirror_originals() ) { add_action( 'add_attachment', array( $this, 'queue_original' ), 50, 1 ); }

        if ( is_admin() ) {
            $this->operations_admin = new Operations_Admin();
            $this->operations_admin->hooks();
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) { Ops_CLI::register(); }
        $this->booted = true;
    }

    public function reload_context(): void {
        $plugin = Plugin::instance();
        $this->telemetry = Telemetry::boot( $plugin->paths() );
        Runtime_Config::sync_gateway_config( $plugin->paths() );
        $this->queue = new Derivative_Queue( $plugin->paths(), $plugin->settings(), $plugin->manifests(), $plugin->resolver() );
        $this->warmer = new Warmer( $this->queue );
        $this->distribution = new Distribution( $plugin->paths() );
        $this->smart_delivery = new Smart_Delivery( $plugin->manifests(), $plugin->resolver(), $plugin->settings(), $this->distribution );
        $this->picture = new Picture( $plugin->manifests(), $plugin->resolver(), $plugin->settings(), $this->distribution );
        $this->focal_point = new Focal_Point();
        $this->woocommerce = new WooCommerce_Adapter( $this->warmer );
        $this->rest = new REST_API( $plugin->manifests(), $plugin->resolver(), $this->distribution );
        $this->thumbnail_migrator = new Thumbnail_Migrator( $plugin->paths(), $plugin->settings() );
        $this->health = new Health( $plugin->paths(), $plugin->settings(), $this->queue, $this->distribution );
    }

    public function transition_post_status( string $new, string $old, \WP_Post $post ): void { $this->warmer->on_transition( $new, $old, $post ); }
    public function save_post( int $id, \WP_Post $post, bool $update ): void { $this->warmer->on_save( $id, $post, $update ); }
    public function warm_product( int $id ): void { $this->woocommerce->warm_product( $id ); }
    public function preserve_woocommerce_sizes( array $sizes, int $id, string $mode ): array { return $this->woocommerce->preserve_sizes( $sizes, $id, $mode ); }
    public function woocommerce_warm_widths( array $widths ): array { return $this->woocommerce->warm_widths( $widths ); }

    public function smart_downsize( $downsize, int $id, $size ) { return $this->smart_delivery->filter_downsize( $downsize, $id, $size ); }
    public function smart_attributes( array $attr, \WP_Post $attachment, $size ): array { return $this->smart_delivery->filter_attributes( $attr, $attachment, $size ); }
    public function smart_content_tag( string $html, string $context, int $id ): string { return $this->smart_delivery->filter_content_tag( $html, $context, $id ); }
    public function picture_html( string $html, int $id, $size, bool $icon, array $attr ): string { return $this->picture->filter_attachment_html( $html, $id, $size, $icon, $attr ); }

    public function focal_fields( array $fields, \WP_Post $attachment ): array { return $this->focal_point->fields( $fields, $attachment ); }
    public function focal_save( array $post, array $attachment ): array { return $this->focal_point->save( $post, $attachment ); }
    public function register_rest_routes(): void { $this->rest->register_routes(); }
    public function queue_original( int $id ): void { $this->distribution->queue_original( $id ); }

    public function process_queue( int $site_id = 0 ): void {
        $this->with_site( $site_id, fn() => $this->queue->process() );
    }

    public function process_distribution( int $site_id = 0 ): void {
        $this->with_site( $site_id, fn() => $this->distribution->process_queue( $site_id ) );
    }

    private function with_site( int $site_id, callable $callback ): void {
        $switched = false;
        if ( is_multisite() && $site_id > 0 && $site_id !== get_current_blog_id() ) {
            $site = get_site( $site_id );
            if ( ! $site || (int) $site->network_id !== get_current_network_id() || $site->deleted || $site->archived || $site->spam ) { return; }
            switch_to_blog( $site_id );
            $switched = true;
        }
        try { $callback(); }
        finally { if ( $switched ) { restore_current_blog(); } }
    }

    public function queue(): Derivative_Queue { return $this->queue; }
    public function warmer(): Warmer { return $this->warmer; }
    public function telemetry(): Telemetry { return $this->telemetry; }
    public function distribution(): Distribution { return $this->distribution; }
    public function picture(): Picture { return $this->picture; }
    public function thumbnail_migrator(): Thumbnail_Migrator { return $this->thumbnail_migrator; }
    public function health(): Health { return $this->health; }
}
