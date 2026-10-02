<?php
namespace MediaFlow;

/** WooCommerce-specific compatibility and critical product warming. */
final class WooCommerce_Adapter {
    private Warmer $warmer;

    public function __construct( Warmer $warmer ) { $this->warmer = $warmer; }

    public function hooks(): void {
        add_filter( 'mediaflow_preserved_physical_sizes', array( $this, 'preserve_sizes' ), 20, 3 );
        add_filter( 'qmediaflow_warm_widths', array( $this, 'warm_widths' ) );
        add_action( 'woocommerce_update_product', array( $this, 'warm_product' ), 40, 1 );
        add_action( 'woocommerce_new_product', array( $this, 'warm_product' ), 40, 1 );
    }

    public function preserve_sizes( array $sizes, int $attachment_id, string $mode ): array {
        if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) { return $sizes; }
        // Woo templates generally use normal WP image APIs, but these registered
        // names are retained in Adaptive mode for compatibility with extensions
        // that still concatenate physical WooCommerce thumbnail filenames.
        if ( 'adaptive' === $mode ) {
            $sizes = array_merge( $sizes, array( 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ) );
        }
        return array_values( array_unique( $sizes ) );
    }

    public function warm_widths( array $widths ): array {
        if ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ) {
            $widths = array_merge( $widths, array( 300, 600, 1200 ) );
        }
        $widths = array_values( array_unique( array_map( 'absint', $widths ) ) );
        sort( $widths, SORT_NUMERIC );
        return $widths;
    }

    public function warm_product( int $product_id ): void { $this->warmer->warm_post( $product_id ); }
}
