<?php
namespace MediaFlow;

final class Settings {
    private const OPTION = 'mediaflow_settings';

    private Paths $paths;
    private array $values;
    private ?string $signing_key_runtime = null;

    public function __construct( Paths $paths ) {
        $this->paths = $paths;

        $runtime = $this->read_runtime_file();
        if ( ! is_array( $runtime ) ) {
            $runtime = $this->read_legacy_runtime_file();
        }

        if ( is_array( $runtime ) ) {
            $this->values = $this->normalize( $runtime );
            // Upgrade/migrate runtime config without touching the DB hot path.
            if ( $runtime !== $this->values || ! is_readable( $this->runtime_path() ) ) {
                $this->write_runtime_file();
            }
            return;
        }

        // Database recovery is used only when the filesystem runtime config is absent.
        $stored       = get_option( self::OPTION, array() );
        $this->values = $this->normalize( is_array( $stored ) ? $stored : array() );
        $this->write_runtime_file();
    }

    public static function defaults(): array {
        return array(
            'core_size_mode'       => 'safe',
            'format'               => 'auto',
            'quality'              => 82,
            'progressive_jpeg'     => false,
            'interlaced_png'       => false,
            'placeholder_mode'     => 'auto',
            'placeholder_color'    => '#e5e7eb',
            'lqip_width'           => 64,
            'lqip_quality'         => 24,
            'lqip_max_bytes'       => 3072,
            'lqip_max_per_request' => 8,
            'lqip_max_total_bytes' => 24576,
            'widths'               => array( 320, 480, 640, 768, 1024, 1200, 1600, 1920 ),
            'max_srcset_candidates'=> 4,
            'upload_optimizer_enabled' => true,
            'upload_max_width'     => 2560,
            'upload_max_height'    => 2560,
            'upload_max_source_mb' => 25,
            'cache_namespace'      => 'v0200',
        );
    }

    public static function install_defaults( Paths $paths ): void {
        $stored = get_option( self::OPTION, false );
        if ( ! is_array( $stored ) ) {
            $stored = self::defaults();
            add_option( self::OPTION, $stored, '', false );
        }

        $settings         = new self( $paths );
        $settings->values = $settings->normalize( $stored );
        $settings->write_runtime_file();
        $settings->signing_key();
    }

    public function all(): array {
        return $this->values;
    }

    public function core_size_mode(): string {
        $mode = (string) ( $this->values['core_size_mode'] ?? 'adaptive' );
        return in_array( $mode, array( 'safe', 'adaptive', 'strict' ), true ) ? $mode : 'adaptive';
    }

    public function disable_core_sizes(): bool {
        return 'safe' !== $this->core_size_mode();
    }

    public function quality(): int {
        return max( 1, min( 100, (int) $this->values['quality'] ) );
    }

    public function encoding_enabled( string $mime ): bool {
        return match ( $mime ) {
            'image/jpeg' => ! empty( $this->values['progressive_jpeg'] ),
            'image/png' => ! empty( $this->values['interlaced_png'] ),
            default => false,
        };
    }

    public function progressive(): bool {
        return 'none' !== $this->placeholder_mode();
    }

    public function placeholder_mode(): string {
        $mode = (string) ( $this->values['placeholder_mode'] ?? 'color' );
        return in_array( $mode, array( 'none', 'color', 'gradient', 'lqip', 'auto' ), true ) ? $mode : 'auto';
    }

    public function placeholder_color(): string {
        $color = (string) ( $this->values['placeholder_color'] ?? '#e5e7eb' );
        return preg_match( '/^#[a-f0-9]{6}$/i', $color ) ? strtolower( $color ) : '#e5e7eb';
    }

    public function lqip_width(): int {
        $width = absint( $this->values['lqip_width'] ?? 64 );
        return in_array( $width, array( 16, 32, 64, 128, 256 ), true ) ? $width : 64;
    }

    public function lqip_quality(): int {
        return max( 8, min( 50, absint( $this->values['lqip_quality'] ?? 24 ) ) );
    }

    public function lqip_max_bytes(): int {
        return max( 768, min( 16384, absint( $this->values['lqip_max_bytes'] ?? 3072 ) ) );
    }

    public function lqip_max_per_request(): int {
        return max( 0, min( 100, absint( $this->values['lqip_max_per_request'] ?? 8 ) ) );
    }

    public function lqip_max_total_bytes(): int {
        return max( 4096, min( 262144, absint( $this->values['lqip_max_total_bytes'] ?? 24576 ) ) );
    }

