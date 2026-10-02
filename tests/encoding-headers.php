<?php
// Standalone: php tests/encoding-headers.php (no WordPress needed for header inspection).
require_once dirname( __DIR__ ) . '/includes/class-encoding.php';
$fixtures = array(
    'progressive.jpg' => array( 'image/jpeg', true ),
    'baseline.jpg' => array( 'image/jpeg', false ),
    'adam7.png' => array( 'image/png', true ),
    'plain.png' => array( 'image/png', false ),
);
foreach ( $fixtures as $name => [ $mime, $expected ] ) {
    if ( \MediaFlow\Encoding::interlaced( __DIR__ . '/fixtures/' . $name, $mime ) !== $expected ) { throw new RuntimeException( 'Failed: ' . $name ); }
    echo 'PASS: ' . $name . PHP_EOL;
}
if ( null !== \MediaFlow\Encoding::interlaced( __FILE__, 'image/jpeg' ) ) { throw new RuntimeException( 'Invalid input accepted' ); }
echo 'PASS: invalid JPEG rejected' . PHP_EOL;
