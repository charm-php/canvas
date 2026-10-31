<?php declare(strict_types=1);

namespace phasync\Canvas\Backend;

use phasync\Canvas\Gradient;

/**
 * Interface for canvas backends (GD, Imagick, etc.).
 */
interface BackendInterface
{
    public function getWidth(): int;
    public function getHeight(): int;

    // Context state
    public function save(): void;
    public function restore(): void;

    // Style
    public function setFillStyle(string $color): void;
    public function setStrokeStyle(string $color): void;
    public function setFillStyleGradient(Gradient $gradient): void;
    public function setStrokeStyleGradient(Gradient $gradient): void;
    public function setLineWidth(float $width): void;
    public function setLineCap(string $cap): void;
    public function setLineJoin(string $join): void;
    public function setGlobalAlpha(float $alpha): void;
    public function setFont(string $font): void;
    public function setTextAlign(string $align): void;
    public function setTextBaseline(string $baseline): void;

    // Paths
    public function beginPath(): void;
    public function closePath(): void;
    public function moveTo(float $x, float $y): void;
    public function lineTo(float $x, float $y): void;
    public function arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false): void;
    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void;
    public function ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false): void;
    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): void;
    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): void;
    public function rect(float $x, float $y, float $w, float $h): void;
    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii): void;

    // Drawing
    public function fill(): void;
    public function stroke(): void;
    public function fillRect(float $x, float $y, float $w, float $h): void;
    public function strokeRect(float $x, float $y, float $w, float $h): void;
    public function clearRect(float $x, float $y, float $w, float $h): void;

    // Text
    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void;
    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void;
    public function measureText(string $text): array;

    // Images
    public function drawImage($source, ...$args): void;
    public function getImageData(float $x, float $y, float $w, float $h): array;
    public function putImageData(array $imageData, float $x, float $y): void;

    // Transforms
    public function translate(float $x, float $y): void;
    public function scale(float $x, float $y): void;
    public function rotate(float $angle): void;
    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void;
    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void;
    public function resetTransform(): void;

    // Clipping
    public function clip(): void;

    // Gradients
    public function createLinearGradient(float $x0, float $y0, float $x1, float $y1): Gradient;
    public function createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1): Gradient;

    // Output
    public function toPng(): string;
    public function toJpeg(int $quality): string;
    public function toGd(): \GdImage;
    public function toImagick(): \Imagick;
    public function toPixels(): array;
    public function toDots(int $w, int $h): array;

    // Import
    public function fromGd(\GdImage $image): void;
    public function fromImagick(\Imagick $imagick): void;
}
