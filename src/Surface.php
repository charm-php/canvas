<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * An RGBA pixel buffer with the rasterisation semantics of HTML canvas.
 *
 * This is deliberately free of any drawing-library dependency: pixels are
 * allocated, covered, blended and read back here, so a backend is only ever a
 * codec (PNG/JPEG/GD/Imagick) or, one day, an accelerated implementation of
 * the same surface contract.
 */
final class Surface implements RendererInterface
{
    /**
     * Straight (non-premultiplied) GD truecolor, one int per pixel, row after
     * row: 0xAARRGGBB with alpha 0 = opaque .. 127 = transparent (7 bits), so
     * nothing is converted when pixels cross into or out of GD.
     *
     * Address single pixels with idx()/getPixel()/setPixel()/blendPixel().
     * Only these touch the array directly: the row loops of fillPolygons'
     * callback (overRun), fillRectDevice and clearRectDevice, toRgbaString,
     * fromRgbaString, readRect/writeSurface (bulk slices) and the constructor
     * helpers; Canvas::toDots reads a copy from clonePixels().
     *
     * @var list<int>
     */
    private array $px;

    /** @var list<?string> clip stack; each mask is width*height bytes, 0..255 */
    private array $clipStack = [];

    /** @var ?string current clip coverage mask, null when unclipped */
    private ?string $clip = null;

    /** How many vertical samples per pixel row when rasterising. */
    public int $verticalSamples = 8;

