<?php
namespace MediaFlow;

/** Builds focal-aware variants while preserving the legacy signature for center crops. */
final class Focal_Resolver {
    public static function url( int $attachment_id, array $manifest, array $spec, int $focal_x, int $focal_y ) {
        $variant = self::variant( $attachment_id, $manifest, $spec, $focal_x, $focal_y );
        return $variant ? Plugin::instance()->resolver()->url( $attachment_id, $manifest, $variant ) : false;
    }

    public static function variant( int $attachment_id, array $manifest, array $spec, int $focal_x, int $focal_y ): ?Variant {
        $base = Plugin::instance()->resolver()->variant_for( $attachment_id, $manifest, $spec );
        if ( ! $base ) { return null; }
        $focal_x = max( 0, min( 100, $focal_x ) );
        $focal_y = max( 0, min( 100, $focal_y ) );
        if ( ! $base->crop || ( 50 === $focal_x && 50 === $focal_y ) ) { return $base; }

        $variant = new Variant( $base->width, $base->height, $base->crop, $base->quality, $base->format, '', $focal_x, $focal_y );
        $namespace = Plugin::instance()->settings()->cache_namespace();
        return $variant->with_signature( self::signature( $attachment_id, (string) $manifest['revision'], $namespace, $variant ) );
    }

    public static function validate( int $attachment_id, string $revision, string $namespace, Variant $variant ): bool {
        if ( ! $variant->has_focal_point() ) { return Plugin::instance()->resolver()->validate_signature( $attachment_id, $revision, $namespace, $variant ); }
        $expected = self::signature( $attachment_id, $revision, $namespace, $variant );
        return '' !== $expected && hash_equals( $expected, $variant->signature );
    }

    private static function signature( int $attachment_id, string $revision, string $namespace, Variant $variant ): string {
        $plugin = Plugin::instance();
        $payload = array(
            $plugin->paths()->site_id() ? 'mf3-site-' . $plugin->paths()->site_id() : 'mf2',
            $attachment_id,
            $revision,
            $namespace,
            $variant->width,
            $variant->height,
            $variant->crop ? 1 : 0,
            $variant->quality,
            $variant->format,
            $variant->focal_x,
            $variant->focal_y,
        );
        $key = $plugin->settings()->signing_key();
        return '' === $key ? '' : substr( hash_hmac( 'sha256', implode( '|', $payload ), $key ), 0, 32 );
    }
}
