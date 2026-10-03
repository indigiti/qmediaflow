<?php
$root = dirname( __DIR__ );
$read = static function ( string $path ) use ( $root ): string {
    $data = file_get_contents( $root . '/' . $path );
    if ( ! is_string( $data ) ) { throw new RuntimeException( 'Could not read ' . $path ); }
    return $data;
};
$assert = static function ( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$main = $read( 'mediaflow.php' );
$runtime = $read( 'includes/class-runtime-config.php' );
$hardening = $read( 'includes/class-release-hardening.php' );
$smart = $read( 'includes/class-smart-delivery.php' );
$edge = $read( 'qmediaflow-edge.php' );
$intelligence = $read( 'includes/class-image-intelligence.php' );
$predictive = $read( 'includes/class-predictive-cache.php' );
$validator = $read( 'includes/class-production-validator.php' );
$load_test = $read( 'tools/qmediaflow-load-test.py' );

$assert( str_contains( $main, "qmediaflow-edge.php" ), 'Cold routing must use the edge wrapper.' );
$assert( str_contains( $main, 'MediaFlow\\Release_Hardening::boot();' ), 'Release hardening must boot.' );
$assert( str_contains( $main, 'MediaFlow\\Image_Intelligence::boot();' ), 'Image intelligence must boot.' );
$assert( str_contains( $main, 'MediaFlow\\Predictive_Cache::boot();' ), 'Predictive cache must boot.' );
$assert( str_contains( $main, 'MediaFlow\\Production_Validator::boot();' ), 'Production validator must boot.' );

$assert( str_contains( $runtime, "'object_store'      => self::object_store_enabled()" ), 'Gateway config must publish only the object-store enablement flag, not credentials.' );
$assert( str_contains( $runtime, 'QMEDIAFLOW_IMAGE_INTELLIGENCE' ) && str_contains( $runtime, 'QMEDIAFLOW_PREDICTIVE_WARMING' ), 'Intelligence feature switches must be runtime constants.' );
$assert( str_contains( $smart, 'return ! empty( $crop );' ), 'Array crop definitions must be treated as hard crops.' );

$assert( ! str_contains( $edge, 'wp-load.php' ) && ! str_contains( $edge, 'ABSPATH' ), 'Edge wrapper must remain WordPress-free.' );
$assert( str_contains( $edge, "require __DIR__ . '/qmediaflow-gateway.php';" ), 'Edge wrapper must delegate encoding to the proven standalone gateway.' );
$assert( str_contains( $edge, 'distribution-queue' ) && str_contains( $edge, 'distribution-pending' ), 'Gateway output must enter the sharded object distribution queue.' );
$assert( str_contains( $edge, "@fopen( $job_path, 'x' )" ), 'Gateway distribution jobs must de-duplicate atomically.' );

$assert( str_contains( $hardening, "rest_pre_dispatch" ) && str_contains( $hardening, 'is_post_publicly_viewable' ), 'REST image access must be visibility-gated.' );
$assert( str_contains( $hardening, 'qmediaflow_rest_public_unattached' ) && str_contains( $hardening, ', false, $post' ), 'Unattached public REST exposure must be explicit opt-in.' );
$assert( str_contains( $hardening, 'sync_focal_batch' ) && str_contains( $hardening, '/focal/' ), 'Existing focal metadata must have a bounded runtime backfill path.' );
$assert( str_contains( $hardening, 'LOCK_EX' ) || str_contains( $hardening, '@rename' ), 'Focal runtime publication must use controlled filesystem writes.' );

$assert( str_contains( $intelligence, 'private const SAMPLE_MAX = 96' ), 'Image intelligence must remain bounded to a tiny sample.' );
$assert( str_contains( $intelligence, "'classification'" ) && str_contains( $intelligence, "'suggested_focal'" ) && str_contains( $intelligence, "'quality_delta'" ), 'Image intelligence must persist classification, focal and encoding hints.' );
$assert( str_contains( $intelligence, 'qmediaflow_content_quality' ), 'Image intelligence must integrate with content-aware quality policy.' );
$assert( ! str_contains( $intelligence, 'REMOTE_ADDR' ) && ! str_contains( $intelligence, 'HTTP_USER_AGENT' ), 'Image intelligence must not collect visitor identity.' );

$assert( str_contains( $predictive, 'LOCK_EX | LOCK_NB' ), 'Predictive heat updates must never wait on filesystem lock contention.' );
$assert( str_contains( $predictive, 'pow( 0.5' ) && str_contains( $predictive, 'predictive_threshold' ), 'Predictive heat must decay and use a bounded threshold.' );
$assert( ! str_contains( $predictive, 'REMOTE_ADDR' ) && ! str_contains( $predictive, 'HTTP_USER_AGENT' ) && ! str_contains( $predictive, 'HTTP_REFERER' ), 'Predictive warming must not store visitor identifiers.' );
$assert( str_contains( $predictive, 'Features::instance()->warmer()->warm_post' ), 'Predictive decisions must reuse the bounded warmer and derivative de-duplication.' );

$assert( str_contains( $validator, "qmediaflow validate production" ) && str_contains( $validator, 'production_ready' ), 'Production release gate CLI must be available.' );
$assert( str_contains( $validator, "qmediaflow focal sync" ) && str_contains( $validator, "qmediaflow predictive status" ), 'Release migration/operations CLI must be available.' );
$assert( str_contains( $load_test, 'ThreadPoolExecutor' ) && str_contains( $load_test, 'p95' ) && str_contains( $load_test, 'min-success-rate' ), 'Production HTTP load tool must measure concurrency and enforce thresholds.' );

echo "PASS: v0.3.0 release hardening, production validation, image intelligence and predictive cache contracts\n";