    public function lqip_signature(): string {
        return substr( hash( 'sha256', implode( '|', array( $this->lqip_width(), $this->lqip_quality(), $this->lqip_max_bytes() ) ) ), 0, 12 );
    }

    public function widths(): array {
        $widths = array_map( 'absint', (array) $this->values['widths'] );
        $widths = array_values( array_unique( array_filter( $widths ) ) );
        sort( $widths, SORT_NUMERIC );
        return $widths;
    }

    public function max_srcset_candidates(): int {
        return max( 2, min( 6, absint( $this->values['max_srcset_candidates'] ?? 4 ) ) );
    }

    public function upload_optimizer_enabled(): bool {
        return ! empty( $this->values['upload_optimizer_enabled'] );
    }

    public function upload_max_width(): int {
        return max( 320, min( 8192, absint( $this->values['upload_max_width'] ?? 2560 ) ) );
    }

    public function upload_max_height(): int {
        return max( 320, min( 8192, absint( $this->values['upload_max_height'] ?? 2560 ) ) );
    }

    public function upload_max_source_bytes(): int {
        $megabytes = max( 1, min( 100, absint( $this->values['upload_max_source_mb'] ?? 25 ) ) );
        return $megabytes * 1024 * 1024;
    }

    public function preferred_format(): string {
        $format = (string) $this->values['format'];
        if ( ! in_array( $format, array( 'auto', 'webp', 'avif', 'original' ), true ) ) {
            return 'auto';
        }
        return $format;
    }

    public function cache_namespace(): string {
        $namespace = (string) ( $this->values['cache_namespace'] ?? 'v0200' );
        return preg_match( '/^[a-zA-Z0-9_-]{4,32}$/', $namespace ) ? $namespace : 'v0200';
    }

    public function rotate_cache_namespace(): string {
        try {
            $suffix = bin2hex( random_bytes( 4 ) );
        } catch ( \Throwable $e ) {
            $suffix = substr( hash( 'sha256', microtime( true ) . mt_rand() ), 0, 8 );
        }
        $this->values['cache_namespace'] = 'v' . $suffix;
        $this->persist();
        return $this->cache_namespace();
    }

    public function signing_key(): string {
        if ( null !== $this->signing_key_runtime ) {
            return $this->signing_key_runtime;
        }

        $path = $this->paths->secret_path();
        if ( ! is_readable( $path ) ) {
            $legacy = $this->paths->legacy_secret_path();
            if ( is_readable( $legacy ) ) {
                $legacy_key = include $legacy;
                if ( is_string( $legacy_key ) && strlen( $legacy_key ) >= 32 ) {
                    $this->write_secret( $legacy_key );
                }
            }
        }

        if ( is_readable( $path ) ) {
            $key = include $path;
            if ( is_string( $key ) && strlen( $key ) >= 32 ) {
                $this->signing_key_runtime = $key;
                return $key;
            }
        }

        try {
            $key = bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $e ) {
            $key = hash( 'sha256', wp_salt( 'auth' ) . microtime( true ) );
        }

        $this->paths->ensure();
        $guard = @fopen( $this->paths->private_dir() . '/.key-lock', 'c' );
        if ( ! is_resource( $guard ) ) { return ''; }
        if ( ! @flock( $guard, LOCK_EX | LOCK_NB ) ) { @fclose( $guard ); return ''; }
        try {
            clearstatcache( true, $path );
            if ( is_readable( $path ) ) {
                $existing = include $path;
                if ( is_string( $existing ) && strlen( $existing ) >= 32 ) { $key = $existing; }
                elseif ( ! $this->write_secret( $key ) ) { return ''; }
            } elseif ( ! $this->write_secret( $key ) ) { return ''; }
        } finally { @flock( $guard, LOCK_UN ); @fclose( $guard ); }
        $this->signing_key_runtime = $key;
        return $key;
    }

