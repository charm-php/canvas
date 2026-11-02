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
    /**
     * Straight (non-premultiplied) RGBA8, one string per pixel row, so a row
     * can be edited in place or swapped wholesale without copying the image.
     *
     * @var list<string>
     */
    private array $rows;

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
        $this->rows = array_fill(0, $height, str_repeat("\0", $width * 4));
    }

    public function clear(): void
    {
        $this->rows = array_fill(0, $this->height, str_repeat("\0", $this->width * 4));
    }

    /** @return list<int> packed 0xAARRGGBB, one entry per pixel */
    public function clonePixels(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            foreach (unpack('N*', $row) as $v) {
                $out[] = (($v & 0xFF) << 24) | ($v >> 8);
            }
        }
        return $out;
    }

    /** @param array<int,int> $px packed 0xAARRGGBB, row order */
    public function setPixels(array $px): void
    {
        $px = array_values($px);
        $w = $this->width;
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0, $i = $y * $w; $x < $w; $x++, $i++) {
                $p = $px[$i] ?? 0;
                $row .= pack('N', (($p & 0xFFFFFF) << 8) | (($p >> 24) & 0xFF));
            }
            $this->rows[$y] = $row;
        }
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
            function (int $y, array $cov, int $minX, int $maxX, int $fullMin, int $fullMax) use (&$w): void {
                if ($y < 0 || $y >= $this->height) {
                    return;
                }
                $base = $y * $w;
                $clip = $this->clip;
                for ($x = max(0, $fullMin); $x <= $fullMax && $x < $w; $x++) {
                    $cov[$x] = 1.0;
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
            function (int $y, array $cov, int $minX, int $maxX, int $fullMin, int $fullMax) use (&$bytes, $w, $h): void {
                if ($y < 0 || $y >= $h) {
                    return;
                }
                $base = $y * $w;
                $from = max(0, $fullMin);
                $to = min($w - 1, $fullMax);
                if ($from <= $to) {
                    $bytes = substr_replace($bytes, str_repeat("\xFF", $to - $from + 1), $base + $from, $to - $from + 1);
                }
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

                $over = $mode === 'source-over';
                if ($fullMin <= $fullMax) {
                    // The run is fully covered: every column has coverage 1.
                    $from = max(0, $fullMin);
                    $to = min($w - 1, $fullMax);
                    if ($over && $constant !== null && $clip === null) {
                        $this->overRun($y, $from, $to, $constant[0], $constant[1], $constant[2], min(1.0, $constant[3] * $alpha));
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
                            $this->overRun($y, $x, $x, $src[0], $src[1], $src[2], min(1.0, $a));
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
            function (int $y, array $cov, int $minX, int $maxX, int $fullMin, int $fullMax) use (&$rows): void {
                if ($y >= 0 && $y < $this->height) {
                    for ($x = max(0, $fullMin); $x <= $fullMax; $x++) {
                        $cov[$x] = 1.0;
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
            $this->blendAt($y, $x, $src[0], $src[1], $src[2], $a, $mode);
        });
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
        for ($py = max(0, $y0); $py < min($this->height, $y1); $py++) {
            $base = $py * $this->width;
            for ($px = max(0, $x0); $px < min($this->width, $x1); $px++) {
                $idx = $base + $px;
                $cm = $clip === null ? 255 : \ord($clip[$idx]);
                if ($cm === 0) {
                    continue;
                }
                if ($cm === 255) {
                    $this->rows[$py] = substr_replace($this->rows[$py], "\0\0\0\0", $px * 4, 4);
                    continue;
                }
                $this->fadeAlpha($py, $px, $cm / 255);
            }
        }
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

    /** Remove a fraction of a pixel's alpha, leaving its colour as it was. */
    private function fadeAlpha(int $y, int $x, float $fraction): void
    {
        if ($fraction >= 1.0) {
            $this->rows[$y] = substr_replace($this->rows[$y], "\0\0\0\0", $x * 4, 4);
            return;
        }
        $o = $x * 4;
        $a = \ord($this->rows[$y][$o + 3]) * (1 - $fraction);
        if ($a <= 0.0) {
            $this->rows[$y] = substr_replace($this->rows[$y], "\0\0\0\0", $o, 4);
            return;
        }
        $this->rows[$y][$o + 3] = \chr((int) round($a));
    }

    public function rawPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return 0;
        }
        $o = $x * 4;
        $r = $this->rows[$y];
        return (\ord($r[$o + 3]) << 24) | (\ord($r[$o]) << 16) | (\ord($r[$o + 1]) << 8) | \ord($r[$o + 2]);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public function getPixel(int $x, int $y): array
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $o = $x * 4;
        $r = $this->rows[$y];
        return [\ord($r[$o]) / 255, \ord($r[$o + 1]) / 255, \ord($r[$o + 2]) / 255, \ord($r[$o + 3]) / 255];
    }

    private function blendAt(int $y, int $x, float $sr, float $sg, float $sb, float $sa, string $mode): void
    {
        $o = $x * 4;
        $row = $this->rows[$y];
        $dst = [\ord($row[$o]) / 255, \ord($row[$o + 1]) / 255, \ord($row[$o + 2]) / 255, \ord($row[$o + 3]) / 255];
        $out = Compositing::composite($mode, [$sr, $sg, $sb, $sa], $dst);
        $this->rows[$y] = substr_replace(
            $row,
            \chr(self::byte($out[0])) . \chr(self::byte($out[1])) . \chr(self::byte($out[2])) . \chr(self::byte($out[3])),
            $o,
            4,
        );
    }

    /**
     * Source-over of one colour onto the pixels x0..x1 of a row, where $cov
     * (when not null) gives each column's coverage.  Same arithmetic as
     * Compositing::composite() for source-over, on bytes, with no allocation
     * per pixel; a run of identical backdrops reuses the previous result.
     */
    private function overRun(int $y, int $x0, int $x1, float $sr, float $sg, float $sb, float $sa): void
    {
        if ($sa <= 0.0) {
            return;
        }
        $row = $this->rows[$y];
        if ($sa >= 1.0) {
            $n = $x1 - $x0 + 1;
            $this->rows[$y] = substr_replace(
                $row,
                str_repeat(\chr(self::byte($sr)) . \chr(self::byte($sg)) . \chr(self::byte($sb)) . "\xFF", $n),
                $x0 * 4,
                $n * 4,
            );
            return;
        }
        $lastD = null;
        $lastO = '';
        $new = '';
        for ($o = $x0 * 4, $end = $x1 * 4 + 4; $o < $end; $o += 4) {
            $d = substr($row, $o, 4);
            if ($d !== $lastD) {
                $lastD = $d;
                $lastO = self::overPixel($d, $sr, $sg, $sb, $sa);
            }
            $new .= $lastO;
        }
        $this->rows[$y] = substr_replace($row, $new, $x0 * 4, $end - $x0 * 4);
    }

    /** Source-over of straight colour (0..1 floats) onto a 4-byte RGBA pixel. */
    private static function overPixel(string $d, float $sr, float $sg, float $sb, float $as): string
    {
        $ad = \ord($d[3]) / 255;
        $fb = (1.0 - $as) * $ad;
        $ao = $as + $fb;
        if ($ao <= 0.0) {
            return "\0\0\0\0";
        }
        $r = ($as * $sr + $fb * (\ord($d[0]) / 255)) / $ao;
        $g = ($as * $sg + $fb * (\ord($d[1]) / 255)) / $ao;
        $b = ($as * $sb + $fb * (\ord($d[2]) / 255)) / $ao;
        return \chr($r <= 0.0 ? 0 : ($r >= 1.0 ? 255 : (int) round($r * 255)))
            . \chr($g <= 0.0 ? 0 : ($g >= 1.0 ? 255 : (int) round($g * 255)))
            . \chr($b <= 0.0 ? 0 : ($b >= 1.0 ? 255 : (int) round($b * 255)))
            . \chr($ao >= 1.0 ? 255 : (int) round($ao * 255));
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
        return implode('', $this->rows);
    }

    public static function fromRgbaString(int $width, int $height, string $rgba): self
    {
        $s = new self($width, $height);
        $stride = $width * 4;
        $rgba = str_pad(substr($rgba, 0, $stride * $height), $stride * $height, "\0");
        for ($y = 0; $y < $height; $y++) {
            $s->rows[$y] = substr($rgba, $y * $stride, $stride);
        }
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
        bool $snap = false,
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
                if ($snap) {
                    // One source pixel to one device pixel: no filter, no edge
                    // fading — a browser copies the pixels untouched.
                    $color = self::sampleNearest($src, $sx, $sy, $sw, $sh);
                    if ($color === null || $color[3] <= 0.0) {
                        if ($beyond) {
                            $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                        }
                        continue;
                    }
                    $a = min(1.0, $color[3] * $alpha);
                    if ($clip !== null) {
                        $cm = \ord($clip[$idx]);
                        if ($cm < 255) {
                            $a = min(1.0, $a * $cm / 255);
                        }
                    }
                    $this->blendAt($y, $x, $color[0], $color[1], $color[2], $a, $mode);
                    continue;
                }
                // A sample exactly on the edge of the source rectangle belongs
                // to no pixel: a filtered draw leaves it out.
                if ($sx <= $rx0 - 0.5 || $sy <= $ry0 - 0.5 || $sx >= $rx1 - 0.5 || $sy >= $ry1 - 0.5) {
                    if ($beyond) {
                        $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                    }
                    continue;
                }
                $color = $smooth
                    ? self::sampleBilinear($src, $sx, $sy, $sw, $sh)
                    : self::sampleNearest($src, $sx, $sy, $sw, $sh);
                if ($color === null || $color[3] <= 0.0) {
                    // Transparent source still has a result for modes that see
                    // past the shape, so it must not be skipped for them.
                    if ($beyond) {
                        $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                    }
                    continue;
                }
                $edge = 1.0;
                if ($quad !== []) {
                    $edge = self::quadCoverage($x, $y, $quad);
                    if ($edge <= 0.0) {
                        if ($beyond) {
                            $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                        }
                        continue;
                    }
                }
                $a = min(1.0, $color[3] * $alpha * $edge);
                if ($clip !== null) {
                    $cm = \ord($clip[$idx]);
                    if ($cm < 255) {
                        $a = min(1.0, $a * $cm / 255);
                    }
                }
                $this->blendAt($y, $x, $color[0], $color[1], $color[2], $a, $mode);
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
        $corners = 0;
        foreach ([[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]] as $o) {
            if (self::inQuad($x + $o[0], $y + $o[1], $quad)) {
                $corners++;
            }
        }
        if ($corners === 4) {
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
        $sign = 0;
        for ($i = 0; $i < 8; $i += 2) {
            $j = ($i + 2) % 8;
            $cross = ($quad[$j] - $quad[$i]) * ($y - $quad[$i + 1])
                - ($quad[$j + 1] - $quad[$i + 1]) * ($x - $quad[$i]);
            if ($cross > 0.0) {
                if ($sign < 0) {
                    return false;
                }
                $sign = 1;
            } elseif ($cross < 0.0) {
                if ($sign > 0) {
                    return false;
                }
                $sign = -1;
            }
        }
        return true;
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
                    return \ord($m->rows[$py][$px * 4 + 3]) / 255;
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
            $out->rows[$row] = substr_replace($out->rows[$row], substr($this->rows[$sy], $x0 * 4, ($x1 - $x0) * 4), ($x0 - $x) * 4, ($x1 - $x0) * 4);
        }
        return $out;
    }

    /**
     * Replace pixels verbatim: no blending, no alpha, no clip, no CTM, as
     * required of putImageData().
     */
    public function writeSurface(Surface $src, int $dx, int $dy): void
    {
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
            $this->rows[$ty] = substr_replace($this->rows[$ty], substr($src->rows[$row], $c0 * 4, ($c1 - $c0) * 4), ($dx + $c0) * 4, ($c1 - $c0) * 4);
        }
    }

    /** @return ?array{0:float,1:float,2:float,3:float} */
    private static function sampleNearest(Surface $src, float $x, float $y, int $sw, int $sh): ?array
    {
        // Coordinates here are pixel centres, so the nearest pixel is the one
        // whose centre is closest.
        $ix = (int) floor($x + 0.5);
        $iy = (int) floor($y + 0.5);
        if ($ix < 0 || $iy < 0 || $ix >= $sw || $iy >= $sh) {
            return null;
        }
        return $src->getPixel($ix, $iy);
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
            // Clamp to the edge rather than treat it as transparent: a filter
            // reaching past the border of an image reads the border pixel, and
            // the weights then still add to one.
            $sy = min($sh - 1, max(0, $y0 + $dy));
            for ($dx = 0; $dx <= 1; $dx++) {
                $sx = min($sw - 1, max(0, $x0 + $dx));
                $c = $src->getPixel($sx, $sy);
                $w = ($dx === 0 ? 1 - $fx : $fx) * ($dy === 0 ? 1 - $fy : $fy);
                if ($w <= 0.0) {
                    continue;
                }
                $found = true;
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
