<?php declare(strict_types=1);

namespace Charm\Canvas;

/**
 * What a Context needs from the thing that holds and paints pixels.
 *
 * Two implementations: Surface (plain PHP, a flat int array) and
 * ImagickSurface (pixels in an Imagick object). Both expose public readonly
 * int $width and $height. Coordinates are device pixels in canvas convention:
 * pixel (i, j) covers [i, i+1] x [j, j+1].
 */
interface RendererInterface
{
    public function clear(): void;

    /** @param list<list<float>> $polygons */
    public function pushClip(array $polygons, string $rule = Raster::NONZERO): void;

    public function popClip(): void;

    public function clipDepth(): int;

    /** @param list<list<float>> $polygons */
    public function clearPolygons(array $polygons, string $rule = Raster::NONZERO): void;

    /** @param list<list<float>> $polygons device space */
    public function fillPolygons(array $polygons, Paint $paint, float $alpha, string $rule = Raster::NONZERO, string $mode = 'source-over'): void;

    public function fillRectDevice(float $x, float $y, float $w, float $h, Paint $paint, float $alpha, string $mode = 'source-over', bool $useClip = true): void;

    public function clearRectDevice(float $x, float $y, float $w, float $h): void;

    /** @param array{0:float,1:float,2:float,3:float}|null $subrect x0, y0, x1, y1 in source pixels */
    public function blitAffine(RendererInterface $src, Matrix $m, float $alpha, bool $smooth, string $mode = 'source-over', ?array $subrect = null, bool $snap = false): void;

    /** Paint a coverage mask (text glyphs): the mask's alpha is the coverage. */
    public function blitMask(Surface $mask, Matrix $m, Paint $paint, float $alpha, string $mode = 'source-over'): void;

    public function readRect(int $x, int $y, int $width, int $height): RendererInterface;

    /** Replace pixels verbatim: no blending, no clip. */
    public function writeSurface(RendererInterface $src, int $dx, int $dy): void;

    /** Raw RGBA8 bytes, row order, straight alpha. */
    public function toRgbaString(): string;

    /** @return list<int> GD truecolor ints (0xAARRGGBB, alpha 0 opaque .. 127 transparent) */
    public function clonePixels(): array;

    /** GD truecolor int at a pixel; transparent outside. */
    public function getPixel(int $x, int $y): int;

    public function setPixel(int $x, int $y, int $v): void;
}
