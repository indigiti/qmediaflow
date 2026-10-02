<?php
namespace MediaFlow;

/** Coordinates additive QMediaFlow roadmap modules without changing legacy APIs. */
final class Features {
    private static ?self $instance = null;
    private bool $booted = false;
    private Derivative_Queue $queue;
    private Warmer $warmer;
    private Telemetry $telemetry;

    public static function instance(): self {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    public static function boot(): void {
        self::instance()->register();
    }

    private function register(): void {
        if ( $this->booted ) { return; }
        $plugin = Plugin::instance();
        $this->telemetry = Telemetry::boot( $plugin->paths() );
        Runtime_Config::sync_gateway_config( $plugin->paths() );
        $this->queue = new Derivative_Queue( $plugin->paths(), $plugin->settings(), $plugin->manifests(), $plugin->resolver() );
        $this->warmer = new Warmer( $this->queue );
        $this->warmer->hooks();

        add_action( 'qmediaflow_process_derivative_queue', array( $this, 'process_queue' ), 10, 1 );
        add_action( 'mediaflow_process_derivative_queue', array( $this, 'process_queue' ), 10, 1 );
        $this->booted = true;
    }

    public function process_queue( int $site_id = 0 ): void {
        $switched = false;
        if ( is_multisite() && $site_id > 0 && $site_id !== get_current_blog_id() ) {
            $site = get_site( $site_id );
            if ( ! $site || (int) $site->network_id !== get_current_network_id() || $site->deleted || $site->archived || $site->spam ) { return; }
            switch_to_blog( $site_id );
            $switched = true;
            // Plugin reloads its site-scoped service graph on switch_blog. The
            // feature graph must point at that same site before doing any work.
            $plugin = Plugin::instance();
            $this->telemetry = Telemetry::boot( $plugin->paths() );
            $this->queue = new Derivative_Queue( $plugin->paths(), $plugin->settings(), $plugin->manifests(), $plugin->resolver() );
        }
        try {
            $this->queue->process();
        } finally {
            if ( $switched ) {
                restore_current_blog();
                $plugin = Plugin::instance();
                $this->telemetry = Telemetry::boot( $plugin->paths() );
                $this->queue = new Derivative_Queue( $plugin->paths(), $plugin->settings(), $plugin->manifests(), $plugin->resolver() );
            }
        }
    }

    public function queue(): Derivative_Queue { return $this->queue; }
    public function warmer(): Warmer { return $this->warmer; }
    public function telemetry(): Telemetry { return $this->telemetry; }
}