    public function save( array $input ): void {
        $jpeg = ! empty( $input['progressive_jpeg'] );
        $png = ! empty( $input['interlaced_png'] );
        $encoding_changed = $jpeg !== $this->encoding_enabled( 'image/jpeg' ) || $png !== $this->encoding_enabled( 'image/png' );
        $format = isset( $input['format'] ) ? sanitize_key( (string) $input['format'] ) : 'auto';
        if ( ! in_array( $format, array( 'auto', 'webp', 'avif', 'original' ), true ) ) {
            $format = 'auto';
        }

        $mode = isset( $input['core_size_mode'] ) ? sanitize_key( (string) $input['core_size_mode'] ) : 'adaptive';
        if ( ! in_array( $mode, array( 'safe', 'adaptive', 'strict' ), true ) ) {
            $mode = 'adaptive';
        }

        $placeholder_mode = isset( $input['placeholder_mode'] ) ? sanitize_key( (string) $input['placeholder_mode'] ) : 'auto';
        if ( ! in_array( $placeholder_mode, array( 'none', 'color', 'gradient', 'lqip', 'auto' ), true ) ) {
            $placeholder_mode = 'auto';
        }

        $placeholder_color = isset( $input['placeholder_color'] ) ? sanitize_hex_color( (string) $input['placeholder_color'] ) : '#e5e7eb';
        if ( ! is_string( $placeholder_color ) || '' === $placeholder_color ) {
            $placeholder_color = '#e5e7eb';
        }

        $lqip_width = absint( $input['lqip_width'] ?? 64 );
        if ( ! in_array( $lqip_width, array( 16, 32, 64, 128, 256 ), true ) ) {
            $lqip_width = 64;
        }

        $widths_raw = isset( $input['widths'] ) ? preg_split( '/[\s,]+/', (string) $input['widths'] ) : array();
        $widths     = array_values( array_unique( array_filter( array_map( 'absint', (array) $widths_raw ) ) ) );
        $widths     = array_values( array_filter( $widths, static fn( int $w ): bool => $w >= 64 && $w <= 8192 ) );
        sort( $widths, SORT_NUMERIC );
        if ( empty( $widths ) ) {
            $widths = self::defaults()['widths'];
        }

        $this->values = array(
            'core_size_mode'        => $mode,
            'progressive_jpeg'      => $jpeg,
            'interlaced_png'        => $png,
            'format'                => $format,
            'quality'               => max( 1, min( 100, absint( $input['quality'] ?? 82 ) ) ),
            'placeholder_mode'      => $placeholder_mode,
            'placeholder_color'     => $placeholder_color,
            'lqip_width'            => $lqip_width,
            'lqip_quality'          => max( 8, min( 50, absint( $input['lqip_quality'] ?? 24 ) ) ),
            'lqip_max_bytes'        => max( 768, min( 16384, absint( $input['lqip_max_bytes'] ?? 3072 ) ) ),
            'lqip_max_per_request'  => max( 0, min( 100, absint( $input['lqip_max_per_request'] ?? 8 ) ) ),
            'lqip_max_total_bytes'  => max( 4096, min( 262144, absint( $input['lqip_max_total_bytes'] ?? 24576 ) ) ),
            'widths'                => $widths,
            'max_srcset_candidates' => max( 2, min( 6, absint( $input['max_srcset_candidates'] ?? 4 ) ) ),
            'upload_optimizer_enabled' => ! empty( $input['upload_optimizer_enabled'] ),
            'upload_max_width'      => max( 320, min( 8192, absint( $input['upload_max_width'] ?? 2560 ) ) ),
            'upload_max_height'     => max( 320, min( 8192, absint( $input['upload_max_height'] ?? 2560 ) ) ),
            'upload_max_source_mb'  => max( 1, min( 100, absint( $input['upload_max_source_mb'] ?? 25 ) ) ),
            'cache_namespace'       => $encoding_changed ? 'v' . bin2hex( random_bytes( 4 ) ) : $this->cache_namespace(),
        );

        $this->persist();
    }

