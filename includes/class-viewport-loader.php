<?php
namespace MediaFlow;

/**
 * Viewport-aware progressive delivery for below-the-fold QMediaFlow images.
 *
 * WordPress remains responsible for deciding which images are lazy. We only
 * intercept images that already carry loading="lazy" and never defer anything
 * marked fetchpriority="high". This keeps LCP/hero delivery immediate while
 * allowing below-the-fold images to retain a lightweight QMediaFlow placeholder
 * until they approach the viewport.
 */
final class Viewport_Loader {
    private static ?self $instance = null;
    private const TRANSPARENT_GIF = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public static function boot(): void {
        if ( null === self::$instance ) { self::$instance = new self(); }
        self::$instance->hooks();
    }

    private bool $hooked = false;

    private function hooks(): void {
        if ( $this->hooked || ! self::enabled() ) { return; }
        $this->hooked = true;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
        add_action( 'wp_head', array( $this, 'noscript_style' ), 1 );
        add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_html' ), 120, 5 );
        add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 120, 3 );
    }

    public function enqueue_assets(): void {
        if ( ! $this->frontend_enabled() ) { return; }

        wp_enqueue_style(
            'qmediaflow-viewport',
            QMEDIAFLOW_URL . 'assets/css/qmediaflow-viewport.css',
            array(),
            QMEDIAFLOW_VERSION
        );
        wp_add_inline_style(
            'qmediaflow-viewport',
            ':root{--qmediaflow-viewport-blur:' . self::blur_px() . 'px;--qmediaflow-viewport-reveal:' . self::reveal_ms() . 'ms;}'
        );

        wp_enqueue_script(
            'qmediaflow-viewport',
            QMEDIAFLOW_URL . 'assets/js/qmediaflow-viewport.js',
            array(),
            QMEDIAFLOW_VERSION,
            true
        );
        wp_script_add_data( 'qmediaflow-viewport', 'strategy', 'defer' );
        wp_add_inline_script(
            'qmediaflow-viewport',
            'window.QMediaFlowViewport=' . wp_json_encode(
                array(
                    'rootMargin' => self::viewport_margin() . 'px 0px',
                    'revealMs'   => self::reveal_ms(),
                ),
                JSON_UNESCAPED_SLASHES
            ) . ';',
            'before'
        );
    }

    public function noscript_style(): void {
        if ( ! $this->frontend_enabled() ) { return; }
        echo '<noscript><style>img[data-qmediaflow-viewport="1"]{display:none!important}</style></noscript>';
    }

    public function filter_attachment_html( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
        if ( $icon || $attachment_id < 1 ) { return $html; }
        return $this->defer_html( $html, 'attachment' );
    }

    public function filter_content_img_tag( string $html, string $context, int $attachment_id ): string {
        if ( $attachment_id < 1 ) { return $html; }
        return $this->defer_html( $html, 'content:' . sanitize_key( $context ) );
    }

    private function defer_html( string $html, string $context ): string {
        if ( ! $this->frontend_enabled() || '' === $html || ! str_contains( $html, '<img' ) ) { return $html; }
        if ( str_contains( $html, 'data-qmediaflow-viewport="1"' ) ) { return $html; }

        // WordPress already applies its LCP/lazy heuristics. QMediaFlow intentionally
        // builds on that signal instead of inventing a second critical-image policy.
        if ( ! preg_match( '/<img\b[^>]*\bloading=(?:"|\')lazy(?:"|\')/i', $html ) ) { return $html; }
        if ( preg_match( '/<img\b[^>]*\bfetchpriority=(?:"|\')high(?:"|\')/i', $html ) ) { return $html; }
        if ( preg_match( '/<img\b[^>]*\bdata-qmediaflow-viewport=(?:"|\')(?:off|0)(?:"|\')/i', $html ) ) { return $html; }

        $defer = function_exists( 'apply_filters' )
            ? (bool) apply_filters( 'qmediaflow_viewport_defer', true, $html, $context )
            : true;
        if ( ! $defer || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return $html; }

        $processor = new \WP_HTML_Tag_Processor( $html );
        $deferred = false;
        while ( $processor->next_tag() ) {
            $tag = strtoupper( (string) $processor->get_tag() );
            if ( 'SOURCE' === $tag ) {
                $srcset = $processor->get_attribute( 'srcset' );
                if ( is_string( $srcset ) && '' !== $srcset ) {
                    $processor->set_attribute( 'data-qmediaflow-srcset', $srcset );
                    $processor->remove_attribute( 'srcset' );
                }
                continue;
            }
            if ( 'IMG' !== $tag ) { continue; }

            $loading = strtolower( trim( (string) $processor->get_attribute( 'loading' ) ) );
            $priority = strtolower( trim( (string) $processor->get_attribute( 'fetchpriority' ) ) );
            if ( 'lazy' !== $loading || 'high' === $priority ) { continue; }

            $src = $processor->get_attribute( 'src' );
            if ( ! is_string( $src ) || '' === $src ) { continue; }
            $processor->set_attribute( 'data-qmediaflow-src', $src );
            $processor->set_attribute( 'src', self::TRANSPARENT_GIF );

            $srcset = $processor->get_attribute( 'srcset' );
            if ( is_string( $srcset ) && '' !== $srcset ) {
                $processor->set_attribute( 'data-qmediaflow-srcset', $srcset );
                $processor->remove_attribute( 'srcset' );
            }

            $classes = trim( (string) $processor->get_attribute( 'class' ) );
            if ( ! preg_match( '/(?:^|\s)qmediaflow-viewport(?:\s|$)/', $classes ) ) {
                $processor->set_attribute( 'class', trim( $classes . ' qmediaflow-viewport' ) );
            }
            $processor->set_attribute( 'data-qmediaflow-viewport', '1' );
            $processor->set_attribute( 'data-qmediaflow-state', 'pending' );
            if ( ! $processor->get_attribute( 'decoding' ) ) { $processor->set_attribute( 'decoding', 'async' ); }
            $deferred = true;
        }

        if ( ! $deferred ) { return $html; }
        $deferred_html = $processor->get_updated_html();
        $fallback = $this->restore_html( $deferred_html );
        if ( '' === $fallback ) { return $html; }
        return $deferred_html . '<noscript class="qmediaflow-noscript">' . $fallback . '</noscript>';
    }

    private function restore_html( string $html ): string {
        if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) { return ''; }
        $processor = new \WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag() ) {
            $tag = strtoupper( (string) $processor->get_tag() );
            if ( 'SOURCE' === $tag ) {
                $srcset = $processor->get_attribute( 'data-qmediaflow-srcset' );
                if ( is_string( $srcset ) && '' !== $srcset ) { $processor->set_attribute( 'srcset', $srcset ); }
                $processor->remove_attribute( 'data-qmediaflow-srcset' );
                continue;
            }
            if ( 'IMG' !== $tag ) { continue; }
            $src = $processor->get_attribute( 'data-qmediaflow-src' );
            if ( is_string( $src ) && '' !== $src ) { $processor->set_attribute( 'src', $src ); }
            $srcset = $processor->get_attribute( 'data-qmediaflow-srcset' );
            if ( is_string( $srcset ) && '' !== $srcset ) { $processor->set_attribute( 'srcset', $srcset ); }
            foreach ( array( 'data-qmediaflow-src', 'data-qmediaflow-srcset', 'data-qmediaflow-viewport', 'data-qmediaflow-state' ) as $attribute ) {
                $processor->remove_attribute( $attribute );
            }
        }
        return $processor->get_updated_html();
    }

    private function frontend_enabled(): bool {
        if ( ! self::enabled() ) { return false; }
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_feed' ) && is_feed() ) ) { return false; }
        $settings = Plugin::instance()->settings();
        return $settings->progressive();
    }

    private static function enabled(): bool {
        return ! defined( 'QMEDIAFLOW_VIEWPORT_LOADING' ) || (bool) QMEDIAFLOW_VIEWPORT_LOADING;
    }

    private static function viewport_margin(): int {
        $value = defined( 'QMEDIAFLOW_VIEWPORT_MARGIN' ) ? (int) QMEDIAFLOW_VIEWPORT_MARGIN : 250;
        return max( 0, min( 2000, $value ) );
    }

    private static function reveal_ms(): int {
        $value = defined( 'QMEDIAFLOW_VIEWPORT_REVEAL_MS' ) ? (int) QMEDIAFLOW_VIEWPORT_REVEAL_MS : 180;
        return max( 0, min( 1500, $value ) );
    }

    private static function blur_px(): int {
        $value = defined( 'QMEDIAFLOW_VIEWPORT_BLUR_PX' ) ? (int) QMEDIAFLOW_VIEWPORT_BLUR_PX : 12;
        return max( 0, min( 32, $value ) );
    }
}
