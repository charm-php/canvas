<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * The 2D rendering context: the HTML CanvasRenderingContext2D surface.
 *
 * The current transformation matrix, the clip stack and every style property
 * live here, and every drawing operation translates the browser's model into
 * geometry plus one Surface call.  Property assignment follows the browser
 * rules, including the ones that surprise people: an invalid value is ignored
 * rather than throwing, so a typo leaves the previous style in place.
 */
class Context
{
    private Matrix $ctm;
    private string|Gradient|Pattern $fillStyle = '#000000';
    private string|Gradient|Pattern $strokeStyle = '#000000';
    private float $lineWidth = 1.0;
    private string $lineCap = 'butt';
    private string $lineJoin = 'miter';
    private float $miterLimit = 10.0;
    private float $globalAlpha = 1.0;
    private string $globalCompositeOperation = 'source-over';
    private string $font = '10px sans-serif';
    private string $textAlign = 'start';
    private string $textBaseline = 'alphabetic';
    private string $direction = 'inherit';
    /** @var list<float> */
    private array $lineDash = [];
    private float $lineDashOffset = 0.0;
    private bool $imageSmoothingEnabled = true;
    private string $imageSmoothingQuality = 'low';
    private string $filter = 'none';
    private float $shadowBlur = 0.0;
    private string $shadowColor = 'rgba(0, 0, 0, 0)';
    private float $shadowOffsetX = 0.0;
    private float $shadowOffsetY = 0.0;

    private Path $path;

    /** @var list<array> */
    private array $stateStack = [];

    /** @var list<int> clip depth at each saved state */
    private array $clipDepths = [];

    public function __construct(private Surface $surface)
    {
        $this->ctm = Matrix::identity();
        $this->path = new Path();
    }

    public function surface(): Surface
    {
        return $this->surface;
    }

    // -----------------------------------------------------------------
    // Properties
    // -----------------------------------------------------------------

    public function __get(string $name): mixed
    {
        return match ($name) {
            'fillStyle' => $this->fillStyle,
            'strokeStyle' => $this->strokeStyle,
            'lineWidth' => $this->lineWidth,
            'lineCap' => $this->lineCap,
            'lineJoin' => $this->lineJoin,
            'miterLimit' => $this->miterLimit,
            'globalAlpha' => $this->globalAlpha,
            'globalCompositeOperation' => $this->globalCompositeOperation,
            'font' => $this->font,
            'textAlign' => $this->textAlign,
            'textBaseline' => $this->textBaseline,
            'direction' => $this->direction,
            'lineDashOffset' => $this->lineDashOffset,
            'imageSmoothingEnabled' => $this->imageSmoothingEnabled,
            'imageSmoothingQuality' => $this->imageSmoothingQuality,
            'filter' => $this->filter,
            'shadowBlur' => $this->shadowBlur,
            'shadowColor' => $this->shadowColor,
            'shadowOffsetX' => $this->shadowOffsetX,
            'shadowOffsetY' => $this->shadowOffsetY,
            'canvas' => null,
            default => throw new \InvalidArgumentException("Unknown property: $name"),
        };
    }

