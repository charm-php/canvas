<?php declare(strict_types=1);

namespace Charm\Canvas\Codec;

use Charm\Canvas\RendererInterface;
use Charm\Canvas\Surface;

/**
 * Image import/export backend.
 *
 * Rasterisation lives in Surface and knows nothing about drawing libraries;
 * a backend only turns encoded bytes or native images into a Surface and back.
 * That is the seam a future Cairo, Imagick-native or pure-PHP encoder plugs
 * into.
 */
interface CodecInterface
{
    public static function isAvailable(): bool;

    public function name(): string;

    /** Decode image bytes (PNG, JPEG, GIF, WebP, BMP, ...). */
    public function decode(string $bytes): ?Surface;

    public function encodePng(RendererInterface $surface): string;

    public function encodeJpeg(RendererInterface $surface, int $quality): string;

    public function fromGdImage(\GdImage $image): Surface;

    public function toGdImage(RendererInterface $surface): \GdImage;

    public function fromImagick(\Imagick $imagick): Surface;

    public function toImagick(RendererInterface $surface): \Imagick;
}
