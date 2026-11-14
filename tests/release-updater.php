<?php
require dirname( __DIR__ ) . '/includes/class-release-updater.php';
use MediaFlow\Release_Updater;
function fixture( string $tag, bool $preview = false, bool $draft = false ): array {
    return array( 'tag_name' => $tag, 'draft' => $draft, 'prerelease' => $preview, 'assets' => array( array(
        'name' => 'qmediaflow.zip', 'state' => 'uploaded', 'size' => 100,
        'browser_download_url' => "https://github.com/indigiti/qmediaflow/releases/download/$tag/qmediaflow.zip",
    ) ) );
}
function check( $actual, $expected ): void {
    if ( $actual !== $expected ) { throw new RuntimeException( var_export( array( $actual, $expected ), true ) ); }
}
$r = array( fixture( 'v2.0.0-beta.2', true ), fixture( 'v1.5.0' ), fixture( 'v3.0.0', false, true ), fixture( 'v4.0.0-beta.1' ) );
check( Release_Updater::select( $r, '1.0.0', false )['version'], '1.5.0' );
check( Release_Updater::select( $r, '1.0.0', true )['version'], '2.0.0-beta.2' );
check( Release_Updater::select( $r, '2.0.0', true ), false );
check( Release_Updater::select( array( fixture( 'v2.0.0' ) ), '2.0.0-beta.2', true )['version'], '2.0.0' );
$r = fixture( 'v2.0.0' );
$r['assets'][0]['browser_download_url'] = 'https://example.org/qmediaflow.zip';
check( Release_Updater::select( array( $r ), '1.0.0', false ), false );
$r['assets'] = array();
check( Release_Updater::select( array( $r ), '1.0.0', false ), false );
echo "PASS: release channels, version ordering and package validation\n";

$tmp = tempnam( sys_get_temp_dir(), 'qmf-test-' );
try {
    file_put_contents( $tmp, 'release fixture' );
    $hash = hash_file( 'sha256', $tmp );
    check( Release_Updater::checksum_matches( $tmp, $hash . "  qmediaflow.zip\n" ), true );
    check( Release_Updater::checksum_matches( $tmp, $hash . "  wrong.zip\n" ), false );
    check( Release_Updater::checksum_matches( $tmp, str_repeat( '0', 64 ) . "  qmediaflow.zip\n" ), false );
} finally { unlink( $tmp ); }
