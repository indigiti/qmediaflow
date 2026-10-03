<?php
namespace MediaFlow;

/**
 * Bounded upload-time visual analysis.
 *
 * The analyzer downsamples to at most 96x96, stores only derived statistics in
 * private filesystem state, and never runs on warm delivery requests. It provides
 * deterministic photo/graphic/text/transparent classification, complexity hints,
 * and a saliency-based focal suggestion without requiring an external ML service.
 */
final class Image_Intelligence {
    private const SAMPLE_MAX = 96;
    private const VERSION = 1;
    private static bool $booted = false;
    private static array $runtime = array();

    public static function boot(): void {
        if ( self::$booted || ! Runtime_Config::image_intelligence_enabled() ) { return; }
        self::$booted = true;
        add_filter( 'wp_generate_attachment_metadata', array( self::class, 'analyze_metadata' ), 1005, 3 );
        add_action( 'delete_attachment', array( self::class, 'delete' ), 30, 1 );
        add_filter( 'qmediaflow_content_quality', array( self::class, 'adjust_quality' ), 20, 5 );
    }

    public static function analyze_metadata( array $metadata, int $attachment_id, string $context = 'create' ): array {
        self::analyze( $attachment_id );
        return $metadata;
    }

    /** @return array<string,mixed>|null */
    public static function analyze( int $attachment_id ): ?array {
        if ( $attachment_id < 1 || ! Runtime_Config::image_intelligence_enabled() ) { return null; }
        $path = get_attached_file( $attachment_id, true );
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( ! is_string( $path ) || ! is_readable( $path ) || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) { return null; }

        $manifest = Plugin::instance()->manifests()->get( $attachment_id );
        $revision = is_array( $manifest ) ? (string) ( $manifest['revision'] ?? '' ) : '';
        $existing = self::runtime( $attachment_id );
        if ( $existing && (int) ( $existing['version'] ?? 0 ) === self::VERSION && hash_equals( $revision, (string) ( $existing['revision'] ?? '' ) ) ) {
            return $existing;
        }

        $sample = self::sample_pixels( $path, $mime );
        if ( ! $sample ) { return null; }
        $analysis = self::metrics( $sample['pixels'], (int) $sample['width'], (int) $sample['height'] );
        if ( ! $analysis ) { return null; }

        $classification = self::classify( $analysis );
        $delta = match ( $classification ) {
            'text-heavy' => 4,
            'graphic' => 2,
            'transparent' => 1,
            default => ( (int) $analysis['complexity'] >= 70 ? -3 : ( (int) $analysis['complexity'] >= 50 ? -1 : 0 ) ),
        };
        $preferred = 'photo' === $classification && (int) $analysis['complexity'] >= 45 ? 'avif' : 'webp';

        $data = array(
            'version'             => self::VERSION,
            'attachment_id'       => $attachment_id,
            'revision'            => $revision,
            'classification'      => $classification,
            'complexity'          => (int) $analysis['complexity'],
            'entropy'             => round( (float) $analysis['entropy'], 4 ),
            'edge_density'        => round( (float) $analysis['edge_density'], 4 ),
            'contrast'            => round( (float) $analysis['contrast'], 4 ),
            'colorfulness'        => round( (float) $analysis['colorfulness'], 4 ),
            'transparency_ratio'  => round( (float) $analysis['transparency_ratio'], 4 ),
            'mean_luminance'      => round( (float) $analysis['mean_luminance'], 4 ),
            'suggested_focal'     => array( 'x' => (int) $analysis['focal_x'], 'y' => (int) $analysis['focal_y'] ),
            'preferred_format'    => $preferred,
            'quality_delta'       => $delta,
            'sample_width'        => (int) $sample['width'],
            'sample_height'       => (int) $sample['height'],
            'analyzed_at'         => time(),
            'algorithm'           => 'qmf-visual-v1',
        );
        $data['signature'] = substr( hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) ?: '' ), 0, 16 );

        if ( ! self::write( $attachment_id, $data ) ) { return null; }
        self::$runtime[ self::cache_key( $attachment_id ) ] = $data;
        Telemetry::event( 'image_intelligence_analyzed' );
        Telemetry::event( 'image_intelligence_' . str_replace( '-', '_', $classification ) );

        if ( Runtime_Config::auto_focal_enabled() ) { self::maybe_apply_focal( $attachment_id, $data ); }
        do_action( 'qmediaflow_image_analyzed', $attachment_id, $data );
        return $data;
    }

    /** Delivery-safe filesystem read; never queries attachment metadata. */
    public static function runtime( int $attachment_id ): ?array {
        $key = self::cache_key( $attachment_id );
        if ( array_key_exists( $key, self::$runtime ) ) { return self::$runtime[ $key ]; }
        $path = self::path( $attachment_id );
        if ( ! is_readable( $path ) ) { self::$runtime[ $key ] = null; return null; }
        $raw = @file_get_contents( $path );
        $data = is_string( $raw ) ? json_decode( $raw, true ) : null;
        self::$runtime[ $key ] = is_array( $data ) ? $data : null;
        return self::$runtime[ $key ];
    }

    public static function adjust_quality( int $quality, array $manifest, int $output_width, string $format, int $base_quality ): int {
        $id = absint( $manifest['id'] ?? 0 );
        if ( $id < 1 ) { return $quality; }
        $analysis = self::runtime( $id );
        if ( ! $analysis ) { return $quality; }
        $delta = max( -8, min( 8, (int) ( $analysis['quality_delta'] ?? 0 ) ) );
        if ( $output_width <= 480 && $delta < 0 ) { ++$delta; }
        if ( 'avif' === $format && 'text-heavy' === (string) ( $analysis['classification'] ?? '' ) ) { $delta += 2; }
        return max( 45, min( 95, $quality + $delta ) );
    }

    public static function delete( int $attachment_id ): void {
        unset( self::$runtime[ self::cache_key( $attachment_id ) ] );
        $path = self::path( $attachment_id );
        if ( is_file( $path ) ) { @unlink( $path ); }
        self::clean_empty_parents( $path, Plugin::instance()->paths()->private_dir() . '/intelligence' );
    }

    /** @return array{pixels:array<int,array{0:int,1:int,2:int,3:int}>,width:int,height:int}|null */
    private static function sample_pixels( string $path, string $mime ): ?array {
        if ( class_exists( '\\Imagick' ) ) {
            try {
                $image = new \Imagick( $path );
                if ( method_exists( $image, 'setIteratorIndex' ) ) { $image->setIteratorIndex( 0 ); }
                if ( method_exists( $image, 'autoOrient' ) ) { $image->autoOrient(); }
                $image->thumbnailImage( self::SAMPLE_MAX, self::SAMPLE_MAX, true, true );
                $width = max( 1, (int) $image->getImageWidth() );
                $height = max( 1, (int) $image->getImageHeight() );
                $raw = $image->exportImagePixels( 0, 0, $width, $height, 'RGBA', \Imagick::PIXEL_CHAR );
                $image->clear();
                $image->destroy();
                if ( ! is_array( $raw ) || count( $raw ) < $width * $height * 4 ) { return null; }
                $pixels = array();
                for ( $i = 0, $n = count( $raw ); $i + 3 < $n; $i += 4 ) {
                    $pixels[] = array( (int) $raw[$i], (int) $raw[$i + 1], (int) $raw[$i + 2], (int) $raw[$i + 3] );
                }
                return array( 'pixels' => $pixels, 'width' => $width, 'height' => $height );
            } catch ( \Throwable $e ) {
                // Fall through to bounded GD sampling.
            }
        }

        $loader = match ( $mime ) {
            'image/jpeg' => function_exists( 'imagecreatefromjpeg' ) ? 'imagecreatefromjpeg' : '',
            'image/png' => function_exists( 'imagecreatefrompng' ) ? 'imagecreatefrompng' : '',
            'image/webp' => function_exists( 'imagecreatefromwebp' ) ? 'imagecreatefromwebp' : '',
            'image/avif' => function_exists( 'imagecreatefromavif' ) ? 'imagecreatefromavif' : '',
            default => '',
        };
        if ( '' === $loader ) { return null; }
        $source = @$loader( $path );
        if ( ! $source ) { return null; }
        $source_w = imagesx( $source );
        $source_h = imagesy( $source );
        if ( $source_w < 1 || $source_h < 1 ) { imagedestroy( $source ); return null; }
        $scale = min( self::SAMPLE_MAX / $source_w, self::SAMPLE_MAX / $source_h, 1 );
        $width = max( 1, (int) round( $source_w * $scale ) );
        $height = max( 1, (int) round( $source_h * $scale ) );
        $sample = imagecreatetruecolor( $width, $height );
        imagealphablending( $sample, false );
        imagesavealpha( $sample, true );
        imagecopyresampled( $sample, $source, 0, 0, 0, 0, $width, $height, $source_w, $source_h );
        imagedestroy( $source );

        $pixels = array();
        for ( $y = 0; $y < $height; ++$y ) {
            for ( $x = 0; $x < $width; ++$x ) {
                $color = imagecolorat( $sample, $x, $y );
                $a7 = ( $color >> 24 ) & 0x7F;
                $pixels[] = array( ( $color >> 16 ) & 0xFF, ( $color >> 8 ) & 0xFF, $color & 0xFF, 255 - (int) round( $a7 * 255 / 127 ) );
            }
        }
        imagedestroy( $sample );
        return array( 'pixels' => $pixels, 'width' => $width, 'height' => $height );
    }

    /** @param array<int,array{0:int,1:int,2:int,3:int}> $pixels @return array<string,float|int>|null */
    private static function metrics( array $pixels, int $width, int $height ): ?array {
        $count = min( count( $pixels ), $width * $height );
        if ( $count < 4 ) { return null; }
        $hist = array_fill( 0, 32, 0 );
        $lums = array();
        $sum = 0.0;
        $sum_sq = 0.0;
        $colorfulness = 0.0;
        $transparent = 0;
        for ( $i = 0; $i < $count; ++$i ) {
            [ $r, $g, $b, $a ] = $pixels[$i];
            $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
            $lums[$i] = $lum;
            $sum += $lum;
            $sum_sq += $lum * $lum;
            $colorfulness += max( $r, $g, $b ) - min( $r, $g, $b );
            if ( $a < 250 ) { ++$transparent; }
            ++$hist[ min( 31, max( 0, (int) floor( $lum / 8 ) ) ) ];
        }
        $mean = $sum / $count;
        $variance = max( 0.0, $sum_sq / $count - $mean * $mean );
        $contrast = min( 1.0, sqrt( $variance ) / 127.5 );
        $entropy = 0.0;
        foreach ( $hist as $bin ) {
            if ( $bin < 1 ) { continue; }
            $p = $bin / $count;
            $entropy -= $p * log( $p, 2 );
        }
        $entropy = min( 1.0, $entropy / 5.0 );

        $edge_sum = 0.0;
        $edge_count = 0;
        $cells = array_fill( 0, 9, 0.0 );
        $cell_counts = array_fill( 0, 9, 0 );
        for ( $y = 0; $y < $height; ++$y ) {
            for ( $x = 0; $x < $width; ++$x ) {
                $i = $y * $width + $x;
                $local_edge = 0.0;
                $parts = 0;
                if ( $x > 0 ) { $local_edge += abs( $lums[$i] - $lums[$i - 1] ); ++$parts; }
                if ( $y > 0 ) { $local_edge += abs( $lums[$i] - $lums[$i - $width] ); ++$parts; }
                if ( $parts > 0 ) {
                    $local_edge /= $parts;
                    $edge_sum += $local_edge;
                    ++$edge_count;
                }
                $cx = min( 2, (int) floor( 3 * $x / max( 1, $width ) ) );
                $cy = min( 2, (int) floor( 3 * $y / max( 1, $height ) ) );
                $cell = $cy * 3 + $cx;
                $center_bias = 4 === $cell ? 8.0 : ( in_array( $cell, array( 1, 3, 5, 7 ), true ) ? 3.0 : 0.0 );
                $cells[$cell] += 0.72 * $local_edge + 0.28 * abs( $lums[$i] - $mean ) + $center_bias;
                ++$cell_counts[$cell];
            }
        }
        foreach ( $cells as $i => $score ) { $cells[$i] = $score / max( 1, $cell_counts[$i] ); }
        $best = array_keys( $cells, max( $cells ), true )[0] ?? 4;
        $edge_density = $edge_count ? min( 1.0, $edge_sum / ( $edge_count * 255 ) ) : 0.0;
        $colorfulness = min( 1.0, $colorfulness / ( $count * 255 ) );
        $transparency = $transparent / $count;
        $complexity = (int) round( min( 100, 100 * ( 0.50 * $entropy + 0.32 * $edge_density + 0.18 * $colorfulness ) ) );
        $saliency = max( $cells );
        $focal_x = $saliency < 12 ? 50 : (int) round( ( ( $best % 3 ) + 0.5 ) * 100 / 3 );
        $focal_y = $saliency < 12 ? 50 : (int) round( ( ( intdiv( $best, 3 ) ) + 0.5 ) * 100 / 3 );

        return array(
            'entropy' => $entropy,
            'edge_density' => $edge_density,
            'contrast' => $contrast,
            'colorfulness' => $colorfulness,
            'transparency_ratio' => $transparency,
            'mean_luminance' => $mean / 255,
            'complexity' => $complexity,
            'focal_x' => max( 0, min( 100, $focal_x ) ),
            'focal_y' => max( 0, min( 100, $focal_y ) ),
        );
    }

    /** @param array<string,float|int> $m */
    private static function classify( array $m ): string {
        if ( (float) $m['transparency_ratio'] >= 0.02 ) { return 'transparent'; }
        if ( (float) $m['edge_density'] >= 0.16 && (float) $m['contrast'] >= 0.30 && (float) $m['colorfulness'] <= 0.42 ) { return 'text-heavy'; }
        if ( (float) $m['edge_density'] >= 0.13 && (float) $m['entropy'] <= 0.72 ) { return 'graphic'; }
        return 'photo';
    }

    /** @param array<string,mixed> $data */
    private static function maybe_apply_focal( int $attachment_id, array $data ): void {
        if ( '' !== (string) get_post_meta( $attachment_id, '_qmediaflow_focal_x', true ) || '' !== (string) get_post_meta( $attachment_id, '_qmediaflow_focal_y', true ) ) { return; }
        $focal = is_array( $data['suggested_focal'] ?? null ) ? $data['suggested_focal'] : array( 'x' => 50, 'y' => 50 );
        $x = max( 0, min( 100, absint( $focal['x'] ?? 50 ) ) );
        $y = max( 0, min( 100, absint( $focal['y'] ?? 50 ) ) );
        if ( abs( $x - 50 ) < 10 && abs( $y - 50 ) < 10 ) { return; }
        update_post_meta( $attachment_id, '_qmediaflow_focal_x', $x );
        update_post_meta( $attachment_id, '_qmediaflow_focal_y', $y );
        Release_Hardening::sync_focal_runtime( $attachment_id );
        Telemetry::event( 'image_intelligence_auto_focal' );
    }

    /** @param array<string,mixed> $data */
    private static function write( int $attachment_id, array $data ): bool {
        $path = self::path( $attachment_id );
        $dir = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { return false; }
        $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) { return false; }
        $temp = $path . '.tmp-' . getmypid() . '-' . substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        if ( false === @file_put_contents( $temp, $json ) ) { return false; }
        @chmod( $temp, 0600 );
        if ( ! @rename( $temp, $path ) ) { @unlink( $temp ); return false; }
        @chmod( $path, 0600 );
        return true;
    }

    private static function path( int $attachment_id ): string {
        $paths = Plugin::instance()->paths();
        return $paths->private_dir() . '/intelligence/' . $paths->attachment_shard( $attachment_id ) . '/' . $attachment_id . '.json';
    }

    private static function cache_key( int $attachment_id ): string {
        return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $attachment_id;
    }

    private static function clean_empty_parents( string $path, string $root ): void {
        $normalized_root = rtrim( wp_normalize_path( $root ), '/' );
        foreach ( array( dirname( $path ), dirname( dirname( $path ) ) ) as $dir ) {
            $normalized = wp_normalize_path( $dir );
            if ( $normalized === $normalized_root || ! str_starts_with( $normalized, $normalized_root . '/' ) ) { continue; }
            $items = @scandir( $dir );
            if ( is_array( $items ) && count( $items ) <= 2 ) { @rmdir( $dir ); }
        }
    }
}
