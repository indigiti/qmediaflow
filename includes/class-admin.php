<?php
namespace MediaFlow;

final class Admin {
    private Paths $paths;
    private Settings $settings;

    public function __construct( Paths $paths, Settings $settings ) {
        $this->paths    = $paths;
        $this->settings = $settings;
    }

    public function hooks(): void {
        add_action( 'admin_post_mediaflow_check_encoding', static fn() => Plugin::instance()->admin()->check_encoding() );
        add_action( 'add_meta_boxes_post', array( $this, 'add_editor_checks' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'dashboard_assets' ) );
        add_action( 'admin_menu', static fn() => Plugin::instance()->admin()->menu() );
        add_action( 'admin_post_mediaflow_save_settings', static fn() => Plugin::instance()->admin()->save_settings() );
        add_action( 'admin_post_mediaflow_rotate_cache', static fn() => Plugin::instance()->admin()->rotate_cache() );
        add_action( 'wp_ajax_mediaflow_lqip_rebuild', static fn() => Plugin::instance()->admin()->lqip_rebuild_ajax() );
    }

    public function add_editor_checks( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }
        add_meta_box( 'qmediaflow-publish-check', 'QMediaFlow image checks', array( $this, 'render_editor_checks' ), 'post', 'side' );
    }

    public function render_editor_checks( \WP_Post $post ): void {
        $id = (int) get_post_thumbnail_id( $post );
        if ( ! $id ) { echo '<p>No featured image saved.</p>'; return; }
        $meta = wp_get_attachment_metadata( $id );
        $width = (int) ( $meta['width'] ?? 0 );
        $bytes = (int) ( $meta['filesize'] ?? 0 );
        $alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
        $minimum = max( 1, (int) apply_filters( 'qmediaflow_editor_hero_min_width', 1200, $post ) );
        $warnings = array();
        if ( '' === $alt ) { $warnings[] = 'No alternative text is saved. Add it if the image conveys information.'; }
        if ( ! $width ) { $warnings[] = 'Featured-image width is not verified.'; }
        elseif ( $width < $minimum ) { $warnings[] = sprintf( 'Featured image is %d px wide; recommended minimum is %d px.', $width, $minimum ); }
        if ( ! $bytes ) { $warnings[] = 'Fallback file size is not verified.'; }
        elseif ( $bytes > $this->settings->newsroom_max_bytes() ) { $warnings[] = sprintf( 'The saved source is %d bytes and exceeds the newsroom byte limit.', $bytes ); }
        echo '<p>Advisory only. Checks reflect the last saved featured image.</p>';
        foreach ( $warnings as $warning ) { echo '<p>' . esc_html( $warning ) . '</p>'; }
        if ( ! $warnings ) { echo '<p>No warnings for the saved image.</p>'; }
    }

    public function menu(): void {
        add_management_page(
            'QMediaFlow',
            'QMediaFlow',
            'manage_options',
            'mediaflow',
            static fn() => Plugin::instance()->admin()->render()
        );
    }

    public function dashboard_assets( string $hook ): void {
        if ( 'tools_page_mediaflow' !== $hook ) { return; }
        wp_enqueue_style( 'qmediaflow-dashboard', QMEDIAFLOW_URL . 'assets/css/admin-dashboard.css', array( 'dashicons' ), QMEDIAFLOW_VERSION );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->paths->ensure();
        $settings         = $this->settings->all();
        $webp             = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
        $avif             = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
        $cache_writable   = is_writable( $this->paths->cache_dir() );
        $private_writable = is_writable( $this->paths->private_dir() );
        $signing_ready = '' !== $this->settings->signing_key();
        $channel = defined( 'QMEDIAFLOW_UPDATE_CHANNEL' ) && 'testing' === QMEDIAFLOW_UPDATE_CHANNEL ? 'Testing' : 'Stable';
        $navigation = array(
            'overview' => array( 'dashboard', 'Overview' ),
            'runtime' => array( 'performance', 'Runtime & delivery' ),
            'verification' => array( 'shield', 'Deployment checks' ),
            'settings' => array( 'admin-settings', 'Media settings' ),
            'previews' => array( 'format-image', 'Preview tools' ),
            'encoders' => array( 'admin-tools', 'Encoder check' ),
            'cache' => array( 'database', 'Cache maintenance' ),
        );
        ?>
        <div class="wrap qmf-dashboard">
            <aside class="qmf-sidebar">
                <div class="qmf-brand"><span class="qmf-mark" aria-hidden="true">Q</span><div><strong>QMediaFlow</strong><small>Media operations</small></div></div>
                <nav aria-label="QMediaFlow workspace">
                    <p class="qmf-kicker">Workspace</p>
                    <?php foreach ( $navigation as $anchor => $item ) : ?>
                        <a href="#qmf-<?php echo esc_attr( $anchor ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $item[0] ); ?>" aria-hidden="true"></span><?php echo esc_html( $item[1] ); ?></a>
                    <?php endforeach; ?>
                </nav>
                <div class="qmf-sidebar-footer">Version <?php echo esc_html( QMEDIAFLOW_VERSION ); ?><br><?php echo esc_html( $channel ); ?> update channel</div>
            </aside>
            <div class="qmf-main">
                <header class="qmf-topbar">
                    <span><strong>QMediaFlow</strong><span class="qmf-divider">/</span>Workspace</span>
                    <div class="qmf-top-actions">
                        <span class="qmf-site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                        <a class="qmf-action" href="https://github.com/indigiti/qmediaflow/releases">GitHub updates</a>
                        <a class="qmf-action" href="#qmf-settings">Settings</a>
                    </div>
                </header>
                <div class="qmf-content" id="qmf-overview">
                    <p class="qmf-kicker qmf-accent">Media operations</p>
                    <h1>Overview</h1>
                    <p class="qmf-intro">Manage image delivery, upload policy, and cache health from one workspace.</p>
                    <div class="qmf-summary">
                        <div class="qmf-card"><span>Public cache</span><strong><?php echo $cache_writable ? 'Writable' : 'Check access'; ?></strong><small>Origin cache storage</small></div>
                        <div class="qmf-card"><span>Private runtime</span><strong><?php echo $private_writable ? 'Writable' : 'Check access'; ?></strong><small>HTTP isolation not verified</small></div>
                        <div class="qmf-card qmf-highlight"><span>Newsroom policy</span><strong><?php echo $this->settings->newsroom_enabled() ? 'Enabled' : 'Disabled'; ?></strong><small><?php echo esc_html( number_format_i18n( $this->settings->newsroom_max_bytes() ) ); ?> byte limit when enabled</small></div>
                        <div class="qmf-card"><span>Update channel</span><strong><?php echo esc_html( $channel ); ?></strong><small>WordPress controls auto-install</small></div>
                    </div>
                    <div class="qmf-grid">
                        <section class="qmf-card" aria-labelledby="qmf-systems-title">
                            <h2 id="qmf-systems-title">Systems at a glance</h2>
                            <p class="qmf-muted">Live configuration and capability checks.</p>
                            <dl class="qmf-status">
                                <?php foreach ( array(
                                    array( 'Signing storage', $signing_ready ? 'Ready' : 'Unavailable', $signing_ready ),
                                    array( 'WebP encoder', $webp ? 'Supported' : 'Unavailable', $webp ),
                                    array( 'AVIF encoder', $avif ? 'Supported' : 'Unavailable', $avif ),
                                    array( 'Warm hits bypass PHP', 'Not verified', false ),
                                ) as $signal ) : ?>
                                    <div><dt><?php echo esc_html( $signal[0] ); ?></dt><dd class="<?php echo $signal[2] ? 'is-ready' : 'is-unverified'; ?>"><?php echo esc_html( $signal[1] ); ?></dd></div>
                                <?php endforeach; ?>
                            </dl>
                        </section>
                        <section class="qmf-card" aria-labelledby="qmf-actions-title">
                            <h2 id="qmf-actions-title">Quick actions</h2>
                            <p class="qmf-muted">Open the tools you need without scrolling through every setting.</p>
                            <div class="qmf-quick">
                                <a href="#qmf-settings">Review upload policy<span aria-hidden="true">↗</span></a>
                                <a href="#qmf-verification">Review deployment checks<span aria-hidden="true">↗</span></a>
                                <a href="#qmf-previews">Manage low-quality previews<span aria-hidden="true">↗</span></a>
                                <a href="#qmf-cache">Review cache maintenance<span aria-hidden="true">↗</span></a>
                            </div>
                        </section>
                    </div>

            <?php if ( isset( $_GET['mediaflow_notice'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['mediaflow_notice'] ) ) ); ?></p></div>
            <?php endif; ?>

                    <section class="qmf-card qmf-panel" id="qmf-runtime">
            <h2>Runtime status</h2>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td>Site scope</td><td><?php echo $this->paths->site_id() ? 'Multisite · Site ' . esc_html( (string) $this->paths->site_id() ) : 'Single site'; ?></td></tr>
                    <tr><td>Public cache directory</td><td><code><?php echo esc_html( $this->paths->cache_dir() ); ?></code></td></tr>
                    <tr><td>Private runtime directory</td><td><code><?php echo esc_html( $this->paths->private_dir() ); ?></code></td></tr>
                    <tr><td>Cache writable</td><td><?php echo $cache_writable ? 'Yes' : 'No'; ?></td></tr>
                    <tr><td>Private runtime writable</td><td><?php echo $private_writable ? 'Yes' : 'No'; ?></td></tr>
                    <tr><td>Signing storage</td><td><?php echo $signing_ready ? 'Ready' : 'Unavailable — image rewriting disabled'; ?></td></tr>
                    <tr><td>Processing limits</td><td><?php echo esc_html( (string) MEDIAFLOW_MAX_GENERATORS ); ?> concurrent encoders; <?php echo esc_html( (string) MEDIAFLOW_MAX_SOURCE_PIXELS ); ?> source pixels; <?php echo esc_html( (string) MEDIAFLOW_MAX_OUTPUT_PIXELS ); ?> output pixels</td></tr>
                    <tr><td>Current cache namespace</td><td><code><?php echo esc_html( $this->settings->cache_namespace() ); ?></code></td></tr>
                    <tr><td>WebP output</td><td><?php echo $webp ? 'Supported' : 'Unavailable'; ?></td></tr>
                    <tr><td>AVIF output</td><td><?php echo $avif ? 'Supported' : 'Unavailable'; ?></td></tr>
                    <tr><td>Filesystem layout</td><td><strong>Sharded for large media libraries</strong></td></tr>
                    <tr><td>Cached image hot path</td><td><strong>Not verified</strong> — confirm a warm hit in origin access logs with no PHP upstream.</td></tr>
                    <tr><td>Auto-LQIP background worker</td><td><?php echo defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron spawning disabled — use a real server cron or optional pre-warm.' : 'WP-Cron spawning enabled — queue drain not verified.'; ?></td></tr>
                    <tr><td>Cache statistics</td><td>Not scanned automatically; MediaFlow avoids large directory enumeration in wp-admin.</td></tr>
                </tbody>
            </table>

                    </section>
                    <section class="qmf-card qmf-panel" id="qmf-verification">
            <h2>Deployment verification</h2>
            <p>Private and legacy HTTP paths: <strong>Not verified</strong>. Verify origin and CDN separately; filesystem writability is not an access check.</p>
            <p>Warm cache headers: <strong>Not verified</strong>. Check a generated derivative for public immutable caching and a fallback for no-store.</p>
                    </section>
                    <section class="qmf-card qmf-panel" id="qmf-settings">
            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="mediaflow_save_settings">
                <?php wp_nonce_field( 'mediaflow_save_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mediaflow-core-mode">Physical WordPress sub-sizes</label></th>
                        <td>
                            <select id="mediaflow-core-mode" name="core_size_mode">
                                <option value="safe" <?php selected( $settings['core_size_mode'], 'safe' ); ?>>Safe — keep WordPress/theme physical sizes</option>
                                <option value="adaptive" <?php selected( $settings['core_size_mode'], 'adaptive' ); ?>>Adaptive — keep thumbnail + Site Icon only</option>
                                <option value="strict" <?php selected( $settings['core_size_mode'], 'strict' ); ?>>Strict — keep Site Icon only</option>
                            </select>
                            <p class="description">Adaptive is recommended while testing theme/plugin compatibility. Integrations can preserve additional physical sizes through the <code>mediaflow_preserved_physical_sizes</code> filter.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-format">Preferred format</label></th>
                        <td>
                            <select id="mediaflow-format" name="format">
                                <option value="auto" <?php selected( $settings['format'], 'auto' ); ?>>Auto (prefer WebP for fast generation)</option>
                                <option value="webp" <?php selected( $settings['format'], 'webp' ); ?>>WebP</option>
                                <option value="avif" <?php selected( $settings['format'], 'avif' ); ?>>AVIF</option>
                                <option value="original" <?php selected( $settings['format'], 'original' ); ?>>Original format</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-quality">Quality</label></th>
                        <td><input id="mediaflow-quality" type="number" min="1" max="100" name="quality" value="<?php echo esc_attr( (string) $settings['quality'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Browser upload optimization</th>
                        <td>
                            <label><input type="checkbox" name="upload_optimizer_enabled" value="1" <?php checked( ! empty( $settings['upload_optimizer_enabled'] ) ); ?>> Convert selected images to WebP in the browser before WordPress upload</label>
                            <p class="description">Pipeline: validate source → normalize orientation → constrain dimensions → Web Worker WebP encode → binary-search quality toward 480 KB → reduce dimensions when needed → hard validation at 500 KB. The server does not recompress this source upload.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Upload dimensions</th>
                        <td>
                            <label for="mediaflow-upload-max-width">Max width</label>
                            <input id="mediaflow-upload-max-width" type="number" min="320" max="8192" name="upload_max_width" value="<?php echo esc_attr( (string) $settings['upload_max_width'] ); ?>" style="width:100px"> px
                            &nbsp;&nbsp;
                            <label for="mediaflow-upload-max-height">Max height</label>
                            <input id="mediaflow-upload-max-height" type="number" min="320" max="8192" name="upload_max_height" value="<?php echo esc_attr( (string) $settings['upload_max_height'] ); ?>" style="width:100px"> px
                            <p class="description">Images are never upscaled. Default 2560 × 2560 mirrors WordPress's traditional large-image threshold while keeping MediaFlow's browser pipeline explicit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-upload-source-mb">Maximum original image size</label></th>
                        <td><input id="mediaflow-upload-source-mb" type="number" min="1" max="100" name="upload_max_source_mb" value="<?php echo esc_attr( (string) $settings['upload_max_source_mb'] ); ?>" style="width:90px"> MB <span class="description">Checked in the browser before decoding; default 25 MB.</span></td>
                    </tr>
                    <tr>
                        <th scope="row">Image encoding</th>
                        <td>
                            <label><input type="checkbox" name="progressive_jpeg" value="1" <?php checked( ! empty( $settings['progressive_jpeg'] ) ); ?>> Progressive JPEG</label><br>
                            <label><input type="checkbox" name="interlaced_png" value="1" <?php checked( ! empty( $settings['interlaced_png'] ) ); ?>> Interlaced PNG (Adam7)</label>
                            <p class="description">Applies to generated JPEG/PNG files only. Select Original format to retain the source format. WebP/AVIF use placeholders instead. Changes rotate this site's image cache namespace; clear page caches to refresh existing markup. Unsupported editors keep their normal output.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-placeholder">Progressive placeholder</label></th>
                        <td>
                            <select id="mediaflow-placeholder" name="placeholder_mode">
                                <option value="auto" <?php selected( $settings['placeholder_mode'], 'auto' ); ?>>Auto LQIP — recommended; gradient first, LQIP learns in background</option>
                                <option value="lqip" <?php selected( $settings['placeholder_mode'], 'lqip' ); ?>>Inline LQIP — no extra request; pre-generation recommended</option>
                                <option value="gradient" <?php selected( $settings['placeholder_mode'], 'gradient' ); ?>>Generated Gradient — deterministic visual fallback only</option>
                                <option value="color" <?php selected( $settings['placeholder_mode'], 'color' ); ?>>Solid Color — lowest overhead</option>
                                <option value="none" <?php selected( $settings['placeholder_mode'], 'none' ); ?>>Disabled</option>
                            </select>
                            <input type="text" name="placeholder_color" value="<?php echo esc_attr( (string) $settings['placeholder_color'] ); ?>" class="small-text" pattern="#[A-Fa-f0-9]{6}" aria-label="Placeholder color">
                            <p class="description"><strong>Auto LQIP:</strong> first view uses a stable generated gradient and queues a bounded background job; later views use a tiny static LQIP from the public cache. The Auto URL includes the source revision, so image replacements cannot reuse an old immutable preview. Inline LQIP remains available for sites that prefer zero placeholder requests.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-lqip-width">LQIP preview size</label></th>
                        <td>
                            <select id="mediaflow-lqip-width" name="lqip_width">
                                <?php foreach ( array( 16 => '16px — Ultra light', 32 => '32px — Light', 64 => '64px — Recommended', 128 => '128px — High detail', 256 => '256px — Expensive' ) as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (int) $settings['lqip_width'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">64px is the recommended balance. MediaFlow can automatically step down if the encoded preview exceeds the byte budget.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-lqip-quality">LQIP quality</label></th>
                        <td><input id="mediaflow-lqip-quality" type="number" min="8" max="50" name="lqip_quality" value="<?php echo esc_attr( (string) $settings['lqip_quality'] ); ?>"> <span class="description">Recommended: 24</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-lqip-bytes">Maximum LQIP bytes</label></th>
                        <td><input id="mediaflow-lqip-bytes" type="number" min="768" max="16384" step="128" name="lqip_max_bytes" value="<?php echo esc_attr( (string) $settings['lqip_max_bytes'] ); ?>"> <span class="description">Inline mode measures the complete data URI; Auto mode measures the encoded static preview file. Recommended: 3072 bytes.</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-lqip-page">Maximum LQIP previews per page</label></th>
                        <td><input id="mediaflow-lqip-page" type="number" min="0" max="100" name="lqip_max_per_request" value="<?php echo esc_attr( (string) $settings['lqip_max_per_request'] ); ?>"> <span class="description">Recommended: 8. Auto mode uses gradients beyond this limit; Inline mode falls back to the configured solid color.</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-lqip-total-bytes">Maximum total inline LQIP bytes per page</label></th>
                        <td><input id="mediaflow-lqip-total-bytes" type="number" min="4096" max="262144" step="1024" name="lqip_max_total_bytes" value="<?php echo esc_attr( (string) $settings['lqip_max_total_bytes'] ); ?>"> <span class="description">Request-global safety budget. Recommended: 24576 bytes (24 KB).</span></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-widths">Responsive widths</label></th>
                        <td>
                            <input id="mediaflow-widths" class="regular-text" name="widths" value="<?php echo esc_attr( implode( ', ', (array) $settings['widths'] ) ); ?>">
                            <p class="description">Allowed candidates only. Each rendered image receives a bounded subset; variants are generated on demand.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mediaflow-candidates">Max srcset candidates</label></th>
                        <td><input id="mediaflow-candidates" type="number" min="2" max="6" name="max_srcset_candidates" value="<?php echo esc_attr( (string) $settings['max_srcset_candidates'] ); ?>"></td>
                    </tr>
                </table>
                <h3>Newsroom upload policy</h3>
                <p><label><input type="checkbox" name="newsroom_enabled" value="1" <?php checked( ! empty( $settings['newsroom_enabled'] ) ); ?>> Enforce on WordPress upload and sideload hooks</label></p>
                <fieldset><legend>Allowed image types</legend>
                <?php foreach ( array( 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP', 'image/png' => 'PNG', 'image/avif' => 'AVIF' ) as $mime => $label ) : ?>
                    <label><input type="checkbox" name="newsroom_mimes[]" value="<?php echo esc_attr( $mime ); ?>" <?php checked( in_array( $mime, $this->settings->newsroom_mimes(), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                <?php endforeach; ?>
                </fieldset>
                <p><label>Maximum bytes <input type="number" min="1" max="100000000" name="newsroom_max_bytes" value="<?php echo esc_attr( (string) $settings['newsroom_max_bytes'] ); ?>"></label></p>
                <p>Effective: <?php echo $this->settings->newsroom_enabled() ? 'Enabled' : 'Disabled'; ?> · <?php echo esc_html( implode( ', ', $this->settings->newsroom_mimes() ) ); ?> · <?php echo esc_html( (string) $this->settings->newsroom_max_bytes() ); ?> bytes. An empty format selection restores JPEG and WebP. Browser optimization is independent and outputs WebP only. Constants in wp-config.php override the saved enable flag and byte limit.</p>
                <?php submit_button( 'Save MediaFlow Settings' ); ?>
            </form>

                    </section>
                    <section class="qmf-card qmf-panel" id="qmf-previews">
            <h2>LQIP pre-warm <span style="font-weight:400">(optional)</span></h2>
            <p><strong>Auto LQIP does not require a full rebuild.</strong> Existing images learn on first use: a deterministic gradient is shown immediately, one queue marker is created, and the WP-Cron worker generates the LQIP in bounded background runs. Use pre-warm only when you want existing Media Library images ready before visitors request them.</p>
            <p><button type="button" class="button button-secondary" id="mediaflow-lqip-rebuild">Pre-warm / Refresh Existing LQIPs</button></p>
            <div id="mediaflow-lqip-progress" style="max-width:900px;display:none">
                <div style="height:10px;background:#dcdcde;border-radius:10px;overflow:hidden"><div id="mediaflow-lqip-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="mediaflow-lqip-status" aria-live="polite">Ready.</p>
            </div>
            <script>
            (function(){
                const button=document.getElementById('mediaflow-lqip-rebuild');
                if(!button) return;
                const box=document.getElementById('mediaflow-lqip-progress');
                const bar=document.getElementById('mediaflow-lqip-bar');
                const status=document.getElementById('mediaflow-lqip-status');
                let cursor=0, processed=0, failed=0, running=false;
                const endpoint=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                const nonce=<?php echo wp_json_encode( wp_create_nonce( 'mediaflow_lqip_rebuild' ) ); ?>;
                async function rebuild(){
                    let done=false;
                    while(!done){
                        const body=new URLSearchParams({action:'mediaflow_lqip_rebuild',_ajax_nonce:nonce,cursor:String(cursor),batch:'5'});
                        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
                        const result=await response.json();
                        if(!result.success) throw new Error((result.data&&result.data.message)||'LQIP rebuild failed.');
                        cursor=Number(result.data.cursor||cursor); processed+=Number(result.data.processed||0); failed+=Number(result.data.failed||0); done=Boolean(result.data.done);
                        const attempted=processed+failed;
                        const pct=done?100:Math.min(95,15+Math.log10(Math.max(10,attempted))*20);
                        bar.style.width=pct+'%';
                        status.textContent='LQIP ready '+processed+'; failed '+failed+'. Last attachment ID '+cursor+'.';
                    }
                    bar.style.width='100%'; status.textContent='Complete. LQIP ready '+processed+'; failed '+failed+'. Auto LQIP URLs become useful without rebuilding page HTML; Inline LQIP may require a page-cache purge.';
                    button.disabled=false; button.textContent='Pre-warm / Refresh Existing LQIPs'; running=false;
                }
                button.addEventListener('click',async()=>{
                    if(running) return; running=true; cursor=0; processed=0; failed=0; button.disabled=true; button.textContent='Rebuilding…'; box.style.display='block'; bar.style.width='3%'; status.textContent='Starting bounded rebuild…';
                    try{ await rebuild(); }catch(e){ status.textContent=e.message; button.disabled=false; button.textContent='Retry LQIP Rebuild'; running=false; }
                });
            })();
            </script>

                    </section>
                    <section class="qmf-card qmf-panel" id="qmf-encoders">
            <h2>Encoder compatibility check</h2>
            <p>Creates tiny JPEG/PNG test files and checks their encoding headers. Run after a server or image-library update. VERIFIED confirms the selected encoder produced the requested mode.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mediaflow_check_encoding">
                <?php wp_nonce_field( 'mediaflow_check_encoding' ); submit_button( 'Check JPEG / PNG encoders', 'secondary' ); ?>
            </form>
                    </section>
                    <section class="qmf-card qmf-panel" id="qmf-cache">
            <h2>Logical cache purge</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mediaflow_rotate_cache">
                <?php wp_nonce_field( 'mediaflow_rotate_cache' ); ?>
                <?php submit_button( 'Rotate Cache Namespace', 'secondary' ); ?>
                <p class="description">O(1) purge: new HTML immediately uses a fresh cache namespace. Existing immutable files stay valid for already-cached pages and can be removed later by maintenance tooling; wp-admin never recursively deletes millions of files.</p>
            </form>
                    </section>
                    <p class="qmf-footer">QMediaFlow reports local configuration and capabilities. Traffic, cache hit rates, and CDN health are not measured here.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function lqip_rebuild_ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
        check_ajax_referer( 'mediaflow_lqip_rebuild' );
        if ( ! in_array( $this->settings->placeholder_mode(), array( 'auto', 'lqip' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Select Auto LQIP or Inline LQIP and save MediaFlow settings before pre-warming previews.' ), 400 );
        }
        $cursor = absint( $_POST['cursor'] ?? 0 );
        $batch  = max( 1, min( 10, absint( $_POST['batch'] ?? 5 ) ) );
        $started = microtime( true );
        $time_budget = 8.0;

        global $wpdb;
        $mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
        $placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d AND post_mime_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d";
        $params = array_merge( array( $cursor ), $mimes, array( $batch ) );
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

        $processed = 0;
        $failed = 0;
        $attempted = 0;
        foreach ( (array) $ids as $id ) {
            if ( $attempted > 0 && microtime( true ) - $started >= $time_budget ) {
                break;
            }
            $cursor = absint( $id );
            $ready = Plugin::instance()->manifests()->rebuild_lqip( $cursor );
            ++$attempted;
            if ( $ready ) { ++$processed; }
            else { ++$failed; }
        }
        $exhausted_query = count( (array) $ids ) < $batch;
        $consumed_query  = $attempted >= count( (array) $ids );
        wp_send_json_success( array(
            'cursor'     => $cursor,
            'processed'  => $processed,
            'failed'     => $failed,
            'attempted'  => $attempted,
            'elapsed_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'done'       => $exhausted_query && $consumed_query,
        ) );
    }

    public function check_encoding(): void {
        $this->guard( 'mediaflow_check_encoding' );
        $this->redirect( Encoding::check( $this->paths ) );
    }

    public function save_settings(): void {
        $this->guard( 'mediaflow_save_settings' );
        try { $this->settings->save( wp_unslash( $_POST ) ); } catch ( \RuntimeException $e ) { wp_die( esc_html( $e->getMessage() ) ); }
        $this->redirect( 'MediaFlow settings saved.' );
    }

    public function rotate_cache(): void {
        $this->guard( 'mediaflow_rotate_cache' );
        try { $namespace = $this->settings->rotate_cache_namespace(); } catch ( \RuntimeException $e ) { wp_die( esc_html( $e->getMessage() ) ); }
        $this->redirect( 'MediaFlow cache namespace rotated to ' . $namespace . '. New requests will regenerate on demand.' );
    }

    private function guard( string $nonce_action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( $nonce_action );
    }

    private function redirect( string $notice ): void {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'             => 'mediaflow',
                    'mediaflow_notice' => $notice,
                ),
                admin_url( 'tools.php' )
            )
        );
        exit;
    }
}