    private function normalize( array $input ): array {
        $defaults = self::defaults();

        // Upgrade v0.1's boolean setting without surprising existing installs.
        if ( ! isset( $input['core_size_mode'] ) && array_key_exists( 'disable_core_sizes', $input ) ) {
            $input['core_size_mode'] = ! empty( $input['disable_core_sizes'] ) ? 'strict' : 'safe';
        }
        if ( ! isset( $input['placeholder_mode'] ) && array_key_exists( 'progressive', $input ) ) {
            // v0.2 switches the default progressive implementation to a zero-byte color placeholder.
            $input['placeholder_mode'] = ! empty( $input['progressive'] ) ? 'color' : 'none';
        }

        $values = wp_parse_args( $input, $defaults );
        $values['progressive_jpeg'] = ! empty( $values['progressive_jpeg'] );
        $values['interlaced_png'] = ! empty( $values['interlaced_png'] );

        $mode = (string) $values['core_size_mode'];
        $values['core_size_mode'] = in_array( $mode, array( 'safe', 'adaptive', 'strict' ), true ) ? $mode : 'adaptive';

        $format = (string) $values['format'];
        $values['format'] = in_array( $format, array( 'auto', 'webp', 'avif', 'original' ), true ) ? $format : 'auto';

        $placeholder = (string) $values['placeholder_mode'];
        $values['placeholder_mode'] = in_array( $placeholder, array( 'none', 'color', 'gradient', 'lqip', 'auto' ), true ) ? $placeholder : 'auto';
        $values['placeholder_color'] = preg_match( '/^#[a-f0-9]{6}$/i', (string) $values['placeholder_color'] ) ? strtolower( (string) $values['placeholder_color'] ) : '#e5e7eb';
        $values['quality'] = max( 1, min( 100, absint( $values['quality'] ) ) );
        $lqip_width = absint( $values['lqip_width'] ?? 64 );
        $values['lqip_width'] = in_array( $lqip_width, array( 16, 32, 64, 128, 256 ), true ) ? $lqip_width : 64;
        $values['lqip_quality'] = max( 8, min( 50, absint( $values['lqip_quality'] ?? 24 ) ) );
        $values['lqip_max_bytes'] = max( 768, min( 16384, absint( $values['lqip_max_bytes'] ?? 3072 ) ) );
        $values['lqip_max_per_request'] = max( 0, min( 100, absint( $values['lqip_max_per_request'] ?? 8 ) ) );
        $values['lqip_max_total_bytes'] = max( 4096, min( 262144, absint( $values['lqip_max_total_bytes'] ?? 24576 ) ) );

        $widths = array_values( array_unique( array_filter( array_map( 'absint', (array) $values['widths'] ) ) ) );
        $widths = array_values( array_filter( $widths, static fn( int $w ): bool => $w >= 64 && $w <= 8192 ) );
        sort( $widths, SORT_NUMERIC );
        $values['widths'] = $widths ?: $defaults['widths'];
        $values['max_srcset_candidates'] = max( 2, min( 6, absint( $values['max_srcset_candidates'] ) ) );
        $values['upload_optimizer_enabled'] = ! empty( $values['upload_optimizer_enabled'] );
        $values['upload_max_width'] = max( 320, min( 8192, absint( $values['upload_max_width'] ?? 2560 ) ) );
        $values['upload_max_height'] = max( 320, min( 8192, absint( $values['upload_max_height'] ?? 2560 ) ) );
        $values['upload_max_source_mb'] = max( 1, min( 100, absint( $values['upload_max_source_mb'] ?? 25 ) ) );

        $namespace = (string) $values['cache_namespace'];
        $values['cache_namespace'] = preg_match( '/^[a-zA-Z0-9_-]{4,32}$/', $namespace ) ? $namespace : 'v0200';

        unset( $values['disable_core_sizes'], $values['progressive'] );
        return $values;
    }

    private function persist(): void {
        if ( ! $this->write_runtime_file() ) { throw new \RuntimeException( 'MediaFlow could not persist runtime settings. Check private directory permissions and disk space.' ); }
        update_option( self::OPTION, $this->values, false );
    }

    private function runtime_path(): string {
        return $this->paths->settings_path();
    }

    private function read_runtime_file(): ?array {
        $path = $this->runtime_path();
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $json = file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function read_legacy_runtime_file(): ?array {
        $path = $this->paths->legacy_settings_path();
        if ( ! is_readable( $path ) ) {
            return null;
        }
        $json = file_get_contents( $path );
        $data = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $data ) ? $data : null;
    }

    private function write_runtime_file(): bool {
        $this->paths->ensure();
        $path = $this->runtime_path();
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        try {
            $suffix = bin2hex( random_bytes( 6 ) );
        } catch ( \Throwable $e ) {
            $suffix = uniqid( '', true );
        }

        $tmp  = $path . '.' . $suffix . '.tmp';
        $json = wp_json_encode( $this->values, JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            return false;
        }
        if ( ! @chmod( $tmp, 0600 ) || ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }
        @chmod( $path, 0600 );
        return true;
    }

    private function write_secret( string $key ): bool {
        $this->paths->ensure();
        $path = $this->paths->secret_path();
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $php = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn " . var_export( $key, true ) . ";\n";
        try {
            $suffix = bin2hex( random_bytes( 6 ) );
        } catch ( \Throwable $e ) {
            $suffix = uniqid( '', true );
        }
        $tmp = $path . '.' . $suffix . '.tmp';
        if ( false === @file_put_contents( $tmp, $php, LOCK_EX ) || ! @chmod( $tmp, 0600 ) || ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }
        @chmod( $path, 0600 );
        return true;
    }
}
