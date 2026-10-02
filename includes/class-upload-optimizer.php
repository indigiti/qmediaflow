<?php
namespace MediaFlow;

/**
 * Browser-first source upload optimizer.
 *
 * The browser produces the final WebP source before WordPress receives it.
 * PHP never resizes or recompresses the upload in this path; it only verifies
 * the resulting MIME type and hard byte ceiling before normal WordPress upload
 * handling continues.
 */
final class Upload_Optimizer {
    public const TARGET_BYTES = 480000; // 480 KB working target.
    public const HARD_BYTES   = 500000; // 500 KB final ceiling.

    private Settings $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    public function hooks(): void {
        add_filter( 'wp_client_side_media_processing_enabled', static fn( $enabled ) => Plugin::instance()->upload_optimizer()->disable_core_client_processing( (bool) $enabled ), 1000 );
        add_action( 'admin_enqueue_scripts', static fn( $hook_suffix ) => Plugin::instance()->upload_optimizer()->enqueue( (string) $hook_suffix ), 1000, 1 );
        add_filter( 'wp_handle_upload_prefilter', static fn( $file ) => Plugin::instance()->upload_optimizer()->validate_upload( (array) $file ), 1000 );
        add_filter( 'wp_handle_sideload_prefilter', static fn( $file ) => Plugin::instance()->upload_optimizer()->validate_sideload( (array) $file ), 1000 );
    }

    /** WordPress 7.1+ client processing is disabled when QMediaFlow owns the transform. */
    public function disable_core_client_processing( bool $enabled ): bool {
        return $this->settings->upload_optimizer_enabled() ? false : $enabled;
    }

    public function enqueue( string $hook_suffix = '' ): void {
        if ( ! $this->settings->upload_optimizer_enabled() || ! current_user_can( 'upload_files' ) ) {
            return;
        }

        wp_enqueue_script(
            'mediaflow-upload-optimizer',
            MEDIAFLOW_URL . 'assets/js/mediaflow-upload.js',
            array(),
            MEDIAFLOW_VERSION,
            true
        );

        $config = array(
            'workerUrl'      => MEDIAFLOW_URL . 'assets/js/mediaflow-upload-worker.js',
            'maxWidth'       => $this->settings->upload_max_width(),
            'maxHeight'      => $this->settings->upload_max_height(),
            'maxSourceBytes' => $this->settings->upload_max_source_bytes(),
            'targetBytes'    => self::TARGET_BYTES,
            'hardBytes'      => self::HARD_BYTES,
            'maxWorkers'     => class_exists( Runtime_Config::class ) ? Runtime_Config::upload_workers() : 2,
            'supportedTypes' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif' ),
            'messages'       => array(
                'unsupported' => 'QMediaFlow accepts JPEG, PNG, WebP, AVIF, HEIC or HEIF source images when the browser can decode them.',
                'tooLarge'    => 'The original image exceeds the QMediaFlow browser-processing size limit.',
                'processing'  => 'QMediaFlow could not optimize this image in the browser.',
                'finalSize'   => 'QMediaFlow could not reduce this image below the 500 KB upload ceiling.',
            ),
        );

        wp_add_inline_script(
            'mediaflow-upload-optimizer',
            'window.MediaFlowUploadConfig=' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) . ';',
            'before'
        );
    }

    public function validate_upload( array $file ): array {
        if ( ! $this->settings->upload_optimizer_enabled() ) {
            return $file;
        }
        if ( $this->looks_like_image( $file ) ) {
            return $this->validate_final_file( $file );
        }
        return $file;
    }

    public function validate_sideload( array $file ): array {
        if ( ! $this->settings->upload_optimizer_enabled() ) {
            return $file;
        }
        $marker = isset( $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] ) ) : '';
        if ( '1' !== $marker || ! $this->looks_like_image( $file ) ) {
            return $file;
        }
        return $this->validate_final_file( $file );
    }

    private function validate_final_file( array $file ): array {
        if ( ! empty( $file['error'] ) ) {
            return $file;
        }

        $tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        $mime = '';
        if ( '' !== $tmp && is_readable( $tmp ) && function_exists( 'wp_get_image_mime' ) ) {
            $mime = (string) wp_get_image_mime( $tmp );
        }
        if ( '' === $mime ) {
            $mime = strtolower( (string) ( $file['type'] ?? '' ) );
        }

        if ( 'image/webp' !== $mime ) {
            $file['error'] = 'QMediaFlow upload rejected: optimized images must arrive as WebP.';
            return $file;
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size <= 0 && '' !== $tmp && is_file( $tmp ) ) {
            $size = (int) filesize( $tmp );
        }
        if ( $size <= 0 || $size > self::HARD_BYTES ) {
            $file['error'] = 'QMediaFlow upload rejected: the final WebP must be 500 KB or smaller.';
            return $file;
        }

        return $file;
    }

    private function looks_like_image( array $file ): bool {
        $type = strtolower( (string) ( $file['type'] ?? '' ) );
        if ( str_starts_with( $type, 'image/' ) ) {
            return true;
        }
        $name = strtolower( (string) ( $file['name'] ?? '' ) );
        return (bool) preg_match( '/\.(?:jpe?g|png|webp|avif|gif|heic|heif)$/', $name );
    }
}
