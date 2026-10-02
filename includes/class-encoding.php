<?php
namespace MediaFlow;

/** Applies only during a MediaFlow derivative save; never adds a global encoder policy. */
final class Encoding {
    public static function compatible( $editor, string $mime ): bool {
        global $wp_version;
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || version_compare( (string) $wp_version, '6.5', '<' ) ) { return false; }
        if ( $editor instanceof \WP_Image_Editor_GD ) {
            return function_exists( 'imageinterlace' ) && function_exists( 'image/jpeg' === $mime ? 'imagejpeg' : 'imagepng' );
        }
        if ( $editor instanceof \WP_Image_Editor_Imagick ) {
            return class_exists( '\Imagick' ) && method_exists( '\Imagick', 'setInterlaceScheme' ) && method_exists( '\Imagick', 'getInterlaceScheme' ) && defined( 'Imagick::INTERLACE_PLANE' ) && defined( 'Imagick::INTERLACE_NO' );
        }
        return false;
    }

    public static function save( $editor, string $path, string $mime, bool $enabled ) {
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) { return $editor->save( $path, $mime ); }
        if ( ! self::compatible( $editor, $mime ) ) {
            if ( $enabled ) { do_action( 'mediaflow_encoding_unavailable', $mime, get_class( $editor ), 'unsupported_editor' ); }
            return $editor->save( $path, $mime );
        }
        $policy = static fn( $current, $saving_mime ) => $saving_mime === $mime ? $enabled : $current;
        add_filter( 'image_save_progressive', $policy, PHP_INT_MAX, 2 );
        try { $result = $editor->save( $path, $mime ); }
        catch ( \Throwable $e ) { $result = new \WP_Error( 'mediaflow_encoding_save', $e->getMessage() ); }
        finally { remove_filter( 'image_save_progressive', $policy, PHP_INT_MAX ); }
        if ( ! is_wp_error( $result ) ) {
            $actual = self::interlaced( (string) ( $result['path'] ?? $path ), $mime );
            if ( $actual !== $enabled ) { do_action( 'mediaflow_encoding_unavailable', $mime, get_class( $editor ), 'output_not_verified' ); }
        }
        return $result;
    }

    /** Inspect headers without decoding or reading a full image into memory. */
    public static function interlaced( string $path, string $mime ): ?bool {
        $file = @fopen( $path, 'rb' );
        if ( ! is_resource( $file ) ) { return null; }
        try {
            if ( 'image/png' === $mime ) {
                $header = fread( $file, 29 );
                if ( strlen( $header ) !== 29 || substr( $header, 0, 8 ) !== "\x89PNG\r\n\x1a\n" || substr( $header, 12, 4 ) !== 'IHDR' ) { return null; }
                return match ( ord( $header[28] ) ) { 0 => false, 1 => true, default => null };
            }
            if ( 'image/jpeg' !== $mime || fread( $file, 2 ) !== "\xff\xd8" ) { return null; }
            // Bound metadata traversal; never scan entropy-coded image data.
            for ( $i = 0; $i < 4096; ++$i ) {
                if ( fread( $file, 1 ) !== "\xff" ) { return null; }
                do { $byte = fread( $file, 1 ); } while ( "\xff" === $byte );
                if ( '' === $byte ) { return null; }
                $marker = ord( $byte );
                if ( in_array( $marker, array( 0xc2, 0xc6, 0xca, 0xce ), true ) ) { return true; }
                if ( in_array( $marker, array( 0xc0, 0xc1, 0xc3, 0xc5, 0xc7, 0xc9, 0xcb, 0xcd, 0xcf ), true ) ) { return false; }
                if ( 0xda === $marker || 0xd9 === $marker ) { return null; }
                if ( 0x01 === $marker || ( $marker >= 0xd0 && $marker <= 0xd7 ) ) { continue; }
                $length = fread( $file, 2 );
                if ( strlen( $length ) !== 2 ) { return null; }
                $size = unpack( 'n', $length )[1];
                if ( $size < 2 || 0 !== fseek( $file, $size - 2, SEEK_CUR ) ) { return null; }
            }
            return null;
        } finally { fclose( $file ); }
    }

    /** User-triggered tiny encode test of the actual WordPress-selected editor. */
    public static function check( Paths $paths ): string {
        $paths->ensure();
        $slot = Budget::acquire( $paths );
        if ( is_wp_error( $slot ) ) { return 'Encoder check deferred: processing capacity is busy.'; }
        $files = array();
        try {
            $dir = $paths->temp_dir();
            if ( ! wp_mkdir_p( $dir ) ) { return 'Encoder check failed: temporary directory is unavailable.'; }
            $base = $dir . '/encoding-' . bin2hex( random_bytes( 8 ) );
            $source = $base . '-source.png'; $files[] = $source;
            if ( ! copy( MEDIAFLOW_DIR . 'tests/fixtures/encoding-source.png', $source ) ) { return 'Encoder check failed: cannot create test source.'; }
            $messages = array();
            foreach ( array( 'image/jpeg' => 'JPEG', 'image/png' => 'PNG' ) as $mime => $label ) {
                foreach ( array( false, true ) as $enabled ) {
                    $editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );
                    $name = $label . ( $enabled ? ' ON' : ' OFF' );
                    if ( is_wp_error( $editor ) || ! self::compatible( $editor, $mime ) ) { $messages[] = $name . ': unsupported editor'; continue; }
                    $target = $base . '-' . $label . '-' . (int) $enabled . ( 'JPEG' === $label ? '.jpg' : '.png' ); $files[] = $target;
                    $result = self::save( $editor, $target, $mime, $enabled );
                    if ( is_wp_error( $result ) ) { $messages[] = $name . ': save failed'; continue; }
                    $saved = (string) ( $result['path'] ?? $target ); $files[] = $saved;
                    $messages[] = $name . ': ' . ( self::interlaced( $saved, $mime ) === $enabled ? 'VERIFIED' : 'NOT VERIFIED' ) . ' (' . get_class( $editor ) . ')';
                    unset( $editor );
                }
            }
            return implode( '; ', $messages );
        } catch ( \Throwable $e ) { return 'Encoder check failed: ' . $e->getMessage(); }
        finally { foreach ( array_unique( $files ) as $file ) { @unlink( $file ); } Budget::release( $slot ); }
    }
}
