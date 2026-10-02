<?php
namespace MediaFlow;

final class Variant {
    public int $width;
    public int $height;
    public bool $crop;
    public int $quality;
    public string $format;
    public string $extension;
    public string $mime;
    public string $signature;
    public int $focal_x;
    public int $focal_y;

    public function __construct( int $width, int $height, bool $crop, int $quality, string $format, string $signature = '', int $focal_x = 50, int $focal_y = 50 ) {
        $this->width     = max( 1, $width );
        $this->height    = max( 0, $height );
        $this->crop      = $crop;
        $this->quality   = max( 1, min( 100, $quality ) );
        $this->format    = $format;
        $this->extension = self::extension_for_format( $format );
        $this->mime      = self::mime_for_format( $format );
        $this->signature = $signature;
        $this->focal_x   = max( 0, min( 100, $focal_x ) );
        $this->focal_y   = max( 0, min( 100, $focal_y ) );
    }

    public function with_signature( string $signature ): self {
        $copy            = clone $this;
        $copy->signature = $signature;
        return $copy;
    }

    public function has_focal_point(): bool {
        return $this->crop && ( 50 !== $this->focal_x || 50 !== $this->focal_y );
    }

    public function token(): string {
        $focal = $this->has_focal_point() ? sprintf( '-fx%d-fy%d', $this->focal_x, $this->focal_y ) : '';
        return sprintf(
            'w%d-h%d-c%d-q%d%s-s%s',
            $this->width,
            $this->height,
            $this->crop ? 1 : 0,
            $this->quality,
            $focal,
            $this->signature
        );
    }

    public static function mime_for_format( string $format ): string {
        return match ( $format ) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'png'  => 'image/png',
            default => 'image/jpeg',
        };
    }

    public static function extension_for_format( string $format ): string {
        return match ( $format ) {
            'jpeg' => 'jpg',
            default => $format,
        };
    }

    public static function format_from_mime( string $mime ): string {
        return match ( $mime ) {
            'image/avif' => 'avif',
            'image/webp' => 'webp',
            'image/png'  => 'png',
            default      => 'jpeg',
        };
    }
}