    public function __construct(public readonly int $width, public readonly int $height)
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Surface dimensions must be positive');
        }
        $this->px = array_fill(0, $width * $height, self::TRANSPARENT);
    }

    public function clear(): void
    {
        $this->px = array_fill(0, $this->width * $this->height, self::TRANSPARENT);
    }

    /** @return list<int> the pixels as GD truecolor ints, one entry per pixel */
    public function clonePixels(): array
    {
        return $this->px;
    }

    /** @param array<int,int> $px GD truecolor ints, row order */
    public function setPixels(array $px): void
    {
        $n = $this->width * $this->height;
        $px = array_values($px);
        $c = count($px);
        if ($c > $n) {
            $px = array_slice($px, 0, $n);
        } elseif ($c < $n) {
            $px = array_merge($px, array_fill(0, $n - $c, self::TRANSPARENT));
        }
        $this->px = $px;
    }

    /** GD truecolor value of a fully transparent pixel. */
    private const TRANSPARENT = 0x7F000000;

    /** @var ?array{0:list<int>,1:list<int>,2:list<float>} */
    private static ?array $tables = null;

    /**
     * Alpha conversion tables: [0] 7-bit GD alpha -> 8-bit alpha, [1] 8-bit
     * alpha -> GD alpha already shifted into the top byte, [2] GD alpha ->
     * opacity as a float 0..1.
     *
     * @return array{0:list<int>,1:list<int>,2:list<float>}
     */
    private static function tables(): array
    {
        if (self::$tables === null) {
            $a8 = [];
            $a7 = [];
            $op = [];
            for ($i = 0; $i < 128; $i++) {
                $a8[$i] = (int) round((127 - $i) * 255 / 127);
                $op[$i] = (127 - $i) / 127;
            }
            for ($i = 0; $i < 256; $i++) {
                $a7[$i] = (127 - (int) round($i * 127 / 255)) << 24;
            }
            self::$tables = [$a8, $a7, $op];
        }
        return self::$tables;
    }

    // -----------------------------------------------------------------
    // Clipping
    // -----------------------------------------------------------------

    /**
     * Intersect the current clip with a polygon set.  Clips nest the way the
     * specification says they do: each new clip can only remove area.
     *
     * @param list<list<float>> $polygons
     */
    public function pushClip(array $polygons, string $rule = Raster::NONZERO): void
    {
        $this->clipStack[] = $this->clip;
        $mask = $this->coverageMask($polygons, $rule);
        if ($this->clip === null) {
            $this->clip = $mask;
            return;
        }
        // Combine only where the new mask is neither 0 nor 255: whole runs of
        // those are skipped or zeroed in one step.
        $existing = $this->clip;
        $len = strlen($existing);
        $out = '';
        for ($i = 0; $i < $len;) {
            $n = strspn($mask, "\xFF", $i);
            if ($n > 0) {
                $out .= substr($existing, $i, $n);
                $i += $n;
                continue;
            }
            $n = strspn($mask, "\0", $i);
            if ($n > 0) {
                $out .= str_repeat("\0", $n);
                $i += $n;
                continue;
            }
            $out .= \chr(intdiv(\ord($existing[$i]) * \ord($mask[$i]), 255));
            $i++;
        }
        $existing = $out;
        $this->clip = $existing;
    }

    public function popClip(): void
    {
        if ($this->clipStack === []) {
            return;
        }
        $this->clip = array_pop($this->clipStack);
    }

    public function hasClip(): bool
    {
        return $this->clip !== null;
    }

    public function clipDepth(): int
    {
        return count($this->clipStack);
    }

    /**
     * Clear the area covered by polygons, honouring their antialiasing but
     * nothing else: clearRect ignores alpha, compositing and clipping.
     *
     * @param list<list<float>> $polygons
     */
    public function clearPolygons(array $polygons, string $rule = Raster::NONZERO): void
    {
        $w = $this->width;
        $h = $this->height;
        Raster::walk($polygons, $rule, $w, $h, $this->verticalSamples,
            function (int $y, array $cov, int $minX, int $maxX, array $runs) use (&$w): void {
                if ($y < 0 || $y >= $this->height) {
                    return;
                }
                $base = $y * $w;
                $clip = $this->clip;
                foreach ($runs as [$from, $to]) {
                    for ($x = max(0, $from); $x <= $to && $x < $w; $x++) {
                        $cov[$x] = 1.0;
                    }
                }
                foreach ($cov as $x => $coverage) {
                    if ($x < 0 || $x >= $w || $coverage <= 0.0) {
                        continue;
                    }
                    if ($clip !== null) {
                        // Clearing is limited to the clipped part of a pixel.
                        $coverage *= \ord($clip[$base + $x]) / 255;
                    }
                    $this->fadeAlpha($y, $x, min(1.0, $coverage));
                }
            });
    }

    /**
     * Coverage mask of a polygon set, 0..255 per pixel.
     *
     * @param list<list<float>> $polygons
     */
    public function coverageMask(array $polygons, string $rule): string
    {
        $w = $this->width;
        $h = $this->height;
        $bytes = str_repeat("\0", $w * $h);
        // A clip mask is built once and reused for every later draw, so it is
        // worth sampling it more finely than a single fill.
        Raster::walk($polygons, $rule, $w, $h, max($this->verticalSamples, 16),
            function (int $y, array $cov, int $minX, int $maxX, array $runs) use (&$bytes, $w, $h): void {
                if ($y < 0 || $y >= $h) {
                    return;
                }
                $base = $y * $w;
                foreach ($runs as [$from, $to]) {
                    $from = max(0, $from);
                    $to = min($w - 1, $to);
                    if ($from <= $to) {
                        $bytes = substr_replace($bytes, str_repeat("\xFF", $to - $from + 1), $base + $from, $to - $from + 1);
                    }
                }
                foreach ($cov as $x => $c) {
                    if ($x < 0 || $x >= $w || $c <= 0.0) {
                        continue;
                    }
                    $v = $c >= 1.0 ? 255 : (int) ($c * 255 + 0.5);
                    $i = $base + $x;
                    if ($v > \ord($bytes[$i])) {
                        $bytes[$i] = \chr($v);
                    }
                }
            });
        return $bytes;
    }

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------

    /**
     * Fill polygons with a paint.
     *
     * @param list<list<float>> $polygons device space
     */
    public function fillPolygons(
        array $polygons,
        Paint $paint,
        float $alpha,
        string $rule = Raster::NONZERO,
        string $mode = 'source-over',
    ): void {
        if ($polygons === [] || $paint->isEmpty()) {
            return;
        }
        if (Compositing::reachesBeyondShape($mode)) {
            $this->fillWholeCanvas($polygons, $paint, $alpha, $rule, $mode);
            return;
        }
        if ($alpha <= 0.0 && self::alphaIsOpaqueSafe($mode)) {
            return;
        }

        $w = $this->width;
        $h = $this->height;
        $clip = $this->clip;
        $constant = $paint->isConstant() ? $paint->constant() : null;
        $rgb = $constant === null ? null
            : (self::byte($constant[0]) << 16) | (self::byte($constant[1]) << 8) | self::byte($constant[2]);

        Raster::walk($polygons, $rule, $w, $h, $this->verticalSamples,
            function (int $y, array $cov, int $minX, int $maxX, array $runs) use (
                $w, $h, $clip, $constant, $rgb, $alpha, $mode, $paint
            ): void {
                if ($y < 0 || $y >= $h) {
                    return;
                }
                $base = $y * $w;
                $rowY = $y + 0.5;

                $over = $mode === 'source-over';
                foreach ($runs as [$from, $to]) {
                    // A run is fully covered: every column has coverage 1.
                    $from = max(0, $from);
                    $to = min($w - 1, $to);
                    if ($over && $constant !== null && $clip === null) {
                        $this->overRun($y, $from, $to, $constant[0], $constant[1], $constant[2], min(1.0, $constant[3] * $alpha), $rgb);
                    } else {
                        for ($x = $from; $x <= $to; $x++) {
                            $cov[$x] = 1.0;
                        }
                    }
                }
                foreach ($cov as $x => $coverage) {
                    if ($x < 0 || $x >= $w || $coverage <= 0.0) {
                        continue;
                    }
                    if ($clip !== null) {
                        $cm = \ord($clip[$base + $x]);
                        if ($cm === 0) {
                            continue;
                        }
                        if ($cm < 255) {
                            $coverage *= $cm / 255;
                        }
                    }
                    $src = $constant ?? $paint->colorAt($x + 0.5, $rowY);
                    $a = $src[3] * $alpha;
                    if ($coverage < 1.0) {
                        $a *= $coverage;
                    }
                    if ($over) {
                        if ($a > 0.0) {
                            $this->blendPixel($x, $y, $rgb ?? (self::byte($src[0]) << 16) | (self::byte($src[1]) << 8) | self::byte($src[2]),
                                (int) (min(1.0, $a) * 127 + 0.5));
                        }
                        continue;
                    }
                    $this->blendAt($y, $x, $src[0], $src[1], $src[2], min(1.0, $a), $mode);
                }
            });
    }

    /**
     * Composite over the entire canvas.
     *
     * Modes like source-in and copy are defined over the whole destination, not
     * just over the shape: a pixel the shape never touches still has a result,
     * computed with a fully transparent source.  Gathering the shape's coverage
     * first lets one loop cover every pixel exactly once.
     *
     * @param list<list<float>> $polygons
     */
    private function fillWholeCanvas(array $polygons, Paint $paint, float $alpha, string $rule, string $mode): void
    {
        $w = $this->width;
        $h = $this->height;
        $rows = [];
        Raster::walk($polygons, $rule, $w, $h, $this->verticalSamples,
            function (int $y, array $cov, int $minX, int $maxX, array $runs) use (&$rows): void {
                if ($y >= 0 && $y < $this->height) {
                    foreach ($runs as [$from, $to]) {
                        for ($x = max(0, $from); $x <= $to; $x++) {
                            $cov[$x] = 1.0;
                        }
                    }
                    $rows[$y] = $cov;
                }
            });

        $clip = $this->clip;
        for ($y = 0; $y < $h; $y++) {
            $cov = $rows[$y] ?? [];
            $base = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $idx = $base + $x;
                $coverage = $cov[$x] ?? 0.0;
                if ($clip !== null) {
                    $coverage *= \ord($clip[$idx]) / 255;
                }
                $a = $coverage * $alpha;
                if ($a <= 0.0) {
                    $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                    continue;
                }
                $src = $paint->isConstant()
                    ? $paint->constant()
                    : $paint->colorAt($x + 0.5, $y + 0.5);
                $this->blendAt($y, $x, $src[0], $src[1], $src[2], min(1.0, $a * $src[3]), $mode);
            }
        }
    }

    /**
     * Paint an axis-aligned device rectangle.  Used for fillRect()/clearRect()
     * so integer geometry stays pixel-exact.
     */
    public function fillRectDevice(
        float $x,
        float $y,
        float $w,
        float $h,
        Paint $paint,
        float $alpha,
        string $mode = 'source-over',
        bool $useClip = true,
    ): void {
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
        $x0 = (int) floor($x);
        $x1 = (int) ceil($x + $w) - 1;
        $y0 = (int) floor($y);
        $y1 = (int) ceil($y + $h) - 1;
        if ($x1 < 0 || $y1 < 0 || $x0 > $this->width - 1 || $y0 > $this->height - 1) {
            return;
        }
        $clip = $useClip ? $this->clip : null;
        $constant = $paint->isConstant() ? $paint->constant() : null;
        $over = $mode === 'source-over';
        $crgb = $constant === null ? null
            : (self::byte($constant[0]) << 16) | (self::byte($constant[1]) << 8) | self::byte($constant[2]);
        $px = &$this->px;
        $qa = max(0, $x0);
        $qb = min($this->width - 1, $x1);
        $cxs = [];
        for ($qx = $qa; $qx <= $qb; $qx++) {
            $cxs[$qx] = min($x + $w, (float) ($qx + 1)) - max($x, (float) $qx);
        }
        for ($py = max(0, $y0); $py <= min($this->height - 1, $y1); $py++) {
            $cy = min($y + $h, (float) ($py + 1)) - max($y, (float) $py);
            if ($cy <= 0.0) {
                continue;
            }
            $base = $py * $this->width;
            $rowY = $py + 0.5;
            for ($qx = $qa, $idx = $base + $qa; $qx <= $qb; $qx++, $idx++) {
                $cx = $cxs[$qx];
                if ($cx <= 0.0) {
                    continue;
                }
                $src = $constant ?? $paint->colorAt($qx + 0.5, $rowY);
                $a = min(1.0, $src[3] * $alpha * $cx * $cy);
                if ($a <= 0.0 && $over) {
                    continue;
                }
                if ($clip !== null) {
                    $cm = \ord($clip[$idx]);
                    if ($cm === 0) {
                        continue;
                    }
                    if ($cm < 255) {
                        $a = min(1.0, $a * $cm / 255);
                    }
                }
                if ($over) {
                    if ($a < 1.0) {
                        $px[$idx] = self::overPixel($px[$idx], $src[0], $src[1], $src[2], $a);
                        continue;
                    }
                    if ($crgb !== null) {
                        $px[$idx] = $crgb;
                        continue;
                    }
                    $r = $src[0];
                    $g = $src[1];
                    $b = $src[2];
                    $px[$idx] = (($r <= 0.0 ? 0 : ($r >= 1.0 ? 255 : (int) ($r * 255 + 0.5))) << 16)
                        | (($g <= 0.0 ? 0 : ($g >= 1.0 ? 255 : (int) ($g * 255 + 0.5))) << 8)
                        | ($b <= 0.0 ? 0 : ($b >= 1.0 ? 255 : (int) ($b * 255 + 0.5)));
                    continue;
                }
                $this->blendAt($py, $qx, $src[0], $src[1], $src[2], $a, $mode);
            }
        }
    }

    /** Clear to transparent black, honouring no other drawing state. */
    public function clearRectDevice(float $x, float $y, float $w, float $h): void
    {
        // A browser clears whole pixels: the device rectangle is rounded to
        // the nearest pixel edge and no antialiasing is applied, and the
        // current clipping region limits how far the clear reaches.
        $x0 = (int) floor($x + 0.5);
        $y0 = (int) floor($y + 0.5);
        $x1 = (int) floor($x + $w + 0.5);
        $y1 = (int) floor($y + $h + 0.5);
        if ($x1 < $x0) {
            $t = $x0;
            $x0 = $x1;
            $x1 = $t;
        }
        if ($y1 < $y0) {
            $t = $y0;
            $y0 = $y1;
            $y1 = $t;
        }
        $clip = $this->clip;
        $px = &$this->px;
        $xa = max(0, $x0);
        $xb = min($this->width, $x1);
        for ($py = max(0, $y0); $py < min($this->height, $y1); $py++) {
            $base = $py * $this->width;
            if ($clip === null) {
                for ($i = $base + $xa, $end = $base + $xb; $i < $end; $i++) {
                    $px[$i] = self::TRANSPARENT;
                }
                continue;
            }
            for ($i = $base + $xa, $end = $base + $xb; $i < $end; $i++) {
                $cm = \ord($clip[$i]);
                if ($cm === 255) {
                    $px[$i] = self::TRANSPARENT;
                } elseif ($cm !== 0) {
                    $this->fadeAlpha($py, $i - $base, $cm / 255);
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Pixel access
    // -----------------------------------------------------------------

    /** Remove a fraction of a pixel's alpha, leaving its colour as it was. */
    private function fadeAlpha(int $y, int $x, float $fraction): void
    {
        if ($fraction >= 1.0) {
            $this->setPixel($x, $y, self::TRANSPARENT);
            return;
        }
        $p = $this->getPixel($x, $y);
        $o = (int) round((127 - ($p >> 24)) * (1 - $fraction));
        $this->setPixel($x, $y, $o <= 0 ? self::TRANSPARENT : ($p & 0xFFFFFF) | ((127 - $o) << 24));
    }

    /** Index of pixel (x, y) in the pixel array; no bounds check. */
    public function idx(int $x, int $y): int
    {
        return $y * $this->width + $x;
    }

    /** The pixel as a GD truecolor int; transparent outside the surface. */
    public function getPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return self::TRANSPARENT;
        }
        return $this->px[$y * $this->width + $x];
    }

    /** Replace a pixel with a GD truecolor int; ignored outside the surface. */
    public function setPixel(int $x, int $y, int $v): void
    {
        if ($x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height) {
            $this->px[$y * $this->width + $x] = $v;
        }
    }

    /**
     * Source-over of the colour in $v (its low 24 bits, 0xRRGGBB) with
     * opacity $alpha7 (0..127, 127 = opaque) onto a pixel; ignored outside
     * the surface.  Same arithmetic as the row fast path.
     */
    public function blendPixel(int $x, int $y, int $v, int $alpha7): void
    {
        if ($alpha7 <= 0 || $x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $i = $y * $this->width + $x;
        $this->px[$i] = $alpha7 >= 127
            ? $v & 0xFFFFFF
            : self::overInt($this->px[$i], ($v >> 16) & 0xFF, ($v >> 8) & 0xFF, $v & 0xFF, $alpha7);
    }

    /** The pixel as straight RGBA floats 0..1; zeros outside the surface. @return array{0:float,1:float,2:float,3:float} */
    public function colorAt(int $x, int $y): array
    {
        return self::unpack($this->getPixel($x, $y));
    }

    private function blendAt(int $y, int $x, float $sr, float $sg, float $sb, float $sa, string $mode): void
    {
        $p = $this->getPixel($x, $y);
        if ($mode === 'source-over') {
            $this->setPixel($x, $y, self::overPixel($p, $sr, $sg, $sb, $sa));
            return;
        }
        $out = Compositing::composite($mode, [$sr, $sg, $sb, $sa], self::unpack($p));
        $this->setPixel($x, $y, self::pack($out[0], $out[1], $out[2], $out[3]));
    }

    /**
     * Source-over of one colour onto the pixels x0..x1 of a row.  A run of
     * identical backdrops reuses the previous result.
     *
     * @param ?int $rgb the colour as 0xRRGGBB, when the caller has it
     */
    private function overRun(int $y, int $x0, int $x1, float $sr, float $sg, float $sb, float $sa, ?int $rgb = null): void
    {
        $os = (int) ($sa * 127 + 0.5);
        if ($os <= 0) {
            return;
        }
        $rgb ??= (self::byte($sr) << 16) | (self::byte($sg) << 8) | self::byte($sb);
        $px = &$this->px;
        $i = $y * $this->width + $x0;
        $end = $y * $this->width + $x1 + 1;
        if ($os >= 127) {
            for (; $i < $end; $i++) {
                $px[$i] = $rgb;
            }
            return;
        }
        $r = $rgb >> 16;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $sv = ((127 - $os) << 24) | $rgb;
        $lastD = -1;
        $lastO = 0;
        for (; $i < $end; $i++) {
            $d = $px[$i];
            if ($d !== $lastD) {
                $lastD = $d;
                // Over nothing the result is the source colour itself.
                $lastO = $d >= 0x7F000000 ? $sv : self::overInt($d, $r, $g, $b, $os);
            }
            $px[$i] = $lastO;
        }
    }

    /** Source-over of straight colour (0..1 floats) onto a GD truecolor pixel. */
    private static function overPixel(int $d, float $sr, float $sg, float $sb, float $as): int
    {
        $os = (int) ($as * 127 + 0.5);
        if ($os <= 0) {
            return $d;
        }
        return self::overInt(
            $d,
            $sr <= 0.0 ? 0 : ($sr >= 1.0 ? 255 : (int) ($sr * 255 + 0.5)),
            $sg <= 0.0 ? 0 : ($sg >= 1.0 ? 255 : (int) ($sg * 255 + 0.5)),
            $sb <= 0.0 ? 0 : ($sb >= 1.0 ? 255 : (int) ($sb * 255 + 0.5)),
            $os,
        );
    }

    /**
     * Source-over in the 7-bit domain.  $os is the source weight (127 - GD
     * alpha, 1..127); weights are divided by 127 with the exact integer
     * round(t / 127) = (t * 16513 + 0x100000) >> 21, valid for t <= 32385.
     */
    private static function overInt(int $d, int $r, int $g, int $b, int $os): int
    {
        $od = 127 - ($d >> 24);
        if ($od === 127) {
            // Opaque backdrop: the result stays opaque.
            $ib = 127 - $os;
            return (((($os * $r + $ib * (($d >> 16) & 0xFF)) * 16513 + 0x100000) >> 21) << 16)
                | (((($os * $g + $ib * (($d >> 8) & 0xFF)) * 16513 + 0x100000) >> 21) << 8)
                | ((($os * $b + $ib * ($d & 0xFF)) * 16513 + 0x100000) >> 21);
        }
        if ($od === 0) {
            return ((127 - $os) << 24) | ($r << 16) | ($g << 8) | $b;
        }
        $fb = ($od * (127 - $os) * 16513 + 0x100000) >> 21;
        $oo = $os + $fb;
        $h = $oo >> 1;
        return ((127 - $oo) << 24)
            | ((int) (($os * $r + $fb * (($d >> 16) & 0xFF) + $h) / $oo) << 16)
            | ((int) (($os * $g + $fb * (($d >> 8) & 0xFF) + $h) / $oo) << 8)
            | (int) (($os * $b + $fb * ($d & 0xFF) + $h) / $oo);
    }

    /** GD truecolor value of straight float RGBA. */
    public static function pack(float $r, float $g, float $b, float $a): int
    {
        return ((127 - (int) (max(0.0, min(1.0, $a)) * 127 + 0.5)) << 24)
            | (self::byte($r) << 16) | (self::byte($g) << 8) | self::byte($b);
    }

    private static function byte(float $v): int
    {
        if ($v <= 0.0) {
            return 0;
        }
        if ($v >= 1.0) {
            return 255;
        }
        return (int) ($v * 255 + 0.5);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public static function unpack(int $p): array
    {
        return [
            (($p >> 16) & 0xFF) / 255,
            (($p >> 8) & 0xFF) / 255,
            ($p & 0xFF) / 255,
            (127 - (($p >> 24) & 0x7F)) / 127,
        ];
    }

    /** Raw RGBA8 bytes, in row order — the browser's ImageData layout. */
    public function toRgbaString(): string
    {
        $a8 = self::tables()[0];
        $px = &$this->px;
        $out = '';
        $n = $this->width * $this->height;
        $row = [];
        for ($i = 0; $i < $n; $i += $this->width) {
            $end = $i + $this->width;
            for ($j = $i; $j < $end; $j++) {
                $p = $px[$j];
                $row[$j - $i] = (($p & 0xFFFFFF) << 8) | $a8[$p >> 24];
            }
            $out .= pack('N*', ...$row);
        }
        return $out;
    }

    /** This renderer's pixels as a Surface (itself, or a converted copy). */
    public static function of(RendererInterface $r): self
    {
        return $r instanceof self ? $r : self::fromRgbaString($r->width, $r->height, $r->toRgbaString());
    }

    public static function fromRgbaString(int $width, int $height, string $rgba): self
    {
        $s = new self($width, $height);
        $a7 = self::tables()[1];
        $stride = $width * 4;
        $rgba = str_pad(substr($rgba, 0, $stride * $height), $stride * $height, "\0");
        $px = [];
        for ($y = 0; $y < $height; $y++) {
            foreach (unpack('N*', substr($rgba, $y * $stride, $stride)) as $v) {
                $px[] = (($v >> 8) & 0xFFFFFF) | $a7[$v & 0xFF];
            }
        }
        $s->px = $px;
        return $s;
    }

    // -----------------------------------------------------------------
    // Image transfer
    // -----------------------------------------------------------------

    /**
     * Draw another surface through an affine transform.
     *
     * Sampling happens in source space against pixel centres, which is what a
     * browser's image layer does too: nearest neighbour with smoothing off,
     * bilinear (premultiplied) with it on.
     */
    public function blitAffine(
        RendererInterface $src,
        Matrix $m,
        float $alpha,
        bool $smooth,
        string $mode = 'source-over',
        ?array $subrect = null,
        bool $snap = false,
    ): void {
        $inv = $m->inverse();
        if ($inv === null || $alpha <= 0.0) {
            return;
        }
        $src = self::of($src);
        $sw = $src->width;
        $sh = $src->height;
        if ($sw <= 0 || $sh <= 0) {
            return;
        }

        // Only the requested source rectangle is drawn; with a sub-rectangle,
        // sampling outside it must not happen.
        $rx0 = $subrect === null ? 0.0 : $subrect[0];
        $ry0 = $subrect === null ? 0.0 : $subrect[1];
        $rx1 = $subrect === null ? (float) $sw : $subrect[2];
        $ry1 = $subrect === null ? (float) $sh : $subrect[3];

        $corners = [
            $m->mapPoint($rx0, $ry0),
            $m->mapPoint($rx1, $ry0),
            $m->mapPoint($rx1, $ry1),
            $m->mapPoint($rx0, $ry1),
        ];
        $x0 = max(0, (int) floor(min($corners[0]['x'], $corners[1]['x'], $corners[2]['x'], $corners[3]['x'])));
        $x1 = min($this->width - 1, (int) ceil(max($corners[0]['x'], $corners[1]['x'], $corners[2]['x'], $corners[3]['x'])) - 1);
        $y0 = max(0, (int) floor(min($corners[0]['y'], $corners[1]['y'], $corners[2]['y'], $corners[3]['y'])));
        $y1 = min($this->height - 1, (int) ceil(max($corners[0]['y'], $corners[1]['y'], $corners[2]['y'], $corners[3]['y'])) - 1);
        if ($x0 > $x1 || $y0 > $y1) {
            return;
        }

        if (Compositing::reachesBeyondShape($mode)) {
            // The destination outside the drawn quad is part of the result.
            $x0 = 0;
            $y0 = 0;
            $x1 = $this->width - 1;
            $y1 = $this->height - 1;
        }
        $clip = $this->clip;
        $beyond = Compositing::reachesBeyondShape($mode);
        // A rotated or skewed image has edges that fall between pixels, and a
        // browser antialiases them: measure how much of each pixel the quad
        // covers instead of deciding with the centre point alone.
        $axisAligned = \abs($m->b) < 1e-9 && \abs($m->c) < 1e-9;
        $quad = [];
        if (!$axisAligned && !$snap) {
            foreach ($corners as $c) {
                $quad[] = $c['x'];
                $quad[] = $c['y'];
            }
        }
        $ia = $inv->a;
        $ib = $inv->b;
        $ic = $inv->c;
        $id = $inv->d;
        $ie = $inv->e;
        $if = $inv->f;
        $opa = self::tables()[2];
        $over = $mode === 'source-over';
        for ($y = $y0; $y <= $y1; $y++) {
            $base = $y * $this->width;
            $cy = $y + 0.5;
            for ($x = $x0; $x <= $x1; $x++) {
                $idx = $base + $x;
                if ($clip !== null && \ord($clip[$idx]) === 0) {
                    continue;
                }
                $cx = $x + 0.5;
                $sx = $ia * $cx + $ic * $cy + $ie - 0.5;
                $sy = $ib * $cx + $id * $cy + $if - 0.5;
                $edge = 1.0;
                if ($snap) {
                    // One source pixel to one device pixel: no filter, no edge
                    // fading — a browser copies the pixels untouched.
                    $ix = (int) floor($sx + 0.5);
                    $iy = (int) floor($sy + 0.5);
                    $ok = $ix >= 0 && $iy >= 0 && $ix < $sw && $iy < $sh;
                    if ($ok) {
                        $p = $src->getPixel($ix, $iy);
                        $ok = $p < 0x7F000000;
                    }
                    if (!$ok) {
                        if ($beyond) {
                            $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                        }
                        continue;
                    }
                    $cr = (($p >> 16) & 0xFF) / 255;
                    $cg = (($p >> 8) & 0xFF) / 255;
                    $cb = ($p & 0xFF) / 255;
                    $ca = $opa[$p >> 24];
                } else {
                    // A sample exactly on the edge of the source rectangle belongs
                    // to no pixel: a filtered draw leaves it out.
                    $ok = !($sx <= $rx0 - 0.5 || $sy <= $ry0 - 0.5 || $sx >= $rx1 - 0.5 || $sy >= $ry1 - 0.5);
                    if ($ok && $smooth) {
                        $bx = (int) floor($sx);
                        $by = (int) floor($sy);
                        $fx = $sx - $bx;
                        $fy = $sy - $by;
                        // Four taps, clamped to the image edge, interpolated
                        // premultiplied so transparent edges do not bleed their
                        // colour into neighbours.
                        $tx0 = $bx < 0 ? 0 : ($bx > $sw - 1 ? $sw - 1 : $bx);
                        $tx1 = $bx + 1 < 0 ? 0 : ($bx + 1 > $sw - 1 ? $sw - 1 : $bx + 1);
                        $ty0 = $by < 0 ? 0 : ($by > $sh - 1 ? $sh - 1 : $by);
                        $ty1 = $by + 1 < 0 ? 0 : ($by + 1 > $sh - 1 ? $sh - 1 : $by + 1);
                        $gx = 1 - $fx;
                        $gy = 1 - $fy;
                        $w0 = $gx * $gy;
                        $w1 = $fx * $gy;
                        $w2 = $gx * $fy;
                        $w3 = $fx * $fy;
                        $p = $src->getPixel($tx0, $ty0);
                        $pa = $opa[$p >> 24];
                        $cr = (($p >> 16) & 0xFF) / 255 * $pa * $w0;
                        $cg = (($p >> 8) & 0xFF) / 255 * $pa * $w0;
                        $cb = ($p & 0xFF) / 255 * $pa * $w0;
                        $ca = $pa * $w0;
                        $p = $src->getPixel($tx1, $ty0);
                        $pa = $opa[$p >> 24];
                        $cr += (($p >> 16) & 0xFF) / 255 * $pa * $w1;
                        $cg += (($p >> 8) & 0xFF) / 255 * $pa * $w1;
                        $cb += ($p & 0xFF) / 255 * $pa * $w1;
                        $ca += $pa * $w1;
                        $p = $src->getPixel($tx0, $ty1);
                        $pa = $opa[$p >> 24];
                        $cr += (($p >> 16) & 0xFF) / 255 * $pa * $w2;
                        $cg += (($p >> 8) & 0xFF) / 255 * $pa * $w2;
                        $cb += ($p & 0xFF) / 255 * $pa * $w2;
                        $ca += $pa * $w2;
                        $p = $src->getPixel($tx1, $ty1);
                        $pa = $opa[$p >> 24];
                        $cr += (($p >> 16) & 0xFF) / 255 * $pa * $w3;
                        $cg += (($p >> 8) & 0xFF) / 255 * $pa * $w3;
                        $cb += ($p & 0xFF) / 255 * $pa * $w3;
                        $ca += $pa * $w3;
                        if ($ca <= 0.0) {
                            $ok = false;
                        } else {
                            $cr /= $ca;
                            $cg /= $ca;
                            $cb /= $ca;
                            if ($ca > 1.0) {
                                $ca = 1.0;
                            }
                        }
                    } elseif ($ok) {
                        $ix = (int) floor($sx + 0.5);
                        $iy = (int) floor($sy + 0.5);
                        $ok = $ix >= 0 && $iy >= 0 && $ix < $sw && $iy < $sh;
                        if ($ok) {
                            $p = $src->getPixel($ix, $iy);
                            $cr = (($p >> 16) & 0xFF) / 255;
                            $cg = (($p >> 8) & 0xFF) / 255;
                            $cb = ($p & 0xFF) / 255;
                            $ca = $opa[$p >> 24];
                            $ok = $ca > 0.0;
                        }
                    }
                    if ($ok && $quad !== []) {
                        $edge = self::quadCoverage($x, $y, $quad);
                        $ok = $edge > 0.0;
                    }
                    if (!$ok) {
                        // Transparent source still has a result for modes that see
                        // past the shape, so it must not be skipped for them.
                        if ($beyond) {
                            $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                        }
                        continue;
                    }
                }
                $a = min(1.0, $ca * $alpha * $edge);
                if ($clip !== null) {
                    $cm = \ord($clip[$idx]);
                    if ($cm < 255) {
                        $a = min(1.0, $a * $cm / 255);
                    }
                }
                if ($over) {
                    $this->setPixel($x, $y, self::overPixel($this->getPixel($x, $y), $cr, $cg, $cb, $a));
                } else {
                    $this->blendAt($y, $x, $cr, $cg, $cb, $a, $mode);
                }
            }
        }
    }

    /**
     * How much of the pixel at (x, y) lies inside a convex quad, measured with
     * a 4x4 grid.  Pixels wholly inside or wholly outside cost four tests.
     *
     * @param list<float> $quad  x0,y0,x1,y1,x2,y2,x3,y3 in device space
     */
    private static function quadCoverage(int $x, int $y, array $quad): float
    {
        if (self::inQuad($x, $y, $quad) && self::inQuad($x + 1.0, $y, $quad)
            && self::inQuad($x + 1.0, $y + 1.0, $quad) && self::inQuad($x, $y + 1.0, $quad)) {
            return 1.0;
        }
        $hits = 0;
        for ($j = 0; $j < 8; $j++) {
            $py = $y + ($j + 0.5) / 8;
            for ($i = 0; $i < 8; $i++) {
                if (self::inQuad($x + ($i + 0.5) / 8, $py, $quad)) {
                    $hits++;
                }
            }
        }
        return $hits / 64.0;
    }

    /**
     * @param list<float> $quad
     */
    private static function inQuad(float $x, float $y, array $quad): bool
    {
        [$x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3] = $quad;
        // Same side of all four edges (or on one): the point is inside.
        $c0 = ($x1 - $x0) * ($y - $y0) - ($y1 - $y0) * ($x - $x0);
        $c1 = ($x2 - $x1) * ($y - $y1) - ($y2 - $y1) * ($x - $x1);
        $c2 = ($x3 - $x2) * ($y - $y2) - ($y3 - $y2) * ($x - $x2);
        $c3 = ($x0 - $x3) * ($y - $y3) - ($y0 - $y3) * ($x - $x3);
        return !(($c0 > 0.0 || $c1 > 0.0 || $c2 > 0.0 || $c3 > 0.0) && ($c0 < 0.0 || $c1 < 0.0 || $c2 < 0.0 || $c3 < 0.0));
    }

    /**
     * Paint a coverage mask (text glyphs, drop shadows) using a paint.
     */
    public function blitMask(
        Surface $mask,
        Matrix $m,
        Paint $paint,
        float $alpha,
        string $mode = 'source-over',
    ): void {
        $inv = $m->inverse();
        if ($inv === null || $alpha <= 0.0) {
            return;
        }
        $sw = $mask->width;
        $sh = $mask->height;
        $corners = [
            $m->mapPoint(0.0, 0.0),
            $m->mapPoint((float) $sw, 0.0),
            $m->mapPoint(0.0, (float) $sh),
            $m->mapPoint((float) $sw, (float) $sh),
        ];
        $x0 = max(0, (int) floor(min($corners[0]['x'], $corners[1]['x'], $corners[2]['x'], $corners[3]['x'])));
        $x1 = min($this->width - 1, (int) ceil(max($corners[0]['x'], $corners[1]['x'], $corners[2]['x'], $corners[3]['x'])) - 1);
        $y0 = max(0, (int) floor(min($corners[0]['y'], $corners[1]['y'], $corners[2]['y'], $corners[3]['y'])));
        $y1 = min($this->height - 1, (int) ceil(max($corners[0]['y'], $corners[1]['y'], $corners[2]['y'], $corners[3]['y'])) - 1);
        if ($x0 > $x1 || $y0 > $y1) {
            return;
        }
        $clip = $this->clip;
        $opa = self::tables()[2];
        for ($y = $y0; $y <= $y1; $y++) {
            $base = $y * $this->width;
            for ($x = $x0; $x <= $x1; $x++) {
                $idx = $base + $x;
                if ($clip !== null && \ord($clip[$idx]) === 0) {
                    continue;
                }
                $u = $inv->mapPoint($x + 0.5, $y + 0.5);
                // Bilinear on the mask: it is itself an antialiased coverage
                // field, so resampling it linearly preserves its ink.
                $fx = $u['x'] - 0.5;
                $fy = $u['y'] - 0.5;
                $mx = (int) floor($fx);
                $my = (int) floor($fy);
                if ($mx < -1 || $my < -1 || $mx > $sw || $my > $sh) {
                    continue;
                }
                $tx = $fx - $mx;
                $ty = $fy - $my;
                // getPixel() is transparent outside the mask.
                $coverage = $opa[$mask->getPixel($mx, $my) >> 24] * (1 - $tx) * (1 - $ty)
                    + $opa[$mask->getPixel($mx + 1, $my) >> 24] * $tx * (1 - $ty)
                    + $opa[$mask->getPixel($mx, $my + 1) >> 24] * (1 - $tx) * $ty
                    + $opa[$mask->getPixel($mx + 1, $my + 1) >> 24] * $tx * $ty;
                if ($coverage <= 0.0) {
                    continue;
                }
                $src = $paint->isConstant() ? $paint->constant() : $paint->colorAt($x + 0.5, $y + 0.5);
                $a = min(1.0, $src[3] * $alpha * $coverage);
                if ($clip !== null) {
                    $cm = \ord($clip[$idx]);
                    if ($cm < 255) {
                        $a = min(1.0, $a * $cm / 255);
                    }
                }
                if ($a <= 0.0) {
                    continue;
                }
                $this->blendAt($y, $x, $src[0], $src[1], $src[2], $a, $mode);
            }
        }
    }

    /**
     * Copy a rectangle of pixels out, padding with transparency outside the
     * surface, exactly like getImageData().
     */
    public function readRect(int $x, int $y, int $width, int $height): Surface
    {
        $out = new self($width, $height);
        $x0 = max(0, $x);
        $x1 = min($this->width, $x + $width);
        for ($row = 0; $row < $height; $row++) {
            $sy = $y + $row;
            if ($sy < 0 || $sy >= $this->height || $x1 <= $x0) {
                continue;
            }
            $n = $x1 - $x0;
            array_splice($out->px, $row * $width + $x0 - $x, $n, array_slice($this->px, $sy * $this->width + $x0, $n));
        }
        return $out;
    }

    /**
     * Replace pixels verbatim: no blending, no alpha, no clip, no CTM, as
     * required of putImageData().
     */
    public function writeSurface(RendererInterface $src, int $dx, int $dy): void
    {
        $src = self::of($src);
        $c0 = max(0, -$dx);
        $c1 = min($src->width, $this->width - $dx);
        if ($c1 <= $c0) {
            return;
        }
        for ($row = 0; $row < $src->height; $row++) {
            $ty = $dy + $row;
            if ($ty < 0 || $ty >= $this->height) {
                continue;
            }
            $n = $c1 - $c0;
            array_splice($this->px, $ty * $this->width + $dx + $c0, $n, array_slice($src->px, $row * $src->width + $c0, $n));
        }
    }

    private static function alphaIsOpaqueSafe(string $mode): bool
    {
        // With zero source alpha some operations still erase the destination.
        return !\in_array($mode, [
            'destination-out', 'source-in', 'destination-atop', 'source-atop',
            'copy', 'destination-in', 'xor',
        ], true);
    }
}
