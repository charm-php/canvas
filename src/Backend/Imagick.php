<?php declare(strict_types=1);

namespace phasync\Canvas\Backend;

use phasync\Canvas\Gradient;
use phasync\Canvas\NotSupportedException;

/**
 * Imagick backend for Canvas.
 * Falls back to GD for rendering operations.
 */
class Imagick implements BackendInterface
{
    private \Imagick $imagick;
    private int $width;
    private int $height;
    private Gd $gdBackend;

    public function __construct(int $width, int $height)
    {
        if (!extension_loaded('imagick')) {
            throw new NotSupportedException('Imagick extension is not loaded');
        }

        $this->width = $width;
        $this->height = $height;

        $this->imagick = new \Imagick();
        $this->imagick->newImage($width, $height, new \ImagickPixel('transparent'));
        $this->imagick->setImageFormat('png');

        // Create a GD backend for actual rendering
        $this->gdBackend = new Gd($width, $height);
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function save(): void
    {
        $this->gdBackend->save();
    }

    public function restore(): void
    {
        $this->gdBackend->restore();
    }

    public function setFillStyle(string $color): void
    {
        $this->gdBackend->setFillStyle($color);
    }

    public function setStrokeStyle(string $color): void
    {
        $this->gdBackend->setStrokeStyle($color);
    }

    public function setFillStyleGradient(Gradient $gradient): void
    {
        $this->gdBackend->setFillStyleGradient($gradient);
    }

    public function setStrokeStyleGradient(Gradient $gradient): void
    {
        $this->gdBackend->setStrokeStyleGradient($gradient);
    }

    public function setLineWidth(float $width): void
    {
        $this->gdBackend->setLineWidth($width);
    }

    public function setLineCap(string $cap): void
    {
        $this->gdBackend->setLineCap($cap);
    }

    public function setLineJoin(string $join): void
    {
        $this->gdBackend->setLineJoin($join);
    }

    public function setGlobalAlpha(float $alpha): void
    {
        $this->gdBackend->setGlobalAlpha($alpha);
    }

    public function setFont(string $font): void
    {
        $this->gdBackend->setFont($font);
    }

    public function setTextAlign(string $align): void
    {
        $this->gdBackend->setTextAlign($align);
    }

    public function setTextBaseline(string $baseline): void
    {
        $this->gdBackend->setTextBaseline($baseline);
    }

    public function beginPath(): void
    {
        $this->gdBackend->beginPath();
    }

    public function closePath(): void
    {
        $this->gdBackend->closePath();
    }

    public function moveTo(float $x, float $y): void
    {
        $this->gdBackend->moveTo($x, $y);
    }

    public function lineTo(float $x, float $y): void
    {
        $this->gdBackend->lineTo($x, $y);
    }

    public function arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->gdBackend->arc($x, $y, $radius, $startAngle, $endAngle, $counterclockwise);
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        $this->gdBackend->arcTo($x1, $y1, $x2, $y2, $radius);
    }

    public function ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->gdBackend->ellipse($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $counterclockwise);
    }

    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): void
    {
        $this->gdBackend->bezierCurveTo($cp1x, $cp1y, $cp2x, $cp2y, $x, $y);
    }

    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): void
    {
        $this->gdBackend->quadraticCurveTo($cpx, $cpy, $x, $y);
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->gdBackend->rect($x, $y, $w, $h);
    }

    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii): void
    {
        $this->gdBackend->roundRect($x, $y, $w, $h, $radii);
    }

    public function fill(): void
    {
        $this->gdBackend->fill();
    }

    public function stroke(): void
    {
        $this->gdBackend->stroke();
    }

    public function fillRect(float $x, float $y, float $w, float $h): void
    {
        $this->gdBackend->fillRect($x, $y, $w, $h);
    }

    public function strokeRect(float $x, float $y, float $w, float $h): void
    {
        $this->gdBackend->strokeRect($x, $y, $w, $h);
    }

    public function clearRect(float $x, float $y, float $w, float $h): void
    {
        $this->gdBackend->clearRect($x, $y, $w, $h);
    }

    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->gdBackend->fillText($text, $x, $y, $maxWidth);
    }

    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->gdBackend->strokeText($text, $x, $y, $maxWidth);
    }

    public function measureText(string $text): array
    {
        return $this->gdBackend->measureText($text);
    }

    public function drawImage($source, ...$args): void
    {
        $this->gdBackend->drawImage($source, ...$args);
    }

    public function getImageData(float $x, float $y, float $w, float $h): array
    {
        return $this->gdBackend->getImageData($x, $y, $w, $h);
    }

    public function putImageData(array $imageData, float $x, float $y): void
    {
        $this->gdBackend->putImageData($imageData, $x, $y);
    }

    public function translate(float $x, float $y): void
    {
        $this->gdBackend->translate($x, $y);
    }

    public function scale(float $x, float $y): void
    {
        $this->gdBackend->scale($x, $y);
    }

    public function rotate(float $angle): void
    {
        $this->gdBackend->rotate($angle);
    }

    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->gdBackend->transform($a, $b, $c, $d, $e, $f);
    }

    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->gdBackend->setTransform($a, $b, $c, $d, $e, $f);
    }

    public function resetTransform(): void
    {
        $this->gdBackend->resetTransform();
    }

    public function clip(): void
    {
        $this->gdBackend->clip();
    }

    public function createLinearGradient(float $x0, float $y0, float $x1, float $y1): Gradient
    {
        return $this->gdBackend->createLinearGradient($x0, $y0, $x1, $y1);
    }

    public function createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1): Gradient
    {
        return $this->gdBackend->createRadialGradient($x0, $y0, $r0, $x1, $y1, $r1);
    }

    public function toPng(): string
    {
        // Sync GD content to Imagick
        $this->syncToImagick();
        return (string)$this->imagick->getImageBlob();
    }

    public function toJpeg(int $quality): string
    {
        $this->syncToImagick();
        $this->imagick->setImageFormat('jpeg');
        $this->imagick->setImageCompressionQuality($quality);
        return (string)$this->imagick->getImageBlob();
    }

    public function toGd(): \GdImage
    {
        $this->syncToImagick();
        return $this->gdBackend->toGd();
    }

    public function toImagick(): \Imagick
    {
        $this->syncToImagick();
        return $this->imagick;
    }

    public function toPixels(): array
    {
        $this->syncToImagick();
        return $this->gdBackend->toPixels();
    }

    public function toDots(int $w, int $h): array
    {
        $this->syncToImagick();
        return $this->gdBackend->toDots($w, $h);
    }

    public function fromGd(\GdImage $image): void
    {
        $this->gdBackend->fromGd($image);
    }

    public function fromImagick(\Imagick $imagick): void
    {
        $this->imagick = clone $imagick;
        $blob = $this->imagick->getImageBlob();
        $tempFile = \tempnam(\sys_get_temp_dir(), 'canvas_');
        \file_put_contents($tempFile, $blob);
        $gdImage = \imagecreatefromfile($tempFile);
        @\unlink($tempFile);

        if ($gdImage !== false) {
            $this->gdBackend->fromGd($gdImage);
        }
    }

    /**
     * Sync GD content to Imagick.
     */
    private function syncToImagick(): void
    {
        $pngData = $this->gdBackend->toPng();
        $tempFile = \tempnam(\sys_get_temp_dir(), 'canvas_');
        \file_put_contents($tempFile, $pngData);

        $this->imagick = new \Imagick($tempFile);
        @\unlink($tempFile);
    }
}
