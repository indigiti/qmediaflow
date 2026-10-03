<?php
namespace MediaFlow;

/**
 * Popularity-aware warming without user profiling.
 *
 * A sampled fraction of public singular requests increments only a decaying post
 * score in the private filesystem. No IP, cookie, user agent, referrer, or user ID
 * is stored. When the estimated heat crosses a threshold, the existing bounded
 * Warmer chooses the actual image variants and the derivative queue de-duplicates.
 */
final class Predictive_Cache {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted || ! Runtime_Config::predictive_warming_enabled() ) { return; }
        self::$booted = true;
        add_action( 'template_redirect', array( self::class, 'observe' ), 30 );
        add_action( 'delete_post', array( self::class, 'delete' ), 30, 1 );
    }

    public static function observe(): void {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_feed' ) && is_feed() ) ) { return; }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return; }
        if ( ! function_exists( 'is_singular' ) || ! is_singular() ) { return; }
        $post_id = absint( get_queried_object_id() );
        if ( $post_id < 1 || 'publish' !== get_post_status( $post_id ) ) { return; }
        if ( ! (bool) apply_filters( 'qmediaflow_predictive_observe', true, $post_id ) ) { return; }

        $rate = Runtime_Config::predictive_sample_rate();
        try { $sampled = 1 === random_int( 1, $rate ); }
        catch ( \Throwable $e ) { $sampled = 1 === mt_rand( 1, $rate ); }
        if ( ! $sampled ) { return; }

        $state = self::record_sample( $post_id, $rate );
        if ( ! $state || empty( $state['warm_now'] ) ) { return; }
        $jobs = Features::instance()->warmer()->warm_post( $post_id );
        Telemetry::event( 'predictive_warm_triggered' );
        Telemetry::event( 'predictive_warm_jobs', $jobs );
        do_action( 'qmediaflow_predictive_warmed', $post_id, $jobs, $state );
    }

    /** @return array<string,mixed>|null */
    private static function record_sample( int $post_id, int $weight ): ?array {
        $path = self::path( $post_id );
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return null; }
        $handle = @fopen( $path, 'c+' );
        if ( ! is_resource( $handle ) ) { return null; }
        if ( ! @flock( $handle, LOCK_EX | LOCK_NB ) ) { @fclose( $handle ); return null; }

        try {
            rewind( $handle );
            $raw = stream_get_contents( $handle );
            $state = is_string( $raw ) && '' !== trim( $raw ) ? json_decode( $raw, true ) : array();
            if ( ! is_array( $state ) ) { $state = array(); }
            $now = time();
            $updated = absint( $state['updated_at'] ?? $now );
            $elapsed = max( 0, $now - $updated );
            $half_life = Runtime_Config::integer( 'QMEDIAFLOW_PREDICTIVE_HALF_LIFE', 21600, 900, 604800 );
            $score = max( 0.0, (float) ( $state['score'] ?? 0.0 ) );
            if ( $elapsed > 0 && $score > 0 ) { $score *= pow( 0.5, $elapsed / $half_life ); }
            $score = min( 1000000.0, $score + max( 1, $weight ) );
            $last_warm = absint( $state['last_warm'] ?? 0 );
            $warm_now = $score >= Runtime_Config::predictive_threshold() && $now - $last_warm >= Runtime_Config::predictive_cooldown();
            if ( $warm_now ) { $last_warm = $now; }
            $state = array(
                'version'    => 1,
                'post_id'    => $post_id,
                'score'      => round( $score, 3 ),
                'samples'    => absint( $state['samples'] ?? 0 ) + 1,
                'updated_at' => $now,
                'last_warm'  => $last_warm,
                'warm_now'   => $warm_now,
            );
            $json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
            if ( ! is_string( $json ) ) { return null; }
            rewind( $handle );
            if ( ! @ftruncate( $handle, 0 ) || false === @fwrite( $handle, $json ) ) { return null; }
            @fflush( $handle );
            @chmod( $path, 0600 );
            return $state;
        } finally {
            @flock( $handle, LOCK_UN );
            @fclose( $handle );
        }
    }

    /** @return array{records:int,hot:int,threshold:int,top:array<int,array<string,mixed>>,truncated:bool} */
    public static function status( int $limit = 1000 ): array {
        $limit = max( 1, min( 10000, $limit ) );
        $root = Plugin::instance()->paths()->private_dir() . '/predictive';
        $records = 0;
        $hot = 0;
        $top = array();
        if ( is_dir( $root ) ) {
            foreach ( self::paths( $root, $limit ) as $path ) {
                ++$records;
                $raw = @file_get_contents( $path );
                $state = is_string( $raw ) ? json_decode( $raw, true ) : null;
                if ( ! is_array( $state ) ) { continue; }
                $score = (float) ( $state['score'] ?? 0 );
                if ( $score >= Runtime_Config::predictive_threshold() ) { ++$hot; }
                $top[] = array(
                    'post_id' => absint( $state['post_id'] ?? 0 ),
                    'score' => round( $score, 2 ),
                    'samples' => absint( $state['samples'] ?? 0 ),
                    'last_warm' => absint( $state['last_warm'] ?? 0 ),
                );
            }
        }
        usort( $top, static fn( array $a, array $b ): int => (float) $b['score'] <=> (float) $a['score'] );
        $top = array_slice( $top, 0, 20 );
        return array( 'records' => $records, 'hot' => $hot, 'threshold' => Runtime_Config::predictive_threshold(), 'top' => $top, 'truncated' => $records >= $limit );
    }

    public static function force_warm( int $post_id ): int {
        if ( $post_id < 1 || 'publish' !== get_post_status( $post_id ) ) { return 0; }
        return Features::instance()->warmer()->warm_post( $post_id );
    }

    public static function delete( int $post_id ): void {
        $path = self::path( $post_id );
        if ( is_file( $path ) ) { @unlink( $path ); }
        $root = Plugin::instance()->paths()->private_dir() . '/predictive';
        foreach ( array( dirname( $path ), dirname( dirname( $path ) ) ) as $dir ) {
            if ( ! str_starts_with( wp_normalize_path( $dir ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) { continue; }
            $items = @scandir( $dir );
            if ( is_array( $items ) && count( $items ) <= 2 ) { @rmdir( $dir ); }
        }
    }

    private static function path( int $post_id ): string {
        $paths = Plugin::instance()->paths();
        return $paths->private_dir() . '/predictive/' . $paths->attachment_shard( $post_id ) . '/' . $post_id . '.json';
    }

    /** @return \Generator<int,string> */
    private static function paths( string $root, int $limit ): \Generator {
        $seen = 0;
        foreach ( (array) @scandir( $root ) as $one ) {
            if ( ! preg_match( '/^[0-9]{2}$/', (string) $one ) ) { continue; }
            $one_path = $root . '/' . $one;
            foreach ( (array) @scandir( $one_path ) as $two ) {
                if ( ! preg_match( '/^[0-9]{2}$/', (string) $two ) ) { continue; }
                $two_path = $one_path . '/' . $two;
                foreach ( (array) @scandir( $two_path ) as $file ) {
                    if ( ! preg_match( '/^[0-9]+\.json$/', (string) $file ) ) { continue; }
                    yield $two_path . '/' . $file;
                    if ( ++$seen >= $limit ) { return; }
                }
            }
        }
    }
}
