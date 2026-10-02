<?php
// Standalone regression: php tests/context-reentry.php
namespace {
    $GLOBALS['site'] = 1;
    function get_current_blog_id(): int { return $GLOBALS['site']; }
}
namespace MediaFlow {
    final class Paths { public int $id; public function __construct() { $this->id = \get_current_blog_id(); } }
    final class Settings {
        public static int $builds = 0;
        public static bool $fail = false;
        public function __construct( $paths ) {
            ++self::$builds;
            if ( self::$builds > 10 ) { throw new \RuntimeException( 'Unbounded recursive construction' ); }
            if ( isset( $GLOBALS['plugin'] ) ) {
                $before = $GLOBALS['site'];
                $GLOBALS['site'] = 999;
                try { $GLOBALS['plugin']->reload_context(); }
                finally { $GLOBALS['site'] = $before; }
                $GLOBALS['plugin']->reload_context(); // restore hook too
            }
            if ( self::$fail ) { throw new \RuntimeException( 'simulated storage failure' ); }
        }
    }
    final class Upload_Optimizer { public function __construct(...$args) {} public function hooks(): void {} }
    final class Manifest_Store { public function __construct(...$args) {} }
    final class Resolver { public function __construct(...$args) {} }
    final class Processor { public function __construct(...$args) {} }
    final class Request_Handler { public function __construct(...$args) {} }
    final class Responsive { public function __construct(...$args) {} }
    final class Admin { public function __construct(...$args) {} }
    require dirname(__DIR__) . '/includes/class-plugin.php';
    $GLOBALS['plugin'] = Plugin::instance();
    $plugin = $GLOBALS['plugin'];
    $GLOBALS['site'] = 2;
    $plugin->reload_context();
    if ( Settings::$builds !== 2 || $plugin->paths()->id !== 2 ) { throw new \RuntimeException('Nested switch/restore was not bounded'); }
    echo "PASS: nested switch and restore do not recursively rebuild\n";
    Settings::$fail = true;
    $GLOBALS['site'] = 3;
    try { $plugin->reload_context(); } catch ( \RuntimeException $e ) {}
    if ( $plugin->paths()->id !== 2 ) { throw new \RuntimeException('Partial context published on failure'); }
    Settings::$fail = false;
    $plugin->reload_context();
    if ( $plugin->paths()->id !== 3 ) { throw new \RuntimeException('Guard was not released after exception'); }
    echo "PASS: failure preserves old graph; retry releases guard and publishes complete context\n";
}
