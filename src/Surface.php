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
final class Surface
{
    /** @var array<int,int> packed 0xAARRGGBB, one entry per pixel */
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
        $this->px = array_fill(0, $width * $height, 0x00000000);
    }

    public function clear(): void
    {
        $this->px = array_fill(0, $this->width * $this->height, 0x00000000);
    }

    public function clonePixels(): array
    {
        return $this->px;
    }

    /** @param array<int,int> $px */
    public function setPixels(array $px): void
    {
        $this->px = $px;
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
        $existing = $this->clip;
        $len = strlen($existing);
        for ($i = 0; $i < $len; $i++) {
            $a = \ord($existing[$i]);
            if ($a === 0) {
                continue;
            }
            $b = \ord($mask[$i]);
            $existing[$i] = \chr(intdiv($a * $b, 255));
        }
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
            function (int $y, array $cov) use (&$w): void {
                if ($y < 0 || $y >= $this->height) {
                    return;
                }
                $base = $y * $w;
                foreach ($cov as $x => $coverage) {
                    if ($x < 0 || $x >= $w || $coverage <= 0.0) {
                        continue;
                    }
                    $idx = $base + $x;
                    $coverage = min(1.0, $coverage);
                    if ($coverage >= 1.0) {
                        $this->px[$idx] = 0x00000000;
                        continue;
                    }
                    $d = $this->px[$idx];
                    $a = (($d >> 24) & 0xFF) * (1 - $coverage);
                    if ($a <= 0.0) {
                        $this->px[$idx] = 0x00000000;
                        continue;
                    }
                    $this->px[$idx] = ((int) round($a) << 24)
                        | (($d >> 16) & 0xFF0000)
                        | (($d >> 8) & 0xFF00)
                        | ($d & 0xFF);
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
        Raster::walk($polygons, $rule, $w, $h, $this->verticalSamples,
            function (int $y, array $cov) use (&$bytes, $w, $h): void {
                if ($y < 0 || $y >= $h) {
                    return;
                }
                $base = $y * $w;
                foreach ($cov as $x => $c) {
                    if ($x < 0 || $x >= $w || $c <= 0.0) {
                        continue;
                    }
                    $v = $c >= 1.0 ? 255 : (int) round($c * 255);
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

        Raster::walk($polygons, $rule, $w, $h, $this->verticalSamples,
            function (int $y, array $cov, int $minX, int $maxX, int $fullMin, int $fullMax) use (
                $w, $h, $clip, $constant, $alpha, $mode, $paint
            ): void {
                if ($y < 0 || $y >= $h) {
                    return;
                }
                $base = $y * $w;
                $rowY = $y + 0.5;

                if ($constant !== null && $mode === 'source-over' && $fullMin <= $fullMax) {
                    $ca = $constant[3] * $alpha;
                    if ($ca >= 1.0) {
                        $packed = self::pack($constant[0], $constant[1], $constant[2], 1.0);
                        $from = max(0, $fullMin);
                        $to = min($w - 1, $fullMax);
                        for ($x = $from; $x <= $to; $x++) {
                            if ($clip !== null && \ord($clip[$base + $x]) === 0) {
                                continue;
                            }
                            $this->px[$base + $x] = $packed;
                        }
                    }
                }

                $lo = max(0, $minX);
                $hi = min($w - 1, $maxX);
                for ($x = $lo; $x <= $hi; $x++) {
                    $coverage = $cov[$x] ?? 0.0;
                    if ($coverage <= 0.0) {
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
                    if ($a <= 0.0 && $mode === 'source-over') {
                        continue;
                    }
                    $this->blendAt($base + $x, $src[0], $src[1], $src[2], min(1.0, $a), $mode);
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
            function (int $y, array $cov) use (&$rows): void {
                if ($y >= 0 && $y < $this->height) {
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
                    $this->blendAt($idx, 0.0, 0.0, 0.0, 0.0, $mode);
                    continue;
                }
                $src = $paint->isConstant()
                    ? $paint->constant()
                    : $paint->colorAt($x + 0.5, $y + 0.5);
                $this->blendAt($idx, $src[0], $src[1], $src[2], min(1.0, $a * $src[3]), $mode);
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
        $this->rectRegion($x, $y, $w, $h, function (int $idx, float $fx, float $fy, int $x, int $y) use (
            $paint, $alpha, $mode, $useClip
        ): void {
            $src = $paint->isConstant() ? $paint->constant() : $paint->colorAt($x + 0.5, $y + 0.5);
            $a = min(1.0, $src[3] * $alpha * $fx * $fy);
            if ($a <= 0.0 && $mode === 'source-over') {
                return;
            }
            if ($useClip && $this->clip !== null) {
                $cm = \ord($this->clip[$idx]);
                if ($cm === 0) {
                    return;
                }
                if ($cm < 255) {
                    $a = min(1.0, $a * $cm / 255);
                }
            }
            $this->blendAt($idx, $src[0], $src[1], $src[2], $a, $mode);
        });
    }

    /** Clear to transparent black, honouring no other drawing state. */
    public function clearRectDevice(float $x, float $y, float $w, float $h): void
    {
        $this->rectRegion($x, $y, $w, $h, function (int $idx, float $fx, float $fy): void {
            if ($fx >= 1.0 && $fy >= 1.0) {
                $this->px[$idx] = 0x00000000;
                return;
            }
            // Partial coverage clears proportionally, which is what an
            // antialiased clear of a fractional rectangle does.
            $d = $this->px[$idx];
            $a = (($d >> 24) & 0xFF) / 255;
            $keep = 1.0 - $fx * $fy;
            $na = $a * $keep;
            if ($na <= 0.0) {
                $this->px[$idx] = 0x00000000;
                return;
            }
            $r = ($d >> 16) & 0xFF;
            $g = ($d >> 8) & 0xFF;
            $b = $d & 0xFF;
            $this->px[$idx] = self::packBytes($r, $g, $b, (int) round($na * 255));
        });
    }

    /**
     * Visit every pixel an axis-aligned device rectangle touches.
     *
     * @param callable(int,float,float,int,int):void $fn  (index, coverageX, coverageY, x, y)
     */
    private function rectRegion(float $x, float $y, float $w, float $h, callable $fn): void
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
        $x0 = (int) floor($x);
        $x1 = (int) ceil($x + $w) - 1;
        $y0 = (int) floor($y);
        $y1 = (int) ceil($y + $h) - 1;
        if ($x1 < 0 || $y1 < 0 || $x0 > $this->width - 1 || $y0 > $this->height - 1) {
            return;
        }
        for ($py = max(0, $y0); $py <= min($this->height - 1, $y1); $py++) {
            $top = max($y, (float) $py);
            $bottom = min($y + $h, (float) ($py + 1));
            $cy = $bottom - $top;
            if ($cy <= 0.0) {
                continue;
            }
            $base = $py * $this->width;
            for ($px = max(0, $x0); $px <= min($this->width - 1, $x1); $px++) {
                $left = max($x, (float) $px);
                $right = min($x + $w, (float) ($px + 1));
                $cx = $right - $left;
                if ($cx <= 0.0) {
                    continue;
                }
                $fn($base + $px, $cx, $cy, $px, $py);
            }
        }
    }

    // -----------------------------------------------------------------
    // Pixel access
    // -----------------------------------------------------------------

    public function rawPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return 0;
        }
        return $this->px[$y * $this->width + $x];
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public function getPixel(int $x, int $y): array
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        return self::unpack($this->px[$y * $this->width + $x]);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float} $src
     */
    private function blendAt(int $idx, float $sr, float $sg, float $sb, float $sa, string $mode): void
    {
        $packed = $this->px[$idx] ?? 0;
        $dst = self::unpack($packed);
        $out = Compositing::composite($mode, [$sr, $sg, $sb, $sa], $dst);
        $this->px[$idx] = self::pack($out[0], $out[1], $out[2], $out[3]);
    }

    public static function pack(float $r, float $g, float $b, float $a): int
    {
        return (self::byte($a) << 24) | (self::byte($r) << 16) | (self::byte($g) << 8) | self::byte($b);
    }

    private static function byte(float $v): int
    {
        if ($v <= 0.0) {
            return 0;
        }
        if ($v >= 1.0) {
            return 255;
        }
        return (int) round($v * 255);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public static function unpack(int $p): array
    {
        return [
            (($p >> 16) & 0xFF) / 255,
            (($p >> 8) & 0xFF) / 255,
            ($p & 0xFF) / 255,
            (($p >> 24) & 0xFF) / 255,
        ];
    }

    private static function packBytes(int $r, int $g, int $b, int $a): int
    {
        return ($a << 24) | ($r << 16) | ($g << 8) | $b;
    }

    /** Raw RGBA8 bytes, in row order — the browser's ImageData layout. */
    public function toRgbaString(): string
    {
        $out = '';
        $n = $this->width * $this->height;
        for ($i = 0; $i < $n; $i++) {
            $p = $this->px[$i];
            $out .= \chr(($p >> 16) & 0xFF) . \chr(($p >> 8) & 0xFF) . \chr($p & 0xFF) . \chr(($p >> 24) & 0xFF);
        }
        return $out;
    }

    public static function fromRgbaString(int $width, int $height, string $rgba): self
    {
        $s = new self($width, $height);
        $n = min($width * $height, intdiv(strlen($rgba), 4));
        $px = [];
        for ($i = 0; $i < $n; $i++) {
            $o = $i * 4;
            $px[] = (\ord($rgba[$o]) << 16) | (\ord($rgba[$o + 1]) << 8) | \ord($rgba[$o + 2])
                | (\ord($rgba[$o + 3]) << 24);
        }
        while (count($px) < $width * $height) {
            $px[] = 0;
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
        Surface $src,
        Matrix $m,
        float $alpha,
        bool $smooth,
        string $mode = 'source-over',
        ?array $subrect = null,
    ): void {
        $inv = $m->inverse();
        if ($inv === null || $alpha <= 0.0) {
            return;
        }
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
            $m->mapPoint($rx0, $ry1),
            $m->mapPoint($rx1, $ry1),
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
        for ($y = $y0; $y <= $y1; $y++) {
            $base = $y * $this->width;
            for ($x = $x0; $x <= $x1; $x++) {
                $idx = $base + $x;
                if ($clip !== null && \ord($clip[$idx]) === 0) {
                    continue;
                }
                $u = $inv->mapPoint($x + 0.5, $y + 0.5);
                $sx = $u['x'] - 0.5;
                $sy = $u['y'] - 0.5;
                if ($sx < $rx0 - 0.5 || $sy < $ry0 - 0.5 || $sx > $rx1 - 0.5 || $sy > $ry1 - 0.5) {
                    if ($beyond) {
                        $this->blendAt($idx, 0.0, 0.0, 0.0, 0.0, $mode);
                    }
                    continue;
                }
                $color = $smooth
                    ? self::sampleBilinear($src, $sx, $sy, $sw, $sh)
                    : self::sampleNearest($src, $sx, $sy, $sw, $sh);
                if ($color === null || $color[3] <= 0.0) {
                    continue;
                }
                $a = min(1.0, $color[3] * $alpha);
                if ($clip !== null) {
                    $cm = \ord($clip[$idx]);
                    if ($cm < 255) {
                        $a = min(1.0, $a * $cm / 255);
                    }
                }
                $this->blendAt($idx, $color[0], $color[1], $color[2], $a, $mode);
            }
        }
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
                $at = static function (Surface $m, int $px, int $py, int $w, int $h): float {
                    if ($px < 0 || $py < 0 || $px >= $w || $py >= $h) {
                        return 0.0;
                    }
                    return \ord(chr(($m->rawPixel($px, $py) >> 24) & 0xFF)) / 255;
                };
                $coverage = $at($mask, $mx, $my, $sw, $sh) * (1 - $tx) * (1 - $ty)
                    + $at($mask, $mx + 1, $my, $sw, $sh) * $tx * (1 - $ty)
                    + $at($mask, $mx, $my + 1, $sw, $sh) * (1 - $tx) * $ty
                    + $at($mask, $mx + 1, $my + 1, $sw, $sh) * $tx * $ty;
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
                $this->blendAt($idx, $src[0], $src[1], $src[2], $a, $mode);
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
        $px = [];
        for ($row = 0; $row < $height; $row++) {
            $sy = $y + $row;
            for ($col = 0; $col < $width; $col++) {
                $sx = $x + $col;
                $px[] = ($sx >= 0 && $sy >= 0 && $sx < $this->width && $sy < $this->height)
                    ? $this->px[$sy * $this->width + $sx]
                    : 0;
            }
        }
        $out->px = $px;
        return $out;
    }

    /**
     * Replace pixels verbatim: no blending, no alpha, no clip, no CTM, as
     * required of putImageData().
     */
    public function writeSurface(Surface $src, int $dx, int $dy): void
    {
        for ($row = 0; $row < $src->height; $row++) {
            $ty = $dy + $row;
            if ($ty < 0 || $ty >= $this->height) {
                continue;
            }
            for ($col = 0; $col < $src->width; $col++) {
                $tx = $dx + $col;
                if ($tx < 0 || $tx >= $this->width) {
                    continue;
                }
                $this->px[$ty * $this->width + $tx] = $src->px[$row * $src->width + $col];
            }
        }
    }

    /** @return ?array{0:float,1:float,2:float,3:float} */
    private static function sampleNearest(Surface $src, float $x, float $y, int $sw, int $sh): ?array
    {
        $ix = (int) floor($x);
        $iy = (int) floor($y);
        if ($ix < 0 || $iy < 0 || $ix >= $sw || $iy >= $sh) {
            return null;
        }
        return self::unpack($src->px[$iy * $sw + $ix]);
    }

    /** @return ?array{0:float,1:float,2:float,3:float} */
    private static function sampleBilinear(Surface $src, float $x, float $y, int $sw, int $sh): ?array
    {
        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $fx = $x - $x0;
        $fy = $y - $y0;

        $r = 0.0;
        $g = 0.0;
        $b = 0.0;
        $a = 0.0;
        $found = false;
        for ($dy = 0; $dy <= 1; $dy++) {
            $sy = $y0 + $dy;
            if ($sy < 0 || $sy >= $sh) {
                continue;
            }
            for ($dx = 0; $dx <= 1; $dx++) {
                $sx = $x0 + $dx;
                if ($sx < 0 || $sx >= $sw) {
                    continue;
                }
                $c = self::unpack($src->px[$sy * $sw + $sx]);
                $w = ($dx === 0 ? 1 - $fx : $fx) * ($dy === 0 ? 1 - $fy : $fy);
                if ($w <= 0.0) {
                    continue;
                }
                // Premultiplied interpolation, so transparent edges do not
                // bleed their colour into neighbours.
                $r += $c[0] * $c[3] * $w;
                $g += $c[1] * $c[3] * $w;
                $b += $c[2] * $c[3] * $w;
                $a += $c[3] * $w;
                $found = true;
            }
        }
        if (!$found || $a <= 0.0) {
            return null;
        }
        return [$r / $a, $g / $a, $b / $a, min(1.0, $a)];
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