    public function __set(string $name, mixed $value): void
    {
        switch ($name) {
            case 'fillStyle':
                $this->setStyle($value, true);
                return;
            case 'strokeStyle':
                $this->setStyle($value, false);
                return;
            case 'lineWidth':
                if (is_numeric($value) && $value > 0.0 && is_finite((float) $value)) {
                    $this->lineWidth = (float) $value;
                }
                return;
            case 'lineCap':
                if (\in_array($value, ['butt', 'round', 'square'], true)) {
                    $this->lineCap = $value;
                }
                return;
            case 'lineJoin':
                if (\in_array($value, ['round', 'bevel', 'miter'], true)) {
                    $this->lineJoin = $value;
                }
                return;
            case 'miterLimit':
                if (is_numeric($value) && $value > 0.0 && is_finite((float) $value)) {
                    $this->miterLimit = (float) $value;
                }
                return;
            case 'globalAlpha':
                if (is_numeric($value) && $value >= 0.0 && $value <= 1.0) {
                    $this->globalAlpha = (float) $value;
                }
                return;
            case 'globalCompositeOperation':
                if (\in_array($value, Compositing::MODES, true)) {
                    $this->globalCompositeOperation = (string) $value;
                }
                return;
            case 'font':
                if (\is_string($value) && $value !== '') {
                    // Browsers ignore a font string they cannot parse; a size
                    // is the minimum we can check for.
                    if (preg_match('/[0-9]*\.?[0-9]+\s*(px|pt|em|rem|%|cm|mm|in|pc)/i', $value) === 1) {
                        $this->font = $value;
                    }
                }
                return;
            case 'textAlign':
                if (\in_array($value, ['start', 'end', 'left', 'right', 'center'], true)) {
                    $this->textAlign = $value;
                }
                return;
            case 'textBaseline':
                if (\in_array($value, ['top', 'hanging', 'middle', 'alphabetic', 'ideographic', 'bottom'], true)) {
                    $this->textBaseline = $value;
                }
                return;
            case 'direction':
                if (\in_array($value, ['ltr', 'rtl', 'inherit'], true)) {
                    $this->direction = $value;
                }
                return;
            case 'lineDashOffset':
                if (is_numeric($value) && is_finite((float) $value)) {
                    $this->lineDashOffset = (float) $value;
                }
                return;
            case 'imageSmoothingEnabled':
                $this->imageSmoothingEnabled = (bool) $value;
                return;
            case 'imageSmoothingQuality':
                if (\in_array($value, ['low', 'medium', 'high'], true)) {
                    $this->imageSmoothingQuality = $value;
                }
                return;
            case 'filter':
                $this->filter = \is_string($value) ? $value : 'none';
                return;
            case 'shadowBlur':
                if (is_numeric($value) && $value >= 0.0) {
                    $this->shadowBlur = (float) $value;
                }
                return;
            case 'shadowColor':
                if (\is_string($value)) {
                    try {
                        ColorParser::parseNormalized($value);
                        $this->shadowColor = $value;
                    } catch (\Throwable) {
                        // Ignored, as in browsers.
                    }
                }
                return;
            case 'shadowOffsetX':
            case 'shadowOffsetY':
                if (is_numeric($value) && is_finite((float) $value)) {
                    $this->{$name} = (float) $value;
                }
                return;
            default:
                throw new \InvalidArgumentException("Unknown property: $name");
        }
    }

    private function setStyle(mixed $value, bool $fill): void
    {
        if ($value instanceof Gradient || $value instanceof Pattern) {
            $fill ? $this->fillStyle = $value : $this->strokeStyle = $value;
            return;
        }
        if (!\is_string($value)) {
            return; // ignored, like an unparseable CSS colour
        }
        try {
            ColorParser::parseNormalized($value);
        } catch (\InvalidArgumentException) {
            return;
        }
        $fill ? $this->fillStyle = $value : $this->strokeStyle = $value;
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function save(): void
    {
        $this->stateStack[] = [
            $this->ctm, $this->fillStyle, $this->strokeStyle, $this->lineWidth,
            $this->lineCap, $this->lineJoin, $this->miterLimit, $this->globalAlpha,
            $this->globalCompositeOperation, $this->font, $this->textAlign,
            $this->textBaseline, $this->direction, $this->lineDash,
            $this->lineDashOffset, $this->imageSmoothingEnabled,
            $this->imageSmoothingQuality, $this->filter, $this->shadowBlur,
            $this->shadowColor, $this->shadowOffsetX, $this->shadowOffsetY,
        ];
        $this->clipDepths[] = $this->surface->clipDepth();
    }

    public function restore(): void
    {
        if ($this->stateStack === []) {
            return;
        }
        $wanted = (int) array_pop($this->clipDepths);
        while ($this->surface->clipDepth() > $wanted) {
            $this->surface->popClip();
        }
        [
            $this->ctm, $this->fillStyle, $this->strokeStyle, $this->lineWidth,
            $this->lineCap, $this->lineJoin, $this->miterLimit, $this->globalAlpha,
            $this->globalCompositeOperation, $this->font, $this->textAlign,
            $this->textBaseline, $this->direction, $this->lineDash,
            $this->lineDashOffset, $this->imageSmoothingEnabled,
            $this->imageSmoothingQuality, $this->filter, $this->shadowBlur,
            $this->shadowColor, $this->shadowOffsetX, $this->shadowOffsetY,
        ] = array_pop($this->stateStack);
    }

    // -----------------------------------------------------------------
    // Transformations
    // -----------------------------------------------------------------

    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->ctm = $this->ctm->multiply(new Matrix($a, $b, $c, $d, $e, $f));
    }

