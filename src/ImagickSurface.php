<?php declare(strict_types=1);

namespace Charm\Canvas;

/**
 * A renderer whose pixels live in an Imagick image (8-bit RGBA, straight
 * alpha, transparent background); used when ext-imagick is loaded.
 *
 * Geometry arrives already flattened, in device space, exactly as for the PHP
 * Surface (strokes are outlined by Stroker), so everything here is "paint these
 * polygons / this image through a mask":
 *
 *  - every paint is first built as a layer (an Imagick image of the affected
 *    region) and then composited with the matching COMPOSITE_* operator;
 *  - ImagickDraw only draws filled polygons. IM6's own antialiasing is a
 *    distance ramp rather than area coverage, so polygons are drawn at k times
 *    the resolution (k = 1..8, fewer for big regions) and box-filtered down;
 *  - clips are full-canvas white images whose alpha is the coverage; a layer is
 *    clipped with COMPOSITE_DSTIN;
 *  - ImagickDraw's path commands are avoided: they cost ~0.5 ms per segment.
 */
final class ImagickSurface implements RendererInterface
{
    private const OPERATORS = [
        'source-over' => \Imagick::COMPOSITE_OVER,
        'source-in' => \Imagick::COMPOSITE_SRCIN,
        'source-out' => \Imagick::COMPOSITE_SRCOUT,
        'source-atop' => \Imagick::COMPOSITE_SRCATOP,
        'destination-over' => \Imagick::COMPOSITE_DSTOVER,
        'destination-in' => \Imagick::COMPOSITE_DSTIN,
        'destination-out' => \Imagick::COMPOSITE_DSTOUT,
        'destination-atop' => \Imagick::COMPOSITE_DSTATOP,
        'lighter' => \Imagick::COMPOSITE_PLUS,
        'copy' => \Imagick::COMPOSITE_SRC,
        'xor' => \Imagick::COMPOSITE_XOR,
        'multiply' => \Imagick::COMPOSITE_MULTIPLY,
        'screen' => \Imagick::COMPOSITE_SCREEN,
        'overlay' => \Imagick::COMPOSITE_OVERLAY,
        'darken' => \Imagick::COMPOSITE_DARKEN,
        'lighten' => \Imagick::COMPOSITE_LIGHTEN,
        'color-dodge' => \Imagick::COMPOSITE_COLORDODGE,
        'color-burn' => \Imagick::COMPOSITE_COLORBURN,
        'hard-light' => \Imagick::COMPOSITE_HARDLIGHT,
        'soft-light' => \Imagick::COMPOSITE_SOFTLIGHT,
        'difference' => \Imagick::COMPOSITE_DIFFERENCE,
        'exclusion' => \Imagick::COMPOSITE_EXCLUSION,
        'hue' => \Imagick::COMPOSITE_HUE,
        'saturation' => \Imagick::COMPOSITE_SATURATE,
        'color' => \Imagick::COMPOSITE_COLORIZE,
        'luminosity' => \Imagick::COMPOSITE_LUMINIZE,
    ];

    /** Supersampled polygon rasters stay below this many pixels. */
    private const SAMPLE_BUDGET = 1_500_000;

    private \Imagick $im;
    private \ImagickDraw $draw;
    private ?\Imagick $clip = null;
    /** @var list<?\Imagick> */
    private array $clipStack = [];

    /** @var \WeakMap<Surface,\Imagick>|null glyph masks already converted */
    private static ?\WeakMap $maskImages = null;

