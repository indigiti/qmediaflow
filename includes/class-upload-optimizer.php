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

    /**
     * WordPress 7.1+ has a separate client-side media pipeline. MediaFlow owns
     * the source transform when enabled, so running both would encode twice.
     */
    public function disable_core_client_processing( bool $enabled ): bool {
        return $this->browser_pipeline_enabled() ? false : $enabled;
    }

    public function enqueue( string $hook_suffix = '' ): void {
        if ( ! $this->browser_pipeline_enabled() || ! current_user_can( 'upload_files' ) ) {
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
            'supportedTypes' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif' ),
            'messages'       => array(
                'unsupported' => 'MediaFlow accepts JPEG, PNG, WebP, AVIF, HEIC or HEIF source images when the browser can decode them.',
                'tooLarge'    => 'The original image exceeds the MediaFlow browser-processing size limit.',
                'processing'  => 'MediaFlow could not optimize this image in the browser.',
                'finalSize'   => 'MediaFlow could not reduce this image below the 500 KB upload ceiling.',
            ),
        );

        wp_add_inline_script(
            'mediaflow-upload-optimizer',
            'window.MediaFlowUploadConfig=' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES ) . ';',
            'before'
        );
    }

    private function browser_pipeline_enabled(): bool {
        // The browser encoder emits WebP only. Do not intercept uploads if
        // the independent policy disallows WebP.
        return $this->settings->upload_optimizer_enabled()
            && ( ! $this->settings->newsroom_enabled() || in_array( 'image/webp', $this->settings->newsroom_mimes(), true ) );
    }

    public function validate_upload( array $file ): array {
        return $this->validate_ingestion( $file, false );
    }

    public function validate_sideload( array $file ): array {
        return $this->validate_ingestion( $file, true );
    }

    private function validate_ingestion( array $file, bool $sideload ): array {
        if ( ! empty( $file['error'] ) ) { return $file; }
        $policy = $this->settings->newsroom_enabled();
        $marked = '1' === ( $_SERVER['HTTP_X_MEDIAFLOW_UPLOAD'] ?? '' );
        // Server integrations may opt out for a specific trusted sideload call.
        // Neither a request parameter nor the browser marker grants this exemption.
        if ( $policy && $sideload && ! $marked && true === apply_filters( 'qmediaflow_newsroom_exempt_sideload', false ) ) { return $file; }
        $optimized = $this->browser_pipeline_enabled() && ( ! $sideload || $marked );
        if ( ! $policy && ! $optimized ) { return $file; }

        $tmp = (string) ( $file['tmp_name'] ?? '' );
        $readable = '' !== $tmp && is_file( $tmp ) && is_readable( $tmp );
        $mime = $readable ? (string) wp_get_image_mime( $tmp ) : '';
        if ( ! $this->looks_like_image( $file ) && ! str_starts_with( $mime, 'image/' ) ) { return $file; }

        // The marker is a browser compatibility hint, never an exemption from policy.
        $allowed = $policy ? $this->settings->newsroom_mimes() : array( 'image/webp' );
        if ( ! in_array( $mime, $allowed, true ) ) {
            $file['error'] = $policy ? 'QMediaFlow requires a valid image in one of these formats: ' . implode( ', ', $allowed ) . '.' : 'QMediaFlow requires a valid optimized WebP image.';
            return $file;
        }

        $limit = $policy ? $this->settings->newsroom_max_bytes() : self::HARD_BYTES;
        clearstatcache( true, $tmp );
        $size = @filesize( $tmp );
        if ( false === $size || $size < 1 || $size > $limit ) {
            $file['error'] = sprintf( 'QMediaFlow rejected the image: maximum %d bytes.', $limit );
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
