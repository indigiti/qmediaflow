<?php
namespace MediaFlow;

final class Plugin {
    private static ?self $instance = null;

    private bool $booted = false;
    private bool $reloading_context = false;
    private Paths $paths;
    private Settings $settings;
    private Upload_Optimizer $upload_optimizer;
    private Manifest_Store $manifests;
    private Resolver $resolver;
    private Processor $processor;
    private Request_Handler $request_handler;
    private Responsive $responsive;
    private Admin $admin;

    private function __construct() {
        $this->reload_context();
    }

    public function reload_context(): void {
        // URL/option filters may temporarily switch sites while services are built.
        // Do not recursively construct or publish a partially rebuilt service graph.
        if ( $this->reloading_context ) { return; }
        $this->reloading_context = true;
        try {
            $site_id = get_current_blog_id();
            $paths = new Paths();
            $settings = new Settings( $paths );
            $upload_optimizer = new Upload_Optimizer( $settings );
            $manifests = new Manifest_Store( $paths, $settings );
            $resolver = new Resolver( $paths, $settings, $manifests );
            $processor = new Processor( $paths, $manifests, $resolver );
            $handler = new Request_Handler( $paths, $manifests, $resolver, $processor );
            $responsive = new Responsive( $manifests, $resolver, $settings );
            $admin = new Admin( $paths, $settings );
            if ( get_current_blog_id() !== $site_id ) {
                throw new \RuntimeException( 'MediaFlow context initialization detected an unrestored site switch.' );
            }
            $this->paths = $paths;
            $this->settings = $settings;
            $this->upload_optimizer = $upload_optimizer;
            $this->manifests = $manifests;
            $this->resolver = $resolver;
            $this->processor = $processor;
            $this->request_handler = $handler;
            $this->responsive = $responsive;
            $this->admin = $admin;
        } finally { $this->reloading_context = false; }
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && ! $network_wide ) { wp_die( 'Please Network Activate MediaFlow from Network Admin → Plugins so subsite cache misses can be routed correctly.' ); }
        $paths = new Paths();
        $paths->ensure();
        Settings::install_defaults( $paths );
    }

    public static function network_enabled(): bool {
        return ! is_multisite() || isset( ((array) get_site_option( 'active_sitewide_plugins', array() ))[ plugin_basename( MEDIAFLOW_FILE ) ] );
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        if ( ! self::network_enabled() ) {
            add_action( 'admin_notices', static function (): void { echo '<div class="notice notice-warning"><p>MediaFlow supports multisite. Please Network Activate it in Network Admin → Plugins to enable image rewriting across sites.</p></div>'; } );
            return;
        }

        // v0.2.3: repair legacy Apache guards before normal hooks are registered.
        // This is filesystem-versioned and scans existing multisite cache scopes so
        // a child .htaccess cannot keep returning HTTP 500 before PHP is reached.
        $this->paths->upgrade_guards_if_needed();
        $this->booted = true;

        add_action( 'switch_blog', array( $this, 'reload_context' ), 10, 0 );
        $this->upload_optimizer->hooks();
        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'disable_intermediate_sizes' ), 999, 3 );

        add_filter( 'wp_generate_attachment_metadata', array( $this, 'capture_manifest' ), 999, 3 );
        add_action( 'added_post_meta', array( $this, 'invalidate_manifest' ), 10, 4 );
        add_action( 'updated_post_meta', array( $this, 'invalidate_manifest' ), 10, 4 );
        add_action( 'deleted_post_meta', array( $this, 'invalidate_manifest' ), 10, 4 );
        add_action( 'delete_attachment', array( $this, 'delete_attachment_cache' ), 5 );

        add_filter( 'image_downsize', fn( $downsize, $id, $size ) => $this->resolver->filter_image_downsize( $downsize, $id, $size ), 5, 3 );
        add_filter( 'wp_get_attachment_image_attributes', fn( $attr, $attachment, $size ) => $this->responsive->filter_attachment_attributes( $attr, $attachment, $size ), 50, 3 );
        add_filter( 'wp_content_img_tag', fn( $html, $context, $id ) => $this->responsive->filter_content_img_tag( $html, $context, $id ), 50, 3 );

        // A request reaches this handler only when a normal signed derivative is missing.
        // Auto-LQIP cold misses are handled by the static web-server pending fallback.
        add_action( 'template_redirect', array( $this, 'route_image_request' ), 0 );
        add_action( 'mediaflow_process_lqip_queue', array( $this, 'process_lqip_queue' ), 10, 1 );

        if ( is_admin() ) {
            $this->admin->hooks();
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            CLI::register();
        }
    }

    public function admin(): Admin { return $this->admin; }

    public function route_image_request(): void {
        if ( ! is_multisite() ) { $this->request_handler->maybe_handle(); return; }
        if ( is_admin() ) { return; }
        $path = (string) wp_parse_url( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
        $prefix = rtrim( $this->paths->cache_root_url_path(), '/' ) . '/sites/';
        if ( ! str_starts_with( $path, $prefix ) ) { return; }
        $relative = substr( $path, strlen( $prefix ) );
        if ( ! preg_match( '#^([1-9][0-9]{0,9})/#', $relative, $match ) ) { $this->missing_site(); }
        $id = (int) $match[1];
        $site = get_site( $id );
        if ( ! $site || (int) $site->network_id !== get_current_network_id() || $site->deleted || $site->archived || $site->spam ) { $this->missing_site(); }
        $switched = $id !== get_current_blog_id();
        if ( $switched ) { switch_to_blog( $id ); }
        try { $this->request_handler->maybe_handle(); }
        finally { if ( $switched ) { restore_current_blog(); } }
    }

    public function process_lqip_queue( int $site_id = 0 ): void {
        $switched = false;
        if ( is_multisite() && $site_id > 0 && $site_id !== get_current_blog_id() ) {
            $site = get_site( $site_id );
            if ( ! $site || (int) $site->network_id !== get_current_network_id() || $site->deleted || $site->archived || $site->spam ) { return; }
            switch_to_blog( $site_id );
            $switched = true;
        }
        try {
            // One image per cron run keeps background work bounded. The worker
            // reschedules itself while ready queue markers remain.
            $this->manifests->process_lqip_queue( 1, 8.0 );
        } finally {
            if ( $switched ) { restore_current_blog(); }
        }
    }

    private function missing_site(): void {
        status_header( 404 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        echo 'MediaFlow site was not found.';
        exit;
    }

    public function resolver(): Resolver {
        return $this->resolver;
    }

    public function paths(): Paths {
        return $this->paths;
    }

    public function settings(): Settings {
        return $this->settings;
    }

    public function upload_optimizer(): Upload_Optimizer {
        return $this->upload_optimizer;
    }

    public function manifests(): Manifest_Store {
        return $this->manifests;
    }

    public function disable_intermediate_sizes( array $sizes, array $image_meta = array(), int $attachment_id = 0 ): array {
        $mode = $this->settings->core_size_mode();
        if ( 'safe' === $mode ) {
            return $sizes;
        }

        $preserved_names = 'adaptive' === $mode ? array( 'thumbnail' ) : array();
        /**
         * Allow integrations to keep physical sizes that bypass normal WordPress image APIs.
         * Site Icon sizes are always preserved by core compatibility policy.
         */
        $preserved_names = (array) apply_filters( 'mediaflow_preserved_physical_sizes', $preserved_names, $attachment_id, $mode );

        $preserve = array();
        foreach ( $sizes as $name => $definition ) {
            if ( ! empty( $definition['crop'] ) || str_starts_with( (string) $name, 'site_icon-' ) || in_array( (string) $name, $preserved_names, true ) ) {
                $preserve[ $name ] = $definition;
            }
        }
        return $preserve;
    }

    public function invalidate_manifest( $meta_id, int $id, string $key, $value ): void {
        if ( '_wp_attachment_metadata' === $key && is_array( $value ) && 'deleted_post_meta' !== current_filter() ) {
            $this->manifests->build( $id, $value, false );
        } elseif ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
            $this->manifests->delete( $id );
        }
    }

    public function capture_manifest( array $metadata, int $attachment_id, string $context = 'create' ): array {
        $this->paths->ensure();
        $this->manifests->build( $attachment_id, $metadata, true );
        return $metadata;
    }

    public function delete_attachment_cache( int $attachment_id ): void {
        $this->delete_tree( $this->paths->attachment_root_dir( $attachment_id ) );

        // v0.1 compatibility cleanup for an attachment deleted after upgrading.
        $this->delete_tree( $this->paths->cache_dir() . '/' . $attachment_id );
        $this->manifests->delete( $attachment_id );
        $this->remove_empty_cache_shards( $attachment_id );
    }

    private function delete_tree( string $path ): void {
        if ( is_link( $path ) ) {
            @unlink( $path );
            return;
        }
        if ( ! is_dir( $path ) || ! $this->paths->is_inside_cache( $path ) ) {
            return;
        }
        $items = scandir( $path );
        foreach ( is_array( $items ) ? $items : array() as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $child = $path . '/' . $item;
            if ( is_dir( $child ) && ! is_link( $child ) ) {
                $this->delete_tree( $child );
            } else {
                @unlink( $child );
            }
        }
        @rmdir( $path );
    }

    private function remove_empty_cache_shards( int $attachment_id ): void {
        $shard = explode( '/', $this->paths->attachment_shard( $attachment_id ) );
        if ( 2 !== count( $shard ) ) {
            return;
        }
        $paths = array(
            $this->paths->cache_dir() . '/' . $shard[0] . '/' . $shard[1],
            $this->paths->cache_dir() . '/' . $shard[0],
        );
        foreach ( $paths as $path ) {
            $items = @scandir( $path );
            if ( is_array( $items ) && count( $items ) <= 2 ) {
                @rmdir( $path );
            }
        }
    }
}
