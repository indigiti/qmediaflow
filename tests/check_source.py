"""Source syntax/contracts and filesystem-lock checks, not a WordPress integration test.
Requires Python 3, tree-sitter and tree-sitter-php. Run from any directory.
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
    start.wait()
    slot=None
    for i in range(2):
        handle=open(Path(directory)/f'slot-{i}','a')
        try: fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: handle.close(); continue
        slot=handle; break
    if slot is None: results.put('busy'); return
    with guard:
        active.value+=1; peak.value=max(peak.value,active.value)
    time.sleep(.15)
    with guard: active.value-=1
    fcntl.flock(slot,fcntl.LOCK_UN);slot.close();results.put('generated')
def main():
    files=list(ROOT.rglob('*.php'))
    if Parser is not None:
        parser=Parser(Language(tree_sitter_php.language_php()))
        for path in files:
            assert not parser.parse(path.read_bytes()).root_node.has_error, path
        print(f'PASS: PHP syntax tree parsing ({len(files)} files)')
    else:
        for path in files:
            result=subprocess.run(['php','-l',str(path)],capture_output=True,text=True)
            assert result.returncode == 0, result.stdout + result.stderr
        print(f'PASS: PHP lint fallback ({len(files)} files; tree-sitter unavailable)')
    handler=(ROOT/'includes/class-request-handler.php').read_text()
    assert handler.index('validate_signature(')<handler.index('$this->manifests->get(')
    print('PASS: signature validation precedes manifest recovery')
    paths=(ROOT/'includes/class-paths.php').read_text()
    public_block=paths.split('private function public_htaccess_block()',1)[1].split('private function front_controller_url_path()',1)[0]
    assert 'Options -Indexes' not in public_block
    assert 'RewriteCond %{REQUEST_FILENAME} !-f' in public_block
    assert 'RewriteRule ^(?:sites/' in public_block
    plugin=(ROOT/'includes/class-plugin.php').read_text()
    assert '$this->paths->upgrade_guards_if_needed();' in plugin
    print('PASS: v0.2.3 Apache guard has no Options directive and cold-miss migration boots early')
    settings=(ROOT/'includes/class-settings.php').read_text()
    manifest=(ROOT/'includes/class-manifest-store.php').read_text()
    responsive=(ROOT/'includes/class-responsive.php').read_text()
    assert "'placeholder_mode'     => 'auto'" in settings
    assert "'lqip_width'           => 64" in settings
    assert 'array( 16, 32, 64, 128, 256 )' in settings
    assert "array( 'none', 'color', 'gradient', 'lqip', 'auto' )" in settings
    assert 'lqip_signature()' in settings and 'placeholder_signature' in manifest
    assert 'Migrating them into Auto mode must not perform attachment DB lookups' in manifest
    assert 'queue_lqip(' in manifest and 'process_lqip_queue(' in manifest
    assert 'lqip_file_ready(' in manifest and 'write_lqip_asset(' in manifest
    assert 'lqip_max_per_request()' in responsive
    assert 'lqip_max_total_bytes()' in responsive and 'private static int $inline_lqip_count' in responsive and 'private static int $inline_lqip_bytes' in responsive
    assert 'private static int $auto_lqip_count' in responsive and 'gradient_css(' in responsive and 'auto-lqip' in responsive
    assert manifest.count('wp_get_image_editor( $source )') == 1 and 'encode_placeholder_editor' in manifest
    admin=(ROOT/'includes/class-admin.php').read_text()
    cli=(ROOT/'includes/class-cli.php').read_text()
    assert "batch:'5'" in admin and '$time_budget = 8.0;' in admin
    assert 'Auto LQIP does not require a full rebuild' in admin
    assert "$assoc_args['limit'] ?? 100" in cli and "if ( 'lqip' === $namespace ) { continue; }" in cli
    assert 'private bool $ensured = false' in paths and 'private bool $guards_checked = false' in paths
    assert '/lqip/' in paths and 'generation-locks/slot-' in paths
    assert '__mediaflow-lqip-pending.svg' in paths and 'no-store, max-age=0' in paths
    main=(ROOT/'mediaflow.php').read_text()
    assert "QMEDIAFLOW_VERSION', '0.2.9'" in main
    assert "MEDIAFLOW_VERSION', QMEDIAFLOW_VERSION" in main
    assert "MEDIAFLOW_ROUTING_SCHEMA_VERSION', QMEDIAFLOW_ROUTING_SCHEMA_VERSION" in main and '$routing_version' in paths
    assert 'qmediaflow_url' in main and 'qmediaflow_image' in main
    assert "add_command( 'qmediaflow'" in main
    branding=(ROOT/'includes/class-branding.php').read_text()
    assert "'QMediaFlow'" in branding and "str_replace( 'MediaFlow', 'QMediaFlow'" in branding
    print('PASS: v0.2.9 QMediaFlow compatibility and branding contracts')
    upload=(ROOT/'includes/class-upload-optimizer.php').read_text()
    upload_js=(ROOT/'assets/js/mediaflow-upload.js').read_text()
    upload_worker=(ROOT/'assets/js/mediaflow-upload-worker.js').read_text()
    assert 'TARGET_BYTES = 480000' in upload and 'HARD_BYTES   = 500000' in upload
    assert 'wp_handle_upload_prefilter' in upload and 'wp_handle_sideload_prefilter' in upload
    assert 'wp_client_side_media_processing_enabled' in upload
    assert "'image/webp' !== $mime" in upload and 'size > self::HARD_BYTES' in upload
    assert 'createImageBitmap' in upload_js and "imageOrientation: 'from-image'" in upload_js
    assert 'X-MediaFlow-Upload' in upload_js and 'transformFormData' in upload_js
    assert 'OffscreenCanvas' in upload_worker and 'bestQuality' in upload_worker
    assert 'DIMENSION_STEP = 0.90' in upload_worker and "type: 'image/webp'" in upload_worker
    print('PASS: browser upload pipeline, Web Worker WebP search and 500 KB server guard contracts')
    processor=(ROOT/'includes/class-processor.php').read_text()
    assert '@unlink( $lock_path )' not in processor
    assert processor.index('Budget::check(')<processor.index('wp_get_image_editor(')
    assert processor.index('Budget::acquire(')<processor.index('wp_get_image_editor(')
    print('PASS: stable lock lifecycle and admission before decode')
    with tempfile.TemporaryDirectory() as d:
        active=multiprocessing.Value('i',0);peak=multiprocessing.Value('i',0)
        guard=multiprocessing.Lock();start=multiprocessing.Event();results=multiprocessing.Queue()
        jobs=[multiprocessing.Process(target=worker,args=(d,active,peak,guard,start,results)) for _ in range(24)]
        for job in jobs: job.start()
        start.set()
        for job in jobs: job.join(5);assert job.exitcode==0
        values=[results.get(timeout=1) for _ in jobs]
        assert 1<=peak.value<=2 and 'busy' in values
        # Slot is reusable after owners exit; inode stays present.
        for i in range(2):
            with open(Path(d)/f'slot-{i}','a') as h: fcntl.flock(h,fcntl.LOCK_EX|fcntl.LOCK_NB)
        print(f'PASS: OS lock stress: 24 processes, peak encoders {peak.value}, busy responses {values.count("busy")}, slots reusable')
    print('NOT RUN: PHP execution, WordPress hooks, GD/Imagick, HTTP routing, browser QA and production load tests')
if __name__=='__main__':main()
