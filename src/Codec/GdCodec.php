<?php declare(strict_types=1);

namespace phasync\Canvas\Codec;

use phasync\Canvas\NotSupportedException;
use phasync\Canvas\RendererInterface;
use phasync\Canvas\Surface;

/**
 * GD-backed import/export.
 *
 * GD is used as a codec only: bytes and native images become Surfaces and back.
 * No drawing primitive of GD is used to render anything, so the raster output
 * does not depend on GD's polygon or line semantics.
 */
final class GdCodec implements CodecInterface
{
    public static function isAvailable(): bool
    {
        return \extension_loaded('gd');
    }

    public function name(): string
    {
        return 'gd';
    }

    public function decode(string $bytes): ?Surface
    {
        if ($bytes === '') {
            return null;
        }
        $image = @\imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            return null;
        }
        return $this->fromGdImage($image);
    }

    public function fromGdImage(\GdImage $image): Surface
    {
        $w = \imagesx($image);
        $h = \imagesy($image);
        if (!\imageistruecolor($image)) {
            \imagepalettetotruecolor($image);
        }
        \imagealphablending($image, false);
        \imagesavealpha($image, true);

        // A truecolor GD pixel is the Surface's own pixel format: no remapping.
        // (imagegd2()/imagegd() would allow a bulk transfer, but common PHP
        // builds ship without GD/GD2 format support, so pixels go one by one.)
        $surface = new Surface($w, $h);
        $packed = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $packed[] = \imagecolorat($image, $x, $y);
            }
        }
        $surface->setPixels($packed);
        return $surface;
    }

    public function toGdImage(RendererInterface $surface): \GdImage
    {
        $w = $surface->width;
        $h = $surface->height;
        $image = \imagecreatetruecolor($w, $h);
        \imagealphablending($image, false);
        \imagesavealpha($image, true);
        // Start fully transparent so transparent pixels need no write.
        \imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, 0x7F000000);

        $px = $surface->clonePixels();
        $i = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = $px[$i++];
                if ($p !== 0x7F000000) {
                    \imagesetpixel($image, $x, $y, $p);
                }
            }
        }
        return $image;
    }

    public function encodePng(RendererInterface $surface): string
    {
        $image = $this->toGdImage($surface);
        \ob_start();
        \imagepng($image);
        $data = (string) \ob_get_clean();
        return $data;
    }

    public function encodeJpeg(RendererInterface $surface, int $quality): string
    {
        // JPEG has no alpha; browsers composite onto black.
        $image = $this->toGdImage($surface);
        $flat = \imagecreatetruecolor($surface->width, $surface->height);
        $black = \imagecolorallocate($image, 0, 0, 0);
        if ($black !== false) {
            \imagealphablending($image, true);
            \imagefilledrectangle($image, 0, 0, $surface->width, $surface->height, $black);
        }
        \imagealphablending($image, false);
        \imagealphablending($flat, false);
        \imagecopy($flat, $image, 0, 0, 0, 0, $surface->width, $surface->height);

        \ob_start();
        \imagejpeg($flat, null, max(0, min(100, $quality)));
        $data = (string) \ob_get_clean();
        return $data;
    }

    public function fromImagick(\Imagick $imagick): Surface
    {
        $clone = clone $imagick;
        $clone->setImageFormat('PNG');
        $bytes = $clone->getImageBlob();
        $clone->destroy();
        $surface = $this->decode($bytes);
        if ($surface === null) {
            throw new \RuntimeException('Unable to decode Imagick image');
        }
        return $surface;
    }

    public function toImagick(RendererInterface $surface): \Imagick
    {
        if (!\extension_loaded('imagick')) {
            throw new NotSupportedException('The imagick extension is not loaded');
        }
        $imagick = new \Imagick();
        $imagick->readImageBlob($this->encodePng($surface));
        return $imagick;
    }
}
