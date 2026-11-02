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
     * Straight (non-premultiplied) RGBA8, one int per pixel, row after row,
     * as 0xRRGGBBAA so that pack('N*') yields ImageData bytes directly.
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
        $this->px = array_fill(0, $width * $height, 0);
    }

    public function clear(): void
    {
        $this->px = array_fill(0, $this->width * $this->height, 0);
    }

    /** @return list<int> packed 0xAARRGGBB, one entry per pixel */
    public function clonePixels(): array
    {
        $out = [];
        foreach ($this->px as $v) {
            $out[] = (($v & 0xFF) << 24) | ($v >> 8);
        }
        return $out;
    }

    /** @param array<int,int> $px packed 0xAARRGGBB, row order */
    public function setPixels(array $px): void
    {
        $n = $this->width * $this->height;
        $out = [];
        foreach (array_slice(array_values($px), 0, $n) as $p) {
            $out[] = (($p & 0xFFFFFF) << 8) | (($p >> 24) & 0xFF);
        }
        $this->px = $out + array_fill(0, $n, 0);
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
                            $this->overRun($y, $x, $x, $src[0], $src[1], $src[2], min(1.0, $a), $rgb);
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
        $px = &$this->px;
        for ($py = max(0, $y0); $py <= min($this->height - 1, $y1); $py++) {
            $cy = min($y + $h, (float) ($py + 1)) - max($y, (float) $py);
            if ($cy <= 0.0) {
                continue;
            }
            $base = $py * $this->width;
            $rowY = $py + 0.5;
            for ($qx = max(0, $x0); $qx <= min($this->width - 1, $x1); $qx++) {
                $cx = min($x + $w, (float) ($qx + 1)) - max($x, (float) $qx);
                if ($cx <= 0.0) {
                    continue;
                }
                $idx = $base + $qx;
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
                    $px[$idx] = $a >= 1.0
                        ? (self::byte($src[0]) << 24) | (self::byte($src[1]) << 16) | (self::byte($src[2]) << 8) | 0xFF
                        : self::overPixel($px[$idx], $src[0], $src[1], $src[2], $a);
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
        for ($py = max(0, $y0); $py < min($this->height, $y1); $py++) {
            $base = $py * $this->width;
            for ($px = max(0, $x0); $px < min($this->width, $x1); $px++) {
                $idx = $base + $px;
                $cm = $clip === null ? 255 : \ord($clip[$idx]);
                if ($cm === 0) {
                    continue;
                }
                if ($cm === 255) {
                    $this->px[$idx] = 0;
                    continue;
                }
                $this->fadeAlpha($py, $px, $cm / 255);
            }
        }
    }

    // -----------------------------------------------------------------
    // Pixel access
    // -----------------------------------------------------------------

    /** Remove a fraction of a pixel's alpha, leaving its colour as it was. */
    private function fadeAlpha(int $y, int $x, float $fraction): void
    {
        $i = $y * $this->width + $x;
        if ($fraction >= 1.0) {
            $this->px[$i] = 0;
            return;
        }
        $p = $this->px[$i];
        $a = ($p & 0xFF) * (1 - $fraction);
        $this->px[$i] = $a <= 0.0 ? 0 : ($p & 0xFFFFFF00) | (int) round($a);
    }

    public function rawPixel(int $x, int $y): int
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return 0;
        }
        $p = $this->px[$y * $this->width + $x];
        return (($p & 0xFF) << 24) | ($p >> 8);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public function getPixel(int $x, int $y): array
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $p = $this->px[$y * $this->width + $x];
        return [($p >> 24) / 255, (($p >> 16) & 0xFF) / 255, (($p >> 8) & 0xFF) / 255, ($p & 0xFF) / 255];
    }

    private function blendAt(int $y, int $x, float $sr, float $sg, float $sb, float $sa, string $mode): void
    {
        $i = $y * $this->width + $x;
        $p = $this->px[$i];
        if ($mode === 'source-over') {
            $this->px[$i] = self::overPixel($p, $sr, $sg, $sb, $sa);
            return;
        }
        $dst = [($p >> 24) / 255, (($p >> 16) & 0xFF) / 255, (($p >> 8) & 0xFF) / 255, ($p & 0xFF) / 255];
        $out = Compositing::composite($mode, [$sr, $sg, $sb, $sa], $dst);
        $this->px[$i] = (self::byte($out[0]) << 24) | (self::byte($out[1]) << 16) | (self::byte($out[2]) << 8) | self::byte($out[3]);
    }

    /**
     * Source-over of one colour onto the pixels x0..x1 of a row.  Same
     * arithmetic as Compositing::composite() for source-over, with no
     * allocation per pixel; a run of identical backdrops reuses the previous
     * result.
     *
     * @param ?int $rgb the colour as 0xRRGGBB, when the caller has it
     */
    private function overRun(int $y, int $x0, int $x1, float $sr, float $sg, float $sb, float $sa, ?int $rgb = null): void
    {
        if ($sa <= 0.0) {
            return;
        }
        $rgb ??= (self::byte($sr) << 16) | (self::byte($sg) << 8) | self::byte($sb);
        $px = &$this->px;
        $i = $y * $this->width + $x0;
        $end = $y * $this->width + $x1 + 1;
        if ($sa >= 1.0) {
            $v = ($rgb << 8) | 0xFF;
            for (; $i < $end; $i++) {
                $px[$i] = $v;
            }
            return;
        }
        $sv = ($rgb << 8) | (int) round($sa * 255);
        $lastD = -1;
        $lastO = 0;
        for (; $i < $end; $i++) {
            $d = $px[$i];
            if ($d !== $lastD) {
                $lastD = $d;
                // Over nothing the result is the source colour itself.
                $lastO = ($d & 0xFF) === 0 ? $sv : self::overPixel($d, $sr, $sg, $sb, $sa);
            }
            $px[$i] = $lastO;
        }
    }

    /** Source-over of straight colour (0..1 floats) onto a packed 0xRRGGBBAA pixel. */
    private static function overPixel(int $d, float $sr, float $sg, float $sb, float $as): int
    {
        $ad = ($d & 0xFF) / 255;
        $fb = (1.0 - $as) * $ad;
        $ao = $as + $fb;
        if ($ao <= 0.0) {
            return 0;
        }
        $r = ($as * $sr + $fb * (($d >> 24) / 255)) / $ao;
        $g = ($as * $sg + $fb * ((($d >> 16) & 0xFF) / 255)) / $ao;
        $b = ($as * $sb + $fb * ((($d >> 8) & 0xFF) / 255)) / $ao;
        return (($r <= 0.0 ? 0 : ($r >= 1.0 ? 255 : (int) round($r * 255))) << 24)
            | (($g <= 0.0 ? 0 : ($g >= 1.0 ? 255 : (int) round($g * 255))) << 16)
            | (($b <= 0.0 ? 0 : ($b >= 1.0 ? 255 : (int) round($b * 255))) << 8)
            | ($ao >= 1.0 ? 255 : (int) round($ao * 255));
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
        for ($y = 0; $y < $this->height; $y++) {
            $out .= pack('N*', ...array_slice($this->px, $y * $this->width, $this->width));
        }
        return $out;
    }

    public static function fromRgbaString(int $width, int $height, string $rgba): self
    {
        $s = new self($width, $height);
        $stride = $width * 4;
        $rgba = str_pad(substr($rgba, 0, $stride * $height), $stride * $height, "\0");
        $px = [];
        for ($y = 0; $y < $height; $y++) {
            foreach (unpack('N*', substr($rgba, $y * $stride, $stride)) as $v) {
                $px[] = $v;
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
        $ia = $inv->a;
        $ib = $inv->b;
        $ic = $inv->c;
        $id = $inv->d;
        $ie = $inv->e;
        $if = $inv->f;
        $sp = $src->px;
        $over = $mode === 'source-over';
        $px = &$this->px;
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
                        $p = $sp[$iy * $sw + $ix];
                        $ok = ($p & 0xFF) !== 0;
                    }
                    if (!$ok) {
                        if ($beyond) {
                            $this->blendAt($y, $x, 0.0, 0.0, 0.0, 0.0, $mode);
                        }
                        continue;
                    }
                    $cr = ($p >> 24) / 255;
                    $cg = (($p >> 16) & 0xFF) / 255;
                    $cb = (($p >> 8) & 0xFF) / 255;
                    $ca = ($p & 0xFF) / 255;
                } else {
                    // A sample exactly on the edge of the source rectangle belongs
                    // to no pixel: a filtered draw leaves it out.
                    $ok = !($sx <= $rx0 - 0.5 || $sy <= $ry0 - 0.5 || $sx >= $rx1 - 0.5 || $sy >= $ry1 - 0.5);
                    if ($ok && $smooth) {
                        $bx = (int) floor($sx);
                        $by = (int) floor($sy);
                        $fx = $sx - $bx;
                        $fy = $sy - $by;
                        $cr = $cg = $cb = $ca = 0.0;
                        for ($k = 0; $k < 4; $k++) {
                            $dx = $k & 1;
                            $w = ($dx === 0 ? 1 - $fx : $fx) * ($k < 2 ? 1 - $fy : $fy);
                            if ($w <= 0.0) {
                                continue;
                            }
                            $tx = min($sw - 1, max(0, $bx + $dx));
                            $ty = min($sh - 1, max(0, $by + ($k >> 1)));
                            $p = $sp[$ty * $sw + $tx];
                            // Premultiplied interpolation, so transparent edges do
                            // not bleed their colour into neighbours.
                            $pa = ($p & 0xFF) / 255;
                            $cr += ($p >> 24) / 255 * $pa * $w;
                            $cg += (($p >> 16) & 0xFF) / 255 * $pa * $w;
                            $cb += (($p >> 8) & 0xFF) / 255 * $pa * $w;
                            $ca += $pa * $w;
                        }
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
                            $p = $sp[$iy * $sw + $ix];
                            $cr = ($p >> 24) / 255;
                            $cg = (($p >> 16) & 0xFF) / 255;
                            $cb = (($p >> 8) & 0xFF) / 255;
                            $ca = ($p & 0xFF) / 255;
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
                    $px[$idx] = self::overPixel($px[$idx], $cr, $cg, $cb, $a);
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
                    return ($m->px[$py * $w + $px] & 0xFF) / 255;
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
            $n = $x1 - $x0;
            array_splice($out->px, $row * $width + $x0 - $x, $n, array_slice($this->px, $sy * $this->width + $x0, $n));
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
