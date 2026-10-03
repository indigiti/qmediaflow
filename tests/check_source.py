"""Source syntax/contracts and filesystem-lock checks, not a full WordPress integration test.
Requires Python 3; tree-sitter is optional because PHP lint is the fallback.
"""
from pathlib import Path
try:
    from tree_sitter import Language, Parser
    import tree_sitter_php
except ModuleNotFoundError:
    Language = Parser = tree_sitter_php = None
import subprocess
import tempfile, fcntl, multiprocessing, time
ROOT=Path(__file__).resolve().parents[1]
def worker(directory, active, peak, guard, start, results):
    start.wait();slot=None
    for i in range(2):
        handle=open(Path(directory)/f'slot-{i}','a')
        try: fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: handle.close();continue
        slot=handle;break
    if slot is None: results.put('busy');return
    with guard: active.value+=1;peak.value=max(peak.value,active.value)
    time.sleep(.15)
    with guard: active.value-=1
    fcntl.flock(slot,fcntl.LOCK_UN);slot.close();results.put('generated')
def main():
    files=list(ROOT.rglob('*.php'))
    if Parser is not None:
        parser=Parser(Language(tree_sitter_php.language_php()))
        for path in files: assert not parser.parse(path.read_bytes()).root_node.has_error, path
        print(f'PASS: PHP syntax tree parsing ({len(files)} files)')
    else:
        for path in files:
            result=subprocess.run(['php','-l',str(path)],capture_output=True,text=True)
            assert result.returncode == 0, result.stdout + result.stderr
        print(f'PASS: PHP lint fallback ({len(files)} files; tree-sitter unavailable)')

    handler=(ROOT/'includes/class-request-handler.php').read_text()
    assert handler.index('validate_signature(')<handler.index('$this->manifests->get(')
    paths=(ROOT/'includes/class-paths.php').read_text()
    public_block=paths.split('private function public_htaccess_block()',1)[1].split('private function front_controller_url_path()',1)[0]
    assert 'Options -Indexes' not in public_block
    assert 'RewriteCond %{REQUEST_FILENAME} !-f' in public_block and 'RewriteRule ^(?:sites/' in public_block
    plugin=(ROOT/'includes/class-plugin.php').read_text()
    assert '$this->paths->upgrade_guards_if_needed();' in plugin
    print('PASS: signature validation, Apache migration and static-hit routing contracts')

    settings=(ROOT/'includes/class-settings.php').read_text();manifest=(ROOT/'includes/class-manifest-store.php').read_text();responsive=(ROOT/'includes/class-responsive.php').read_text()
    assert "'placeholder_mode'     => 'auto'" in settings and "'lqip_width'           => 64" in settings
    assert 'array( 16, 32, 64, 128, 256 )' in settings and "array( 'none', 'color', 'gradient', 'lqip', 'auto' )" in settings
    assert 'lqip_signature()' in settings and 'placeholder_signature' in manifest
    assert 'queue_lqip(' in manifest and 'process_lqip_queue(' in manifest and 'lqip_file_ready(' in manifest and 'write_lqip_asset(' in manifest
    assert 'lqip_max_per_request()' in responsive and 'lqip_max_total_bytes()' in responsive
    assert 'private static int $auto_lqip_count' in responsive and 'gradient_css(' in responsive
    assert manifest.count('wp_get_image_editor( $source )') == 1 and 'encode_placeholder_editor' in manifest
    print('PASS: adaptive LQIP and bounded responsive contracts preserved')

    main=(ROOT/'mediaflow.php').read_text();branding=(ROOT/'includes/class-branding.php').read_text()
    assert "QMEDIAFLOW_VERSION', '0.3.0'" in main and "QMEDIAFLOW_ROUTING_SCHEMA_VERSION', '5'" in main
    assert "MEDIAFLOW_VERSION', QMEDIAFLOW_VERSION" in main and "MEDIAFLOW_ROUTING_SCHEMA_VERSION', QMEDIAFLOW_ROUTING_SCHEMA_VERSION" in main
    assert 'qmediaflow_url' in main and 'qmediaflow_image' in main and 'qmediaflow_picture' in main
    assert 'qmediaflow-gateway.php' in main and "'QMediaFlow'" in branding
    print('PASS: v0.3.0 QMediaFlow naming, compatibility aliases and API surface')

    upload=(ROOT/'includes/class-upload-optimizer.php').read_text();upload_js=(ROOT/'assets/js/mediaflow-upload.js').read_text();upload_worker=(ROOT/'assets/js/mediaflow-upload-worker.js').read_text()
    assert 'TARGET_BYTES = 480000' in upload and 'HARD_BYTES   = 500000' in upload
    assert 'wp_handle_upload_prefilter' in upload and 'wp_handle_sideload_prefilter' in upload and 'wp_client_side_media_processing_enabled' in upload
    assert "'image/webp' !== $mime" in upload and 'size > self::HARD_BYTES' in upload and "'maxWorkers'" in upload
    assert 'createImageBitmap' in upload_js and "imageOrientation: 'from-image'" in upload_js and 'MAX_WORKERS' in upload_js and 'parallelMap' in upload_js
    assert 'X-MediaFlow-Upload' in upload_js and 'X-QMediaFlow-Upload-Workers' in upload_js and 'transformFormData' in upload_js
    assert 'OffscreenCanvas' in upload_worker and 'bestQuality' in upload_worker and 'DIMENSION_STEP = 0.90' in upload_worker
    assert 'MIN_DIMENSION_STEP = 0.65' in upload_worker and 'dimensionScale(' in upload_worker and 'Math.sqrt(targetBytes / encodedBytes)' in upload_worker
    assert 'MAX_DIMENSION_PASSES = 32' in upload_worker
    print('PASS: browser optimizer retains byte contract, bounded backpressure and adaptive dimension convergence')

    processor=(ROOT/'includes/class-processor.php').read_text();budget=(ROOT/'includes/class-budget.php').read_text()
    assert '@unlink( $lock_path )' not in processor
    assert processor.index('Budget::check(')<processor.index('wp_get_image_editor(') and processor.index('Budget::acquire(')<processor.index('wp_get_image_editor(')
    assert 'focal_crop_box' in processor and 'distribution()->enqueue_file' in processor and "Telemetry::timing( 'generation_ms'" in processor
    assert 'Runtime_Config::generator_limit()' in budget
    print('PASS: generation admission, focal crop, telemetry, distribution and adaptive concurrency contracts')

    gateway=(ROOT/'qmediaflow-gateway.php').read_text();runtime=(ROOT/'includes/class-runtime-config.php').read_text()
    assert 'wp-load.php' not in gateway and 'require_once' not in gateway
    assert 'hash_hmac' in gateway and 'LOCK_EX | LOCK_NB' in gateway and "Cache-Control: public, max-age=31536000, immutable" in gateway
    assert "Cache-Control: no-store, max-age=0" in gateway and '@rename( $temp, $target )' in gateway
    assert 'register_shutdown_function( \'qmf_flush_metrics\' )' in gateway and 'function qmf_flush_metrics()' in gateway
    assert '__qmediaflow-gateway-config.php' in runtime and 'ensure_extended_route' in runtime and '-fx[0-9]{1,3}-fy[0-9]{1,3}-s' in runtime
    assert '.qmediaflow-gateway-state' in runtime and "hash( 'sha256', serialize( $config ) )" in runtime
    assert runtime.index("$installed   = is_readable( $state_path )") < runtime.index("$published['generated_at'] = time()")
    print('PASS: standalone gateway stays WordPress-free, non-blocking and configuration sync is change-driven')

    queue=(ROOT/'includes/class-derivative-queue.php').read_text();warmer=(ROOT/'includes/class-warmer.php').read_text();features=(ROOT/'includes/class-features.php').read_text()
    assert "@fopen( $path, 'x' )" in queue and "'0-critical'" in queue and 'as_enqueue_async_action' in queue and 'wp_schedule_single_event' in queue
    assert 'QMEDIAFLOW_MAX_WARM_VARIANTS' in runtime and 'get_post_thumbnail_id' in warmer and 'content_attachment_ids' in warmer
    assert "add_action( 'switch_blog'" in features and 'reload_context' in features
    print('PASS: deduplicated priority queue, bounded critical warming and multisite feature reload')

    distribution=(ROOT/'includes/class-distribution.php').read_text();s3=(ROOT/'includes/class-s3-store.php').read_text();picture=(ROOT/'includes/class-picture.php').read_text()
    assert 'QMEDIAFLOW_CDN_BASE_URL' in runtime and 'qmediaflow_cdn_base_url' in runtime
    assert 'AWS4-HMAC-SHA256' in s3 and 'QMEDIAFLOW_S3_ENDPOINT' in s3 and 'health()' in s3
    assert 'distribution-queue' in distribution and 'public_url' in distribution
    assert "substr( $identity, 0, 2 )" in distribution and "substr( $identity, 2, 2 )" in distribution and 'function job_paths()' in distribution
    assert 'private string $public_base' in distribution and 'rewrites_urls()' in distribution
    assert "array( 'avif' => 'image/avif', 'webp' => 'image/webp' )" in picture and 'max_picture_candidates' in picture
    assert 'private static array $format_support' in picture and 'Focal_Point::runtime' in picture
    print('PASS: CDN/S3 distribution is sharded, URL policy is cached, and picture capability checks are bounded')

    policy=(ROOT/'includes/class-encoding-policy.php').read_text();smart=(ROOT/'includes/class-smart-delivery.php').read_text();telemetry=(ROOT/'includes/class-telemetry.php').read_text();health=(ROOT/'includes/class-health.php').read_text()
    assert 'QMEDIAFLOW_CONTENT_AWARE_ENCODING' in runtime and 'bytes / $pixels' in policy
    assert 'private static array $source_bytes_cache' in policy
    assert 'smart_srcset' in smart and 'Encoding_Policy::quality' in smart
    assert "! str_contains( $html, '-c1-' )" in smart and 'Focal_Point::runtime' in smart
    assert 'p95_ms' in telemetry and 'gateway-metrics.json' in telemetry
    assert 'pending_counters' in telemetry and 'pending_timings' in telemetry and 'register_shutdown_function' in telemetry
    assert 'LOCK_EX | LOCK_NB' in telemetry and 'flush( false )' in telemetry
    assert 'effective_generators' in health and 'immutable_headers' in health and 'encoder_benchmark' in health
    print('PASS: smart delivery fast exits, cached encoding inputs and non-blocking buffered telemetry')

    focal=(ROOT/'includes/class-focal-point.php').read_text();focal_resolver=(ROOT/'includes/class-focal-resolver.php').read_text();rest=(ROOT/'includes/class-rest-api.php').read_text();woo=(ROOT/'includes/class-woocommerce-adapter.php').read_text();migration=(ROOT/'includes/class-thumbnail-migrator.php').read_text();ops=(ROOT/'includes/class-ops-cli.php').read_text()
    assert '_qmediaflow_focal_x' in focal and "'-fx%d-fy%d'" in (ROOT/'includes/class-variant.php').read_text()
    assert 'public static function runtime' in focal and "'/focal/'" in focal and 'delete_runtime(' in focal
    runtime_body=focal.split('public static function runtime',1)[1].split('public static function delete_runtime',1)[0]
    assert 'get_post_meta' not in runtime_body
    assert '$variant->focal_x' in focal_resolver and 'qmediaflow/v1' in rest and "permission_callback' => '__return_true'" in rest and 'Focal_Point::runtime' in rest
    assert 'delete_attachment_runtime' in features
    assert 'woocommerce_gallery_thumbnail' in woo and '_product_image_gallery' in warmer
    assert 'thumbnail-migration/quarantine' in migration and 'rollback(' in migration and 'Dry run only' in ops
    assert "'qmediaflow health'" in ops and "'qmediaflow thumbnails migrate'" in ops
    print('PASS: focal delivery is zero-DB, runtime state cleans up, and ecosystem adapters remain bounded')

    with tempfile.TemporaryDirectory() as d:
        active=multiprocessing.Value('i',0);peak=multiprocessing.Value('i',0);guard=multiprocessing.Lock();start=multiprocessing.Event();results=multiprocessing.Queue()
        jobs=[multiprocessing.Process(target=worker,args=(d,active,peak,guard,start,results)) for _ in range(24)]
        for job in jobs: job.start()
        start.set()
        for job in jobs: job.join(5);assert job.exitcode==0
        values=[results.get(timeout=1) for _ in jobs]
        assert 1<=peak.value<=2 and 'busy' in values
        for i in range(2):
            with open(Path(d)/f'slot-{i}','a') as h: fcntl.flock(h,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print(f'PASS: OS lock stress: 24 processes, peak encoders {peak.value}, busy responses {values.count("busy")}, slots reusable')
    print('NOT RUN HERE: live WordPress theme/plugin compatibility, real CDN/S3 credentials, Nginx configuration and production load testing')
if __name__=='__main__':main()