    public function translate(float $x, float $y): void
    {
        $this->ctm = $this->ctm->translated($x, $y);
    }

    public function rotate(float $angle): void
    {
        $this->ctm = $this->ctm->rotated($angle);
    }

    public function scale(float $x, float $y): void
    {
        $this->ctm = $this->ctm->scaled($x, $y);
    }

    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->ctm = new Matrix($a, $b, $c, $d, $e, $f);
    }

    public function resetTransform(): void
    {
        $this->ctm = Matrix::identity();
    }

    /** @return array{a:float,b:float,c:float,d:float,e:float,f:float} */
    public function getTransform(): array
    {
        return [
            'a' => $this->ctm->a, 'b' => $this->ctm->b, 'c' => $this->ctm->c,
            'd' => $this->ctm->d, 'e' => $this->ctm->e, 'f' => $this->ctm->f,
        ];
    }

    public function ctm(): Matrix
    {
        return $this->ctm;
    }

    // -----------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------

    public function beginPath(): void
    {
        $this->path = new Path();
    }

    public function closePath(): void
    {
        $this->path->closePath();
    }

    public function moveTo(float $x, float $y): void
    {
        $this->path->moveTo($x, $y);
    }

    public function lineTo(float $x, float $y): void
    {
        $this->path->lineTo($x, $y);
    }

    public function quadraticCurveTo(float $cx, float $cy, float $x, float $y): void
    {
        $this->path->quadraticCurveTo($cx, $cy, $x, $y);
    }

    public function bezierCurveTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): void
    {
        $this->path->bezierCurveTo($c1x, $c1y, $c2x, $c2y, $x, $y);
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        $this->path->arcTo($x1, $y1, $x2, $y2, $radius);
    }

    public function arc(float $x, float $y, float $radius, float $start, float $end, bool $ccw = false): void
    {
        $this->path->arc($x, $y, $radius, $start, $end, $ccw);
    }

    public function ellipse(
        float $x,
        float $y,
        float $rx,
        float $ry,
        float $rotation,
        float $start,
        float $end,
        bool $ccw = false,
    ): void {
        $this->path->ellipse($x, $y, $rx, $ry, $rotation, $start, $end, $ccw);
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->path->rect($x, $y, $w, $h);
    }

    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii = 0.0): void
    {
        $this->path->roundRect($x, $y, $w, $h, $radii);
    }

    // -----------------------------------------------------------------
    // Painting
    // -----------------------------------------------------------------

    public function fill(string $rule = Raster::NONZERO): void
    {
        $rule = $rule === Raster::EVENODD ? Raster::EVENODD : Raster::NONZERO;
        $paint = $this->resolvePaint($this->fillStyle);
        if ($paint === null) {
            return;
        }
        $polygons = [];
        foreach ($this->path->flatten($this->ctm) as $p) {
            $polygons[] = $p['pts'];
        }
        $this->surface->fillPolygons($polygons, $paint, $this->globalAlpha, $rule, $this->globalCompositeOperation);
    }

    public function stroke(): void
    {
        if ($this->lineWidth <= 0.0) {
            return;
        }
        $paint = $this->resolvePaint($this->strokeStyle);
        if ($paint === null) {
            return;
        }
        $scale = $this->ctm->averageScale();
        // Flatten in user space, but sample the curves to device accuracy.
        $tolerance = $scale > 0.001 ? 0.12 / $scale : 0.12;
        $userPolylines = $this->path->flatten(Matrix::identity(), $tolerance);
        $strokePolylines = Stroker::build(
            $userPolylines,
            $this->lineWidth,
            $this->lineCap,
            $this->lineJoin,
            $this->miterLimit,
            $this->lineDash,
            $this->lineDashOffset,
        );
        if ($strokePolylines === []) {
            return;
        }
        $polygons = [];
        foreach ($strokePolylines as $poly) {
            $mapped = [];
            $n = intdiv(count($poly), 2);
            for ($i = 0; $i < $n; $i++) {
                $p = $this->ctm->mapPoint($poly[$i * 2], $poly[$i * 2 + 1]);
                $mapped[] = $p['x'];
                $mapped[] = $p['y'];
            }
            if (intdiv(count($mapped), 2) >= 3) {
                $polygons[] = $mapped;
            }
        }
        if ($polygons === []) {
            return;
        }
        $this->surface->fillPolygons($polygons, $paint, $this->globalAlpha, Raster::NONZERO, $this->globalCompositeOperation);
    }

    public function fillRect(float $x, float $y, float $w, float $h): void
    {
        $paint = $this->resolvePaint($this->fillStyle);
        if ($paint === null) {
            return;
        }
        if ($this->ctm->isAxisAligned() && !Compositing::reachesBeyondShape($this->globalCompositeOperation)) {
            $r = $this->ctm->mapAxisRect($x, $y, $w, $h);
            $this->surface->fillRectDevice(
                $r['x'],
                $r['y'],
                $r['w'],
                $r['h'],
                $paint,
                $this->globalAlpha,
                $this->globalCompositeOperation,
            );
            return;
        }
        $poly = [];
        foreach ([[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]] as $p) {
            $d = $this->ctm->mapPoint($p[0], $p[1]);
            $poly[] = $d['x'];
            $poly[] = $d['y'];
        }
        $this->surface->fillPolygons([$poly], $paint, $this->globalAlpha, Raster::NONZERO, $this->globalCompositeOperation);
    }

    public function strokeRect(float $x, float $y, float $w, float $h): void
    {
        $this->save();
        $path = $this->path;
        $this->path = new Path();
        $this->path->rect($x, $y, $w, $h);
        $this->stroke();
        $this->path = $path;
        $this->restore();
    }

    public function clearRect(float $x, float $y, float $w, float $h): void
    {
        if ($this->ctm->isAxisAligned()) {
            $r = $this->ctm->mapAxisRect($x, $y, $w, $h);
            // clearRect is not affected by the clip region, globalAlpha or
            // the composite operation.
            $this->surface->clearRectDevice($r['x'], $r['y'], $r['w'], $r['h']);
            return;
        }
        $poly = [];
        foreach ([[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]] as $p) {
            $d = $this->ctm->mapPoint($p[0], $p[1]);
            $poly[] = $d['x'];
            $poly[] = $d['y'];
        }
        $this->surface->clearPolygons([$poly], Raster::NONZERO);
    }

    /**
     * @param string|Gradient|Pattern $style
     */
    private function resolvePaint(string|Gradient|Pattern $style): ?Paint
    {
        if ($style instanceof Gradient) {
            $inv = $this->ctm->inverse();
            return $inv === null ? null : Paint::fromGradient($style, $inv);
        }
        if ($style instanceof Pattern) {
            $inv = $this->ctm->inverse();
            return $inv === null ? null : Paint::fromPattern($style, $inv);
        }
        $c = ColorParser::parseNormalized($style);
        return Paint::solid($c[0], $c[1], $c[2], $c[3]);
    }

    public function clip(string $rule = Raster::NONZERO): void
    {
        $rule = $rule === Raster::EVENODD ? Raster::EVENODD : Raster::NONZERO;
        $polygons = [];
        foreach ($this->path->flatten($this->ctm) as $p) {
            $polygons[] = $p['pts'];
        }
        // A clip with an empty path clips everything away.
        $this->surface->pushClip($polygons, $rule);
    }

    // -----------------------------------------------------------------
    // Dashes
    // -----------------------------------------------------------------

    /** @return list<float> */
    public function getLineDash(): array
    {
        return $this->lineDash;
    }

    /** @param array<int,mixed> $segments */
    public function setLineDash(array $segments): void
    {
        $clean = [];
        foreach ($segments as $s) {
            if (!is_numeric($s) || $s < 0.0 || !is_finite((float) $s)) {
                return; // invalid values leave the current pattern alone
            }
            $clean[] = (float) $s;
        }
        $this->lineDash = $clean;
    }

    // -----------------------------------------------------------------
    // Gradients and patterns
    // -----------------------------------------------------------------

    public function createLinearGradient(float $x0, float $y0, float $x1, float $y1): Gradient
    {
        foreach ([$x0, $y0, $x1, $y1] as $v) {
            if (!is_finite($v)) {
                throw new \TypeError('createLinearGradient requires finite coordinates');
            }
        }
        return new Gradient('linear', $x0, $y0, $x1, $y1);
    }

    public function createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1): Gradient
    {
        foreach ([$x0, $y0, $r0, $x1, $y1, $r1] as $v) {
            if (!is_finite($v)) {
                throw new \TypeError('createRadialGradient requires finite coordinates');
            }
        }
        if ($r0 < 0.0 || $r1 < 0.0) {
            throw new \InvalidArgumentException('createRadialGradient requires non-negative radii');
        }
        return new Gradient('radial', $x0, $y0, $x1, $y1, $r0, $r1);
    }

    public function createPattern(mixed $image, string $repetition): ?Pattern
    {
        if (!\in_array($repetition, [Pattern::REPEAT, Pattern::REPEAT_X, Pattern::REPEAT_Y, Pattern::NO_REPEAT], true)) {
            return null;
        }
        $surface = self::toSurface($image);
        if ($surface === null || $surface->width <= 0 || $surface->height <= 0) {
            return null;
        }
        return new Pattern($surface, $repetition);
    }

    // -----------------------------------------------------------------
    // Images
    // -----------------------------------------------------------------

    /**
     * drawImage() with the 3, 5 and 9 argument forms.
     */
    public function drawImage(mixed $image, float|int ...$args): void
    {
        $src = self::toSurface($image);
        if ($src === null) {
            throw new \TypeError('drawImage: unsupported image source');
        }
        $sw = $src->width;
        $sh = $src->height;
        $argc = count($args);
        if ($argc === 2) {
            [$dx, $dy] = $args;
            $sx = 0.0;
            $sy = 0.0;
            $srcW = (float) $sw;
            $srcH = (float) $sh;
            $dw = (float) $sw;
            $dh = (float) $sh;
        } elseif ($argc === 4) {
            [$dx, $dy, $dw, $dh] = $args;
            $sx = 0.0;
            $sy = 0.0;
            $srcW = (float) $sw;
            $srcH = (float) $sh;
        } elseif ($argc === 8) {
            [$sx, $sy, $srcW, $srcH, $dx, $dy, $dw, $dh] = $args;
        } else {
            throw new \ArgumentCountError('drawImage() expects 3, 5 or 9 arguments');
        }

        if ($sw <= 0 || $sh <= 0 || $srcW <= 0.0 || $srcH <= 0.0 || $dw == 0.0 || $dh == 0.0) {
            return;
        }

        // A source rectangle that runs off the image is cropped, with the
        // destination rectangle shrunk by the same proportion.
        if ($sx < 0.0) {
            $k = -$sx / $srcW;
            $dx += $k * $dw;
            $dw -= $k * $dw;
            $srcW += $sx;
            $sx = 0.0;
        }
        if ($sy < 0.0) {
            $k = -$sy / $srcH;
            $dy += $k * $dh;
            $dh -= $k * $dh;
            $srcH += $sy;
            $sy = 0.0;
        }
        if ($sx + $srcW > $sw) {
            $over = $sx + $srcW - $sw;
            $k = $over / $srcW;
            $dw -= $k * $dw;
            $srcW -= $over;
        }
        if ($sy + $srcH > $sh) {
            $over = $sy + $srcH - $sh;
            $k = $over / $srcH;
            $dh -= $k * $dh;
            $srcH -= $over;
        }
        if ($srcW <= 0.0 || $srcH <= 0.0 || $dw == 0.0 || $dh == 0.0) {
            return;
        }

        $matrix = $this->ctm
            ->translated((float) $dx, (float) $dy)
            ->scaled($dw / $srcW, $dh / $srcH)
            ->translated(-$sx, -$sy);

        $this->surface->blitAffine(
            $src,
            $matrix,
            $this->globalAlpha,
            $this->imageSmoothingEnabled,
            $this->globalCompositeOperation,
        );
    }

    /**
     * getImageData(): raw device pixels, unaffected by the CTM.
     *
     * @return array{width:int,height:int,data:string,rgba:string}
     */
    public function getImageData(float $sx, float $sy, float $sw, float $sh): array
    {
        $x = self::clampInt($sx);
        $y = self::clampInt($sy);
        $w = self::clampInt($sw);
        $h = self::clampInt($sh);
        if ($w <= 0 || $h <= 0) {
            throw new \InvalidArgumentException('getImageData() requires positive width and height');
        }
        $rect = $this->surface->readRect($x, $y, $w, $h);
        $data = $rect->toRgbaString();
        return ['width' => $w, 'height' => $h, 'data' => $data, 'rgba' => $data];
    }

    /**
     * putImageData(): replaces device pixels exactly; the CTM, alpha,
     * compositing and clipping all do not apply.
     *
     * @param array{width:int,height:int,data:string}|Surface $imageData
     */
    public function putImageData(array|Surface $imageData, float $dx, float $dy, float $cx = 0.0, float $cy = 0.0, ?float $cw = null, ?float $ch = null): void
    {
        $src = $imageData instanceof Surface
            ? $imageData
            : Surface::fromRgbaString((int) ($imageData['width'] ?? 0), (int) ($imageData['height'] ?? 0), (string) ($imageData['data'] ?? ''));
        if ($src->width <= 0 || $src->height <= 0) {
            throw new \InvalidArgumentException('putImageData() needs a non-empty image data object');
        }
        $this->surface->writeSurface($src, (int) floor($dx), (int) floor($dy));
    }

    /** @return array{width:int,height:int,data:string,rgba:string} */
    public function createImageData(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('createImageData() requires positive dimensions');
        }
        $s = new Surface($width, $height);
        $data = $s->toRgbaString();
        return ['width' => $width, 'height' => $height, 'data' => $data, 'rgba' => $data];
    }

    private static function clampInt(float $v): int
    {
        if (!is_finite($v)) {
            return 0;
        }
        return (int) $v;
    }

    // -----------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------

    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->drawText($text, $x, $y, $maxWidth, false);
    }

    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->drawText($text, $x, $y, $maxWidth, true);
    }

    private function drawText(string $text, float $x, float $y, ?float $maxWidth, bool $outline): void
    {
        if ($text === '') {
            return;
        }
        $paint = $this->resolvePaint($outline ? $this->strokeStyle : $this->fillStyle);
        if ($paint === null || $paint->isEmpty()) {
            return;
        }
        $resolved = Fonts::resolve($this->font);
        $file = $resolved['file'];
        if ($file === null) {
            return;
        }

        $deviceScale = $this->ctm->averageScale();
        if ($deviceScale <= 0.0 || !is_finite($deviceScale)) {
            return;
        }
        $deviceScale = max(0.02, min(128.0, $deviceScale));

        $glyphs = TextRaster::render(
            $text,
            $file,
            $resolved['size'] * $deviceScale,
            $outline ? max(1.0, $this->lineWidth * $deviceScale) : 0.0,
        );
        if ($glyphs === null) {
            return;
        }

        $metrics = Fonts::metrics($file, $resolved['size'], $text);
        $advance = $metrics['width'];
        $ascent = $metrics['ascent'];
        $descent = $metrics['descent'];

        $align = $this->textAlign;
        if ($align === 'start') {
            $align = $this->direction === 'rtl' ? 'right' : 'left';
        } elseif ($align === 'end') {
            $align = $this->direction === 'rtl' ? 'left' : 'right';
        }
        $ox = match ($align) {
            'center' => $x - $advance / 2,
            'right' => $x - $advance,
            default => $x,
        };

        $oy = match ($this->textBaseline) {
            'top' => $y + $ascent,
            'hanging' => $y + $ascent * 0.8,
            'middle' => $y + ($ascent - $descent) / 2,
            'bottom' => $y - $descent,
            'ideographic' => $y - $descent * 0.5,
            default => $y,
        };

        $factor = 1.0;
        if ($maxWidth !== null && $maxWidth > 0.0 && $advance > $maxWidth) {
            $factor = $maxWidth / $advance;
        }

        $matrix = $this->ctm
            ->translated($ox, $oy)
            ->scaled($factor, 1.0)
            ->scaled(1 / $deviceScale, 1 / $deviceScale)
            ->translated(-$glyphs['originX'], -$glyphs['originY']);

        $this->surface->blitMask(
            $glyphs['surface'],
            $matrix,
            $paint,
            $this->globalAlpha,
            $this->globalCompositeOperation,
        );
    }

    /**
     * measureText(): the advance width plus the ink box, in user space units.
     *
     * @return array{width:float,actualBoundingBoxLeft:float,actualBoundingBoxRight:float,
     *               fontBoundingBoxAscent:float,fontBoundingBoxDescent:float,
     *               actualBoundingBoxAscent:float,actualBoundingBoxDescent:float}
     */
    public function measureText(string $text): array
    {
        $resolved = Fonts::resolve($this->font);
        if ($resolved['file'] === null) {
            return [
                'width' => 0.0,
                'actualBoundingBoxLeft' => 0.0,
                'actualBoundingBoxRight' => 0.0,
                'fontBoundingBoxAscent' => 0.0,
                'fontBoundingBoxDescent' => 0.0,
                'actualBoundingBoxAscent' => 0.0,
                'actualBoundingBoxDescent' => 0.0,
            ];
        }
        $m = Fonts::metrics($resolved['file'], $resolved['size'], $text);
        return [
            'width' => $m['width'],
            'actualBoundingBoxLeft' => 0.0,
            'actualBoundingBoxRight' => $m['width'],
            'fontBoundingBoxAscent' => $m['ascent'],
            'fontBoundingBoxDescent' => $m['descent'],
            'actualBoundingBoxAscent' => $m['ascent'],
            'actualBoundingBoxDescent' => $m['descent'],
        ];
    }

    // -----------------------------------------------------------------
    // Hit testing
    // -----------------------------------------------------------------

    /**
     * isPointInPath(x, y), isPointInPath(x, y, rule) and the Path2D forms.
     *
     * As in the browser, the point is in canvas space and is therefore not
     * transformed by the CTM — the path is.
     */
    public function isPointInPath(mixed ...$args): bool
    {
        $path = $this->path;
        if (($args[0] ?? null) instanceof Path) {
            $path = array_shift($args);
        }
        $x = (float) ($args[0] ?? 0);
        $y = (float) ($args[1] ?? 0);
        $rule = (string) ($args[2] ?? Raster::NONZERO);
        $polygons = [];
        foreach ($path->flatten($this->ctm) as $p) {
            $polygons[] = $p['pts'];
        }
        return Raster::contains($polygons, $x, $y, $rule === Raster::EVENODD ? Raster::EVENODD : Raster::NONZERO);
    }

    public function isPointInStroke(mixed ...$args): bool
    {
        $path = $this->path;
        if (($args[0] ?? null) instanceof Path) {
            $path = array_shift($args);
        }
        $x = (float) ($args[0] ?? 0);
        $y = (float) ($args[1] ?? 0);
        if ($this->lineWidth <= 0.0) {
            return false;
        }
        $scale = $this->ctm->averageScale();
        $tolerance = $scale > 0.001 ? 0.12 / $scale : 0.12;
        $band = Stroker::build(
            $path->flatten(Matrix::identity(), $tolerance),
            $this->lineWidth,
            $this->lineCap,
            $this->lineJoin,
            $this->miterLimit,
            $this->lineDash,
            $this->lineDashOffset,
        );
        $polygons = [];
        foreach ($band as $poly) {
            $mapped = [];
            $n = intdiv(count($poly), 2);
            for ($i = 0; $i < $n; $i++) {
                $p = $this->ctm->mapPoint($poly[$i * 2], $poly[$i * 2 + 1]);
                $mapped[] = $p['x'];
                $mapped[] = $p['y'];
            }
            $polygons[] = $mapped;
        }
        return Raster::contains($polygons, $x, $y, Raster::NONZERO);
    }

    /**
     * Accept every image source the browser API would: a Canvas, a Surface, a
     * GD image, an Imagick instance, a file path or encoded bytes.
     */
    public static function toSurface(mixed $image): ?Surface
    {
        if ($image instanceof Surface) {
            return $image;
        }
        if ($image instanceof Canvas) {
            return $image->surface();
        }
        if ($image instanceof \GdImage) {
            return (new Codec\GdCodec())->fromGdImage($image);
        }
        if ($image instanceof \Imagick) {
            return (new Codec\GdCodec())->fromImagick($image);
        }
        if (\is_string($image)) {
            if (\strlen($image) < 4096 && \is_file($image) && \is_readable($image)) {
                $bytes = \file_get_contents($image);
                return $bytes === false ? null : (new Codec\GdCodec())->decode($bytes);
            }
            return (new Codec\GdCodec())->decode($image);
        }
        return null;
    }
}
