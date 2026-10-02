<?php
namespace MediaFlow;

/** Lightweight operational dashboard; all expensive checks are explicit. */
final class Operations_Admin {
    public function hooks(): void {
        add_action( 'admin_menu', array( $this, 'menu' ), 30 );
        add_action( 'admin_post_qmediaflow_run_queue', array( $this, 'run_queue' ) );
        add_action( 'admin_post_qmediaflow_reset_metrics', array( $this, 'reset_metrics' ) );
    }

    public function menu(): void {
        add_management_page( 'QMediaFlow Operations', 'QMediaFlow Operations', 'manage_options', 'qmediaflow-operations', array( $this, 'render' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $features = Features::instance();
        $health = $features->health()->run( false );
        $metrics = $features->telemetry()->snapshot();
        $queue = $features->queue()->status( 5000 );
        ?>
        <div class="wrap">
            <h1>QMediaFlow Operations</h1>
            <p>Operational status is read from filesystem runtime state. Static image hits do not execute this code.</p>
            <?php if ( isset( $_GET['qmediaflow_notice'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['qmediaflow_notice'] ) ) ); ?></p></div>
            <?php endif; ?>
            <h2>Health</h2>
            <table class="widefat striped" style="max-width:1000px"><tbody>
                <tr><td>Version</td><td><?php echo esc_html( (string) $health['version'] ); ?></td></tr>
                <tr><td>Cache / private writable</td><td><?php echo ! empty( $health['storage']['cache_writable'] ) && ! empty( $health['storage']['private_writable'] ) ? 'Yes' : 'No'; ?></td></tr>
                <tr><td>Signing key</td><td><?php echo ! empty( $health['storage']['signing_key'] ) ? 'Ready' : 'Unavailable'; ?></td></tr>
                <tr><td>Direct gateway</td><td><?php echo ! empty( $health['routing']['gateway_file'] ) && ! empty( $health['routing']['gateway_config'] ) && ! empty( $health['routing']['legacy_cold_route'] ) ? 'Ready' : 'Check routing'; ?></td></tr>
                <tr><td>Immutable static headers</td><td><?php echo ! empty( $health['routing']['immutable_headers'] ) ? 'Configured' : 'Check server rules'; ?></td></tr>
                <tr><td>WebP / AVIF</td><td><?php echo ! empty( $health['encoders']['webp'] ) ? 'WebP yes' : 'WebP no'; ?> · <?php echo ! empty( $health['encoders']['avif'] ) ? 'AVIF yes' : 'AVIF no'; ?></td></tr>
                <tr><td>Effective generators</td><td><?php echo esc_html( (string) $health['workers']['effective_generators'] ); ?> / <?php echo esc_html( (string) $health['workers']['hard_generator_ceiling'] ); ?> hard ceiling</td></tr>
                <tr><td>Browser workers</td><td><?php echo esc_html( (string) $health['workers']['upload_workers'] ); ?></td></tr>
                <tr><td>CDN</td><td><?php echo ! empty( $health['features']['cdn'] ) ? esc_html( (string) $health['distribution']['cdn_base_url'] ) : 'Disabled'; ?></td></tr>
                <tr><td>Object storage</td><td><?php echo ! empty( $health['features']['object_store'] ) ? 'Configured — use wp qmediaflow distribution health for live validation' : 'Disabled'; ?></td></tr>
            </tbody></table>

            <h2>Derivative queue</h2>
            <p>Depth: <strong><?php echo esc_html( (string) $queue['depth'] ); ?></strong> · Failed: <?php echo esc_html( (string) $queue['failed'] ); ?> · Oldest: <?php echo esc_html( (string) $queue['oldest_seconds'] ); ?>s</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qmediaflow_run_queue"><?php wp_nonce_field( 'qmediaflow_run_queue' ); ?>
                <?php submit_button( 'Run Bounded Queue Batch', 'secondary', 'submit', false ); ?>
            </form>

            <h2>Telemetry</h2>
            <table class="widefat striped" style="max-width:1000px"><thead><tr><th>Counter</th><th>Value</th></tr></thead><tbody>
            <?php foreach ( (array) ( $metrics['counters'] ?? array() ) as $name => $value ) : ?>
                <tr><td><code><?php echo esc_html( (string) $name ); ?></code></td><td><?php echo esc_html( (string) $value ); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ( ! empty( $metrics['derived'] ) ) : ?>
                <h3>Timing summaries</h3><table class="widefat striped" style="max-width:1000px"><thead><tr><th>Metric</th><th>Avg</th><th>p50 bucket</th><th>p95 bucket</th><th>p99 bucket</th><th>Max</th></tr></thead><tbody>
                <?php foreach ( $metrics['derived'] as $name => $timing ) : ?>
                    <tr><td><code><?php echo esc_html( (string) $name ); ?></code></td><td><?php echo esc_html( (string) $timing['avg_ms'] ); ?> ms</td><td><?php echo esc_html( (string) $timing['p50_ms'] ); ?></td><td><?php echo esc_html( (string) $timing['p95_ms'] ); ?></td><td><?php echo esc_html( (string) $timing['p99_ms'] ); ?></td><td><?php echo esc_html( (string) $timing['max_ms'] ); ?> ms</td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
                <input type="hidden" name="action" value="qmediaflow_reset_metrics"><?php wp_nonce_field( 'qmediaflow_reset_metrics' ); ?>
                <?php submit_button( 'Reset Telemetry', 'secondary', 'submit', false ); ?>
            </form>
            <p><strong>Deep diagnostics:</strong> run <code>wp qmediaflow health --deep</code> to benchmark encoders and perform the configured object-store write/read/delete cycle.</p>
        </div>
        <?php
    }

    public function run_queue(): void {
        $this->guard( 'qmediaflow_run_queue' );
        $result = Features::instance()->queue()->process();
        $this->redirect( 'Queue processed ' . (int) $result['processed'] . '; ready ' . (int) $result['ready'] . '; failed ' . (int) $result['failed'] . '.' );
    }

    public function reset_metrics(): void {
        $this->guard( 'qmediaflow_reset_metrics' );
        Features::instance()->telemetry()->reset();
        $this->redirect( 'QMediaFlow telemetry reset.' );
    }

    private function guard( string $nonce ): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Insufficient permissions.' ); }
        check_admin_referer( $nonce );
    }

    private function redirect( string $notice ): void {
        wp_safe_redirect( add_query_arg( array( 'page' => 'qmediaflow-operations', 'qmediaflow_notice' => $notice ), admin_url( 'tools.php' ) ) );
        exit;
    }
}