    public function __construct(public readonly int $width, public readonly int $height, ?\Imagick $im = null)
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Surface dimensions must be positive');
        }
        $this->im = $im ?? self::blank($width, $height);
        $this->draw = new \ImagickDraw();
    }

    // -----------------------------------------------------------------
    // Construction and export
    // -----------------------------------------------------------------

    /** @var array<string,\ImagickPixel> parsing a colour string costs more than drawing a small shape */
    private static array $pixels = [];

    private static function pixel(string $css): \ImagickPixel
    {
        if (\count(self::$pixels) > 512) {
            self::$pixels = [];
        }
        return self::$pixels[$css] ??= new \ImagickPixel($css);
    }

    private static function blank(int $w, int $h, string $color = 'rgba(0,0,0,0)'): \Imagick
    {
        $im = new \Imagick();
        $im->setOption('filter', 'point'); // distortImage(): interpolate instead of EWA
        $im->newImage($w, $h, self::pixel($color), 'png');
        $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
        return $im;
    }

    private static function raw(int $w, int $h, string $rgba): \Imagick
    {
        $im = new \Imagick();
        $im->setOption('filter', 'point');
        $im->setSize($w, $h);
        $im->setOption('depth', '8');
        $im->setFormat('rgba');
        $im->readImageBlob($rgba);
        $im->setFormat('png'); // the raw reader leaves the object's format as rgba
        $im->setImageFormat('png');
        return $im;
    }

    public static function fromRgbaString(int $width, int $height, string $rgba): self
    {
        $n = $width * $height * 4;
        $rgba = \strlen($rgba) === $n ? $rgba : str_pad(substr($rgba, 0, $n), $n, "\0");
        return new self($width, $height, self::raw($width, $height, $rgba));
    }

    public function image(): \Imagick
    {
        return $this->im;
    }

    public function clear(): void
    {
        $this->im = self::blank($this->width, $this->height);
    }

    public function toRgbaString(): string
    {
        // Like a browser's premultiplied bitmap: fully transparent pixels read
        // back as 0,0,0,0 whatever colour they last held.
        $c = self::blank($this->width, $this->height);
        $c->compositeImage($this->im, \Imagick::COMPOSITE_OVER, 0, 0);
        $c->setImageDepth(8);
        $c->setImageFormat('rgba');
        return $c->getImageBlob();
    }

    public function toPng(): string
    {
        $c = clone $this->im;
        $c->setImageDepth(8);
        $c->setImageFormat('png');
        $c->setOption('png:color-type', '6'); // always truecolor + alpha, never a palette
        return $c->getImageBlob();
    }

    public function toJpeg(int $quality): string
    {
        // JPEG has no alpha; browsers composite onto black.
        $c = clone $this->im;
        $c->setImageBackgroundColor(new \ImagickPixel('black'));
        $flat = $c->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $flat->setImageFormat('jpeg');
        $flat->setImageCompressionQuality(max(0, min(100, $quality)));
        return $flat->getImageBlob();
    }

    public function toImagick(): \Imagick
    {
        $c = clone $this->im;
        $c->setImageFormat('png');
        return $c;
    }

    public function toGd(): \GdImage
    {
        $gd = \imagecreatefromstring($this->toPng());
        if ($gd === false) {
            throw new \RuntimeException('Unable to convert to GD');
        }
        \imagealphablending($gd, false);
        \imagesavealpha($gd, true);
        return $gd;
    }

    public function clonePixels(): array
    {
        return Surface::fromRgbaString($this->width, $this->height, $this->toRgbaString())->clonePixels();
    }

    /** One pixel as a GD int. A single-pixel Imagick call: use bulk reads instead where possible. */
    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return 0x7F000000;
        }
        [$r, $g, $b, $a] = $this->im->exportImagePixels($x, $y, 1, 1, 'RGBA', \Imagick::PIXEL_CHAR);
        return ((127 - (int) round($a * 127 / 255)) << 24) | ($r << 16) | ($g << 8) | $b;
    }

    public function setPixel(int $x, int $y, int $v): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $a = (int) round((127 - ($v >> 24)) * 255 / 127);
        $this->im->importImagePixels($x, $y, 1, 1, 'RGBA', \Imagick::PIXEL_CHAR, [($v >> 16) & 255, ($v >> 8) & 255, $v & 255, $a]);
    }

    // -----------------------------------------------------------------
    // Clipping
    // -----------------------------------------------------------------

    public function pushClip(array $polygons, string $rule = Raster::NONZERO): void
    {
        $this->clipStack[] = $this->clip;
        $mask = $polygons === []
            ? self::blank($this->width, $this->height)
            : $this->polygonLayer($polygons, $rule, 0, 0, $this->width, $this->height, null);
        if ($this->clip !== null) {
            $mask->compositeImage($this->clip, \Imagick::COMPOSITE_DSTIN, 0, 0);
        }
        $this->clip = $mask;
    }

    public function popClip(): void
    {
        if ($this->clipStack !== []) {
            $this->clip = array_pop($this->clipStack);
        }
    }

    public function clipDepth(): int
    {
        return \count($this->clipStack);
    }

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------

    public function clearPolygons(array $polygons, string $rule = Raster::NONZERO): void
    {
        $this->fillPolygons($polygons, Paint::solid(1.0, 1.0, 1.0, 1.0), 1.0, $rule, 'destination-out');
    }

    public function fillPolygons(array $polygons, Paint $paint, float $alpha, string $rule = Raster::NONZERO, string $mode = 'source-over'): void
    {
        if ($polygons === [] || $paint->isEmpty()) {
            return;
        }
        $beyond = Compositing::reachesBeyondShape($mode);
        if (!$beyond && $alpha <= 0.0 && self::alphaIsOpaqueSafe($mode)) {
            return;
        }
        if ($beyond) {
            [$x0, $y0, $w, $h] = [0, 0, $this->width, $this->height];
        } else {
            $region = $this->bounds($polygons);
            if ($region === null) {
                return;
            }
            [$x0, $y0, $w, $h] = $region;
        }
        $color = null;
        if ($paint->isConstant()) {
            $c = $paint->constant();
            $color = [$c[0], $c[1], $c[2], $c[3] * $alpha];
        }
        $layer = $this->polygonLayer($polygons, $rule, $x0, $y0, $w, $h, $color);
        if ($color === null) {
            $layer = $this->tint($layer, $paint, $alpha, $x0, $y0, $w, $h);
        }
        $this->compose($layer, $x0, $y0, $w, $h, $mode);
    }

    public function fillRectDevice(float $x, float $y, float $w, float $h, Paint $paint, float $alpha, string $mode = 'source-over', bool $useClip = true): void
    {
        if ($w < 0.0) {
            $x += $w;
            $w = -$w;
        }
        if ($h < 0.0) {
            $y += $h;
            $h = -$h;
        }
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }
        if (Compositing::reachesBeyondShape($mode)) {
            $this->fillPolygons([[$x, $y, $x + $w, $y, $x + $w, $y + $h, $x, $y + $h]], $paint, $alpha, Raster::NONZERO, $mode);
            return;
        }
        $x0 = max(0, (int) floor($x));
        $y0 = max(0, (int) floor($y));
        $x1 = min($this->width, (int) ceil($x + $w));
        $y1 = min($this->height, (int) ceil($y + $h));
        if ($x1 <= $x0 || $y1 <= $y0) {
            return;
        }
        // A linear gradient is painted by Imagick over a white coverage layer;
        // other non-constant paints are sampled pixel by pixel below.
        $viaTint = !$paint->isConstant() && $paint->linearStrip(2) !== null;
        $rowPaint = $viaTint ? Paint::solid(1.0, 1.0, 1.0, 1.0) : $paint;
        $rowAlpha = $viaTint ? 1.0 : $alpha;
        $constant = $rowPaint->isConstant() ? $rowPaint->constant() : null;
        $cx = [];
        for ($i = $x0; $i < $x1; $i++) {
            $cx[$i] = min($x + $w, (float) ($i + 1)) - max($x, (float) $i);
        }
        $pix = [];
        $rows = [];
        $out = [];
        for ($j = $y0; $j < $y1; $j++) {
            $cy = min($y + $h, (float) ($j + 1)) - max($y, (float) $j);
            $key = (string) $cy;
            if ($constant !== null && isset($rows[$key])) {
                $out[] = $rows[$key];
                continue;
            }
            $row = '';
            foreach ($cx as $i => $cxv) {
                $src = $constant ?? $rowPaint->colorAt($i + 0.5, $j + 0.5);
                $a = (int) round(255 * min(1.0, max(0.0, $src[3] * $rowAlpha * $cxv * $cy)));
                if ($constant !== null) {
                    $row .= $pix[$a] ??= \chr(self::byte($src[0])) . \chr(self::byte($src[1])) . \chr(self::byte($src[2])) . \chr($a);
                } else {
                    $row .= \chr(self::byte($src[0])) . \chr(self::byte($src[1])) . \chr(self::byte($src[2])) . \chr($a);
                }
            }
            $out[] = $rows[$key] = $row;
        }
        $layer = self::raw($x1 - $x0, $y1 - $y0, implode('', $out));
        if ($viaTint) {
            $layer = $this->tint($layer, $paint, $alpha, $x0, $y0, $x1 - $x0, $y1 - $y0);
        }
        $this->compose($layer, $x0, $y0, $x1 - $x0, $y1 - $y0, $mode);
    }

    public function clearRectDevice(float $x, float $y, float $w, float $h): void
    {
        // Whole pixels, nearest edge, limited by the clip.
        $xa = (int) floor($x + 0.5);
        $ya = (int) floor($y + 0.5);
        $xb = (int) floor($x + $w + 0.5);
        $yb = (int) floor($y + $h + 0.5);
        [$xa, $xb] = [max(0, min($xa, $xb)), min($this->width, max($xa, $xb))];
        [$ya, $yb] = [max(0, min($ya, $yb)), min($this->height, max($ya, $yb))];
        if ($xb <= $xa || $yb <= $ya) {
            return;
        }
        $layer = self::blank($xb - $xa, $yb - $ya, 'white');
        $this->compose($layer, $xa, $ya, $xb - $xa, $yb - $ya, 'destination-out');
    }

    // -----------------------------------------------------------------
    // Images and glyphs
    // -----------------------------------------------------------------

    public function blitAffine(RendererInterface $src, Matrix $m, float $alpha, bool $smooth, string $mode = 'source-over', ?array $subrect = null, bool $snap = false): void
    {
        if ($m->inverse() === null || $alpha <= 0.0 || $src->width <= 0 || $src->height <= 0) {
            return;
        }
        $sub = $subrect ?? [0.0, 0.0, (float) $src->width, (float) $src->height];
        // Integer pixel window around the requested source rectangle.
        $rx0 = max(0, (int) floor($sub[0]));
        $ry0 = max(0, (int) floor($sub[1]));
        $rx1 = min($src->width, (int) ceil($sub[2]));
        $ry1 = min($src->height, (int) ceil($sub[3]));
        if ($rx1 <= $rx0 || $ry1 <= $ry0) {
            return;
        }
        $srcIm = $src instanceof self ? $src->im : self::raw($src->width, $src->height, $src->toRgbaString());
        $window = $srcIm->getImageRegion($rx1 - $rx0, $ry1 - $ry0, $rx0, $ry0);

        $region = $this->deviceRegion($m, [$sub[0], $sub[1], $sub[2], $sub[3]], Compositing::reachesBeyondShape($mode));
        if ($region === null) {
            return;
        }
        [$x0, $y0, $w, $h] = $region;
        $layer = $this->transformed($window, $m->translated((float) $rx0, (float) $ry0), $x0, $y0, $w, $h, $smooth, \Imagick::VIRTUALPIXELMETHOD_EDGE);
        // Edge pixels are replicated outward while sampling; the drawn
        // rectangle's outline cuts the image off. Axis-aligned: whole pixels
        // between the rounded edges (half rounds up, as in browsers);
        // rotated: antialiased.
        if (abs($m->b) < 1e-9 && abs($m->c) < 1e-9) {
            $p = $m->mapPoint($sub[0], $sub[1]);
            $q = $m->mapPoint($sub[2], $sub[3]);
            $ax = max($x0, (int) floor(min($p['x'], $q['x']) + 0.5));
            $ay = max($y0, (int) floor(min($p['y'], $q['y']) + 0.5));
            $bx = min($x0 + $w, (int) floor(max($p['x'], $q['x']) + 0.5));
            $by = min($y0 + $h, (int) floor(max($p['y'], $q['y']) + 0.5));
            if ($bx <= $ax || $by <= $ay) {
                return;
            }
            $cut = self::blank($w, $h);
            $cut->compositeImage(self::blank($bx - $ax, $by - $ay, 'white'), \Imagick::COMPOSITE_COPY, $ax - $x0, $ay - $y0);
        } else {
            $quad = [];
            foreach ([[$sub[0], $sub[1]], [$sub[2], $sub[1]], [$sub[2], $sub[3]], [$sub[0], $sub[3]]] as [$u, $v]) {
                $p = $m->mapPoint($u, $v);
                $quad[] = $p['x'];
                $quad[] = $p['y'];
            }
            $cut = $this->polygonLayer([$quad], Raster::NONZERO, $x0, $y0, $w, $h, null);
        }
        $layer->compositeImage($cut, \Imagick::COMPOSITE_DSTIN, 0, 0);
        if ($alpha < 1.0) {
            $layer->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $alpha, \Imagick::CHANNEL_ALPHA);
        }
        $this->compose($layer, $x0, $y0, $w, $h, $mode);
    }

    public function blitMask(Surface $mask, Matrix $m, Paint $paint, float $alpha, string $mode = 'source-over'): void
    {
        if ($m->inverse() === null || $alpha <= 0.0 || $paint->isEmpty()) {
            return;
        }
        $region = $this->deviceRegion($m, [0.0, 0.0, (float) $mask->width, (float) $mask->height], Compositing::reachesBeyondShape($mode));
        if ($region === null) {
            return;
        }
        [$x0, $y0, $w, $h] = $region;
        self::$maskImages ??= new \WeakMap();
        $img = self::$maskImages[$mask] ??= self::raw($mask->width, $mask->height, $mask->toRgbaString());
        $cov = $this->transformed($img->getImageRegion($mask->width, $mask->height, 0, 0), $m, $x0, $y0, $w, $h, true);
        $this->compose($this->tint($cov, $paint, $alpha, $x0, $y0, $w, $h), $x0, $y0, $w, $h, $mode);
    }

    public function readRect(int $x, int $y, int $width, int $height): self
    {
        $out = new self($width, $height);
        $x0 = max(0, $x);
        $y0 = max(0, $y);
        $x1 = min($this->width, $x + $width);
        $y1 = min($this->height, $y + $height);
        if ($x1 > $x0 && $y1 > $y0) {
            $out->im->compositeImage($this->im->getImageRegion($x1 - $x0, $y1 - $y0, $x0, $y0), \Imagick::COMPOSITE_COPY, $x0 - $x, $y0 - $y);
        }
        return $out;
    }

    public function writeSurface(RendererInterface $src, int $dx, int $dy): void
    {
        $im = $src instanceof self ? $src->im : self::raw($src->width, $src->height, $src->toRgbaString());
        $this->im->compositeImage($im, \Imagick::COMPOSITE_COPY, $dx, $dy);
    }

    // -----------------------------------------------------------------
    // Layers
    // -----------------------------------------------------------------

    /**
     * Draw a transformed copy of an image into a w x h layer positioned at
     * (x0, y0) of this canvas. Control points of an Imagick distortion use
     * corner-origin coordinates, the same as the canvas model.
     */
    private function transformed(\Imagick $img, Matrix $m, int $x0, int $y0, int $w, int $h, bool $smooth, int $virtual = \Imagick::VIRTUALPIXELMETHOD_TRANSPARENT): \Imagick
    {
        $img->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
        $img->setImageVirtualPixelMethod($virtual);
        // ImageMagick 6 builds of ext-imagick call it NEARESTNEIGHBOR, ImageMagick 7 builds NEAREST_PIXEL (another value)
        $nearest = \defined('Imagick::INTERPOLATE_NEARESTNEIGHBOR') ? \Imagick::INTERPOLATE_NEARESTNEIGHBOR : \constant('Imagick::INTERPOLATE_NEAREST_PIXEL');
        $img->setImageInterpolateMethod($smooth ? \Imagick::INTERPOLATE_BILINEAR : $nearest);
        $img->setImageArtifact('distort:viewport', "{$w}x{$h}+{$x0}+{$y0}");
        // Three control points (source -> device) define the affine map.
        $tx = $m->e;
        $ty = $m->f;
        $img->distortImage(\Imagick::DISTORTION_AFFINE, [
            0, 0, $tx, $ty,
            1, 0, $tx + $m->a, $ty + $m->b,
            0, 1, $tx + $m->c, $ty + $m->d,
        ], false);
        $img->setImagePage($w, $h, 0, 0);
        return $img;
    }

    /** Pixel box (clamped) that the rectangle [sx0,sy0,sx1,sy1] occupies after m. */
    private function deviceRegion(Matrix $m, array $r, bool $whole): ?array
    {
        if ($whole) {
            return [0, 0, $this->width, $this->height];
        }
        $xs = [];
        $ys = [];
        foreach ([[$r[0], $r[1]], [$r[2], $r[1]], [$r[0], $r[3]], [$r[2], $r[3]]] as [$u, $v]) {
            $p = $m->mapPoint($u, $v);
            $xs[] = $p['x'];
            $ys[] = $p['y'];
        }
        return $this->box(min($xs), min($ys), max($xs), max($ys));
    }

    private function box(float $minX, float $minY, float $maxX, float $maxY): ?array
    {
        if (!is_finite($minX) || !is_finite($maxX) || !is_finite($minY) || !is_finite($maxY)) {
            return null;
        }
        $x0 = max(0, (int) floor($minX));
        $y0 = max(0, (int) floor($minY));
        $x1 = min($this->width, (int) ceil($maxX));
        $y1 = min($this->height, (int) ceil($maxY));
        return $x1 > $x0 && $y1 > $y0 ? [$x0, $y0, $x1 - $x0, $y1 - $y0] : null;
    }

    private function bounds(array $polygons): ?array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($polygons as $p) {
            for ($i = 0, $n = \count($p); $i < $n; $i += 2) {
                $minX = min($minX, $p[$i]);
                $maxX = max($maxX, $p[$i]);
                $minY = min($minY, $p[$i + 1]);
                $maxY = max($maxY, $p[$i + 1]);
            }
        }
        return $this->box($minX, $minY, $maxX, $maxY);
    }

    /**
     * A w x h layer holding the polygons: coloured $color (r, g, b 0..1 and
     * alpha 0..1) or, with null, white; its alpha is the coverage.
     *
     * Holes and several subpaths: polygons of one winding are drawn together
     * (their union); opposite windings are subtracted, and under even-odd each
     * subpath is XORed in. That equals the fill rule for subpaths that nest or
     * are disjoint, which is what paths made of rings and holes are.
     *
     * @param list<list<float>> $polygons
     * @param array{0:float,1:float,2:float,3:float}|null $color
     */
    private function polygonLayer(array $polygons, string $rule, int $x0, int $y0, int $w, int $h, ?array $color): \Imagick
    {
        $area = $w * $h;
        $k = $area * 64 <= self::SAMPLE_BUDGET ? 8 : ($area * 16 <= self::SAMPLE_BUDGET ? 4 : ($area * 4 <= self::SAMPLE_BUDGET ? 2 : 1));
        $groups = [];
        $combine = 'union';
        if (\count($polygons) > 1) {
            if ($rule === Raster::EVENODD) {
                $combine = 'xor';
                foreach ($polygons as $p) {
                    $groups[] = [$p];
                }
            } else {
                $pos = [];
                $neg = [];
                foreach ($polygons as $p) {
                    if (self::signedArea($p) >= 0.0) {
                        $pos[] = $p;
                    } else {
                        $neg[] = $p;
                    }
                }
                if ($pos !== [] && $neg !== []) {
                    $combine = 'diff';
                    $groups = [$pos, $neg];
                }
            }
        }
        if ($groups === []) {
            $groups = [$polygons];
        }

        $paintNow = $combine === 'union' && $color !== null && $k <= 1 ? $color : null;
        $acc = null;
        foreach ($groups as $g) {
            $img = $this->drawPolygons($g, $rule, $x0, $y0, $w, $h, $k, $paintNow);
            if ($acc === null) {
                $acc = $img;
            } else { // xor (even-odd) and diff (|positive - negative|) are the same operation
                $acc = self::exclusive($acc, $img);
            }
        }
        if ($k > 1) {
            $acc->resizeImage($w, $h, \Imagick::FILTER_BOX, 1);
            // IM6 draws a little fat (measured +1.4% coverage at k=8, more on
            // thin shapes); a black point of 0.56/k on the alpha channel cancels it.
            $acc->levelImage(0.56 / $k * 65535, 1.0, 65535, \Imagick::CHANNEL_ALPHA);
        }
        if ($paintNow === null && $color !== null) {
            $col = self::blank($w, $h, self::css($color));
            $col->compositeImage($acc, \Imagick::COMPOSITE_DSTIN, 0, 0);
            return $col;
        }
        return $acc;
    }

    /** (a OUT b) + (b OUT a): coverage a + b - 2ab. */
    private static function exclusive(\Imagick $a, \Imagick $b): \Imagick
    {
        $ab = clone $a;
        $ab->compositeImage($b, \Imagick::COMPOSITE_DSTOUT, 0, 0);
        $b->compositeImage($a, \Imagick::COMPOSITE_DSTOUT, 0, 0);
        $ab->compositeImage($b, \Imagick::COMPOSITE_PLUS, 0, 0);
        return $ab;
    }

    private function drawPolygons(array $polygons, string $rule, int $x0, int $y0, int $w, int $h, int $k, ?array $color): \Imagick
    {
        $img = self::blank($w * $k, $h * $k);
        $d = $this->draw;
        $d->clear();
        $d->setFillColor(self::pixel($color === null ? 'white' : self::css($color)));
        $d->setStrokeAntialias(true);
        $d->setFillRule($rule === Raster::EVENODD ? \Imagick::FILLRULE_EVENODD : \Imagick::FILLRULE_NONZERO);
        foreach ($polygons as $p) {
            $pts = [];
            for ($i = 0, $n = \count($p); $i < $n; $i += 2) {
                $pts[] = ['x' => ($p[$i] - $x0) * $k - 0.5, 'y' => ($p[$i + 1] - $y0) * $k - 0.5];
            }
            if (\count($pts) >= 3) {
                $d->polygon($pts);
            }
        }
        $img->drawImage($d);
        return $img;
    }

    /** Give a white coverage layer a non-constant paint (gradient, pattern) or a constant one with $alpha. */
    private function tint(\Imagick $cov, Paint $paint, float $alpha, int $x0, int $y0, int $w, int $h): \Imagick
    {
        if ($paint->isConstant()) {
            $c = $paint->constant();
            $col = self::blank($w, $h, self::css([$c[0], $c[1], $c[2], $c[3] * $alpha]));
        } else {
            if ($alpha < 1.0) {
                $cov->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $alpha, \Imagick::CHANNEL_ALPHA);
            }
            $strip = $paint->linearStrip(512);
            $fwd = $strip === null ? null : $strip['toStrip']->inverse();
            if ($strip !== null && $fwd !== null && is_finite($fwd->a + $fwd->b + $fwd->c + $fwd->d + $fwd->e + $fwd->f)) {
                $col = $this->transformed(self::raw(512, 1, $strip['rgba']), $fwd, $x0, $y0, $w, $h, true, \Imagick::VIRTUALPIXELMETHOD_EDGE);
                $col->compositeImage($cov, \Imagick::COMPOSITE_DSTIN, 0, 0);
                return $col;
            }
            $rows = [];
            for ($j = 0; $j < $h; $j++) {
                $row = [];
                for ($i = 0; $i < $w; $i++) {
                    $c = $paint->colorAt($x0 + $i + 0.5, $y0 + $j + 0.5);
                    $row[] = self::byte($c[0]);
                    $row[] = self::byte($c[1]);
                    $row[] = self::byte($c[2]);
                    $row[] = self::byte($c[3]);
                }
                $rows[] = pack('C*', ...$row);
            }
            $col = self::raw($w, $h, implode('', $rows));
        }
        $col->compositeImage($cov, \Imagick::COMPOSITE_DSTIN, 0, 0);
        return $col;
    }

    /** Clip a finished layer and composite it onto the canvas. */
    private function compose(\Imagick $layer, int $x0, int $y0, int $w, int $h, string $mode): void
    {
        $op = self::OPERATORS[$mode] ?? \Imagick::COMPOSITE_OVER;
        $clip = null;
        if ($this->clip !== null) {
            $clip = $w === $this->width && $h === $this->height ? $this->clip : $this->clip->getImageRegion($w, $h, $x0, $y0);
        }
        if ($clip === null) {
            $this->im->compositeImage($layer, $op, $x0, $y0);
            return;
        }
        if (!Compositing::reachesBeyondShape($mode)) {
            $layer->compositeImage($clip, \Imagick::COMPOSITE_DSTIN, 0, 0);
            $this->im->compositeImage($layer, $op, $x0, $y0);
            return;
        }
        // The operation reaches the whole canvas, but only the clipped part may
        // change: result = original * (1 - clip) + composited * clip.
        $done = clone $this->im;
        $done->compositeImage($layer, $op, $x0, $y0);
        $done->compositeImage($clip, \Imagick::COMPOSITE_DSTIN, 0, 0);
        $this->im->compositeImage($clip, \Imagick::COMPOSITE_DSTOUT, 0, 0);
        $this->im->compositeImage($done, \Imagick::COMPOSITE_PLUS, 0, 0);
    }

    // -----------------------------------------------------------------

    private static function signedArea(array $p): float
    {
        $s = 0.0;
        $n = \count($p);
        for ($i = 0; $i < $n; $i += 2) {
            $j = ($i + 2) % $n;
            $s += $p[$i] * $p[$j + 1] - $p[$j] * $p[$i + 1];
        }
        return $s;
    }

    private static function byte(float $v): int
    {
        return $v <= 0.0 ? 0 : ($v >= 1.0 ? 255 : (int) ($v * 255 + 0.5));
    }

    /** @param array{0:float,1:float,2:float,3:float} $c */
    private static function css(array $c): string
    {
        return \sprintf('rgba(%d,%d,%d,%.6f)', self::byte($c[0]), self::byte($c[1]), self::byte($c[2]), max(0.0, min(1.0, $c[3])));
    }

    private static function alphaIsOpaqueSafe(string $mode): bool
    {
        return !\in_array($mode, ['destination-out', 'source-in', 'destination-atop', 'source-atop', 'copy', 'destination-in', 'xor'], true);
    }
}
