<?php declare(strict_types=1);

namespace phasync\Canvas\Backend;

use phasync\Canvas\ColorParser;
use phasync\Canvas\Gradient;
use phasync\Canvas\NotSupportedException;

/**
 * GD backend for Canvas.
 */
class Gd implements BackendInterface
{
    private \GdImage $image;
    private int $width;
    private int $height;

    private string $fillStyle = '#000000';
    private string $strokeStyle = '#000000';
    private ?Gradient $fillGradient = null;
    private ?Gradient $strokeGradient = null;
    private float $lineWidth = 1.0;
    private string $lineCap = 'butt';
    private string $lineJoin = 'miter';
    private float $globalAlpha = 1.0;
    private string $font = '16px sans-serif';
    private string $textAlign = 'start';
    private string $textBaseline = 'alphabetic';

    /** @var array<array{x: float, y: float}> */
    private array $currentPath = [];
    private bool $pathClosed = false;

    /** @var \SplStack<array> */
    private \SplStack $stateStack;

    /** @var array{a: float, b: float, c: float, d: float, e: float, f: float} */
    private array $transform = ['a' => 1, 'b' => 0, 'c' => 0, 'd' => 1, 'e' => 0, 'f' => 0];

    /** @var ?\GdImage */
    private ?\GdImage $clipMask = null;

    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
        $this->image = \imagecreatetruecolor($width, $height);
        if ($this->image === false) {
            throw new \RuntimeException('Failed to create GD image');
        }

        // Initialize with white background and alpha support
        \imagesavealpha($this->image, true);
        $transparent = \imagecolorallocatealpha($this->image, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new \RuntimeException('Failed to allocate color');
        }
        \imagefill($this->image, 0, 0, $transparent);

        $this->stateStack = new \SplStack();
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
        $this->stateStack->push([
            'fillStyle' => $this->fillStyle,
            'strokeStyle' => $this->strokeStyle,
            'fillGradient' => $this->fillGradient,
            'strokeGradient' => $this->strokeGradient,
            'lineWidth' => $this->lineWidth,
            'lineCap' => $this->lineCap,
            'lineJoin' => $this->lineJoin,
            'globalAlpha' => $this->globalAlpha,
            'font' => $this->font,
            'textAlign' => $this->textAlign,
            'textBaseline' => $this->textBaseline,
            'transform' => $this->transform,
            'clipMask' => $this->clipMask,
        ]);
    }

    public function restore(): void
    {
        if ($this->stateStack->isEmpty()) {
            return;
        }

        $state = $this->stateStack->pop();
        $this->fillStyle = $state['fillStyle'];
        $this->strokeStyle = $state['strokeStyle'];
        $this->fillGradient = $state['fillGradient'];
        $this->strokeGradient = $state['strokeGradient'];
        $this->lineWidth = $state['lineWidth'];
        $this->lineCap = $state['lineCap'];
        $this->lineJoin = $state['lineJoin'];
        $this->globalAlpha = $state['globalAlpha'];
        $this->font = $state['font'];
        $this->textAlign = $state['textAlign'];
        $this->textBaseline = $state['textBaseline'];
        $this->transform = $state['transform'];
        $this->clipMask = $state['clipMask'];
    }

    public function setFillStyle(string $color): void
    {
        ColorParser::validate($color);
        $this->fillStyle = $color;
        $this->fillGradient = null;
    }

    public function setStrokeStyle(string $color): void
    {
        ColorParser::validate($color);
        $this->strokeStyle = $color;
        $this->strokeGradient = null;
    }

    public function setFillStyleGradient(Gradient $gradient): void
    {
        $this->fillGradient = $gradient;
    }

    public function setStrokeStyleGradient(Gradient $gradient): void
    {
        $this->strokeGradient = $gradient;
    }

    public function setLineWidth(float $width): void
    {
        $this->lineWidth = \max(0, $width);
    }

    public function setLineCap(string $cap): void
    {
        if (!\in_array($cap, ['butt', 'round', 'square'], true)) {
            throw new \InvalidArgumentException("Invalid lineCap: $cap");
        }
        $this->lineCap = $cap;
    }

    public function setLineJoin(string $join): void
    {
        if (!\in_array($join, ['bevel', 'round', 'miter'], true)) {
            throw new \InvalidArgumentException("Invalid lineJoin: $join");
        }
        $this->lineJoin = $join;
    }

    public function setGlobalAlpha(float $alpha): void
    {
        $this->globalAlpha = \max(0, \min(1, $alpha));
    }

    public function setFont(string $font): void
    {
        $this->font = $font;
    }

    public function setTextAlign(string $align): void
    {
        if (!\in_array($align, ['start', 'center', 'end'], true)) {
            throw new \InvalidArgumentException("Invalid textAlign: $align");
        }
        $this->textAlign = $align;
    }

    public function setTextBaseline(string $baseline): void
    {
        if (!\in_array($baseline, ['top', 'hanging', 'middle', 'alphabetic', 'ideographic', 'bottom'], true)) {
            throw new \InvalidArgumentException("Invalid textBaseline: $baseline");
        }
        $this->textBaseline = $baseline;
    }

    public function beginPath(): void
    {
        $this->currentPath = [];
        $this->pathClosed = false;
    }

    public function closePath(): void
    {
        $this->pathClosed = true;
    }

    public function moveTo(float $x, float $y): void
    {
        // Marker for move operation - not explicitly drawn
        $this->currentPath[] = ['x' => $x, 'y' => $y, 'move' => true];
    }

    public function lineTo(float $x, float $y): void
    {
        $this->currentPath[] = ['x' => $x, 'y' => $y];
    }

    public function arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->arcSegment($x, $y, $radius, $radius, 0, $startAngle, $endAngle, $counterclockwise);
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        // Get the last point in path
        if (empty($this->currentPath)) {
            $this->currentPath[] = ['x' => $x1, 'y' => $y1];
            return;
        }

        $lastPoint = end($this->currentPath);
        $x0 = $lastPoint['x'];
        $y0 = $lastPoint['y'];

        // Vector from p0 to p1 and from p1 to p2
        $dx1 = $x1 - $x0;
        $dy1 = $y1 - $y0;
        $dx2 = $x2 - $x1;
        $dy2 = $y2 - $y1;

        $len1 = \sqrt($dx1 * $dx1 + $dy1 * $dy1);
        $len2 = \sqrt($dx2 * $dx2 + $dy2 * $dy2);

        if ($len1 < 1e-10 || $len2 < 1e-10) {
            $this->lineTo($x1, $y1);
            return;
        }

        // Normalized directions
        $dx1 /= $len1;
        $dy1 /= $len1;
        $dx2 /= $len2;
        $dy2 /= $len2;

        // Cross product to determine direction
        $cross = $dx1 * $dy2 - $dy1 * $dx2;
        $dot = $dx1 * $dx2 + $dy1 * $dy2;

        if (\abs($cross) < 1e-10) {
            $this->lineTo($x1, $y1);
            return;
        }

        // Compute arc center and angles
        $angle = \acos(\max(-1, \min(1, $dot)));
        $distance = $radius / \sin($angle / 2);

        // Center of arc
        $cx = $x1 - $dy1 * $distance;
        $cy = $y1 + $dx1 * $distance;

        $startAngle = \atan2($y0 - $cy, $x0 - $cx);
        $endAngle = \atan2($y2 - $cy, $x2 - $cx);

        $this->arcSegment($cx, $cy, $radius, $radius, 0, $startAngle, $endAngle, $cross < 0);
    }

    public function ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->arcSegment($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $counterclockwise);
    }

    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): void
    {
        if (empty($this->currentPath)) {
            $this->currentPath[] = ['x' => 0, 'y' => 0];
        }

        $lastPoint = end($this->currentPath);
        $x0 = $lastPoint['x'];
        $y0 = $lastPoint['y'];

        // Flatten the cubic Bézier curve into line segments
        $steps = (int)\ceil(\max(\abs($x - $x0), \abs($y - $y0), \abs($cp1x - $x0), \abs($cp1y - $y0), \abs($cp2x - $x0), \abs($cp2y - $y0)));
        $steps = \max(1, \min($steps, 100)); // Limit steps

        for ($i = 1; $i <= $steps; $i++) {
            $t = $i / $steps;
            $mt = 1 - $t;

            $px = $mt * $mt * $mt * $x0
                + 3 * $mt * $mt * $t * $cp1x
                + 3 * $mt * $t * $t * $cp2x
                + $t * $t * $t * $x;

            $py = $mt * $mt * $mt * $y0
                + 3 * $mt * $mt * $t * $cp1y
                + 3 * $mt * $t * $t * $cp2y
                + $t * $t * $t * $y;

            $this->lineTo($px, $py);
        }
    }

    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): void
    {
        if (empty($this->currentPath)) {
            $this->currentPath[] = ['x' => 0, 'y' => 0];
        }

        $lastPoint = end($this->currentPath);
        $x0 = $lastPoint['x'];
        $y0 = $lastPoint['y'];

        // Flatten the quadratic Bézier curve
        $steps = (int)\ceil(\max(\abs($x - $x0), \abs($y - $y0), \abs($cpx - $x0), \abs($cpy - $y0)));
        $steps = \max(1, \min($steps, 100));

        for ($i = 1; $i <= $steps; $i++) {
            $t = $i / $steps;
            $mt = 1 - $t;

            $px = $mt * $mt * $x0 + 2 * $mt * $t * $cpx + $t * $t * $x;
            $py = $mt * $mt * $y0 + 2 * $mt * $t * $cpy + $t * $t * $y;

            $this->lineTo($px, $py);
        }
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->currentPath[] = ['x' => $x, 'y' => $y, 'move' => true];
        $this->currentPath[] = ['x' => $x + $w, 'y' => $y];
        $this->currentPath[] = ['x' => $x + $w, 'y' => $y + $h];
        $this->currentPath[] = ['x' => $x, 'y' => $y + $h];
        $this->pathClosed = true;
    }

    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii): void
    {
        if (\is_array($radii)) {
            $r = $radii[0] ?? 0;
        } else {
            $r = $radii;
        }

        $r = \min($r, \min($w, $h) / 2);

        $this->currentPath[] = ['x' => $x + $r, 'y' => $y, 'move' => true];
        $this->currentPath[] = ['x' => $x + $w - $r, 'y' => $y];

        // Top-right corner
        $this->arcSegment($x + $w - $r, $y + $r, $r, $r, 0, -\M_PI / 2, 0, false);

        $this->currentPath[] = ['x' => $x + $w, 'y' => $y + $h - $r];

        // Bottom-right corner
        $this->arcSegment($x + $w - $r, $y + $h - $r, $r, $r, 0, 0, \M_PI / 2, false);

        $this->currentPath[] = ['x' => $x + $r, 'y' => $y + $h];

        // Bottom-left corner
        $this->arcSegment($x + $r, $y + $h - $r, $r, $r, 0, \M_PI / 2, \M_PI, false);

        $this->currentPath[] = ['x' => $x, 'y' => $y + $r];

        // Top-left corner
        $this->arcSegment($x + $r, $y + $r, $r, $r, 0, \M_PI, \M_PI + \M_PI / 2, false);

        $this->pathClosed = true;
    }

    public function fill(): void
    {
        if (empty($this->currentPath)) {
            return;
        }

        $points = $this->flattenPath($this->currentPath);
        if (\count($points) < 3) {
            return;
        }

        $gdPoints = $this->transformPoints($points);

        if ($this->fillGradient) {
            $this->fillWithGradient($gdPoints, $this->fillGradient);
        } else {
            $color = $this->allocateColor($this->fillStyle, $this->globalAlpha);
            \imagefilledpolygon($this->image, $gdPoints, $color);
        }
    }

    public function stroke(): void
    {
        if (empty($this->currentPath)) {
            return;
        }

        $points = $this->flattenPath($this->currentPath, false);
        if (\count($points) < 2) {
            return;
        }

        $this->drawPolyline($points, $this->strokeStyle, $this->lineWidth, $this->globalAlpha);
    }

    public function fillRect(float $x, float $y, float $w, float $h): void
    {
        $points = [
            ['x' => $x, 'y' => $y],
            ['x' => $x + $w, 'y' => $y],
            ['x' => $x + $w, 'y' => $y + $h],
            ['x' => $x, 'y' => $y + $h],
        ];

        $gdPoints = $this->transformPoints($points);

        if ($this->fillGradient) {
            $this->fillWithGradient($gdPoints, $this->fillGradient);
        } else {
            $color = $this->allocateColor($this->fillStyle, $this->globalAlpha);
            \imagefilledpolygon($this->image, $gdPoints, $color);
        }
    }

    public function strokeRect(float $x, float $y, float $w, float $h): void
    {
        $points = [
            ['x' => $x, 'y' => $y],
            ['x' => $x + $w, 'y' => $y],
            ['x' => $x + $w, 'y' => $y + $h],
            ['x' => $x, 'y' => $y + $h],
            ['x' => $x, 'y' => $y],
        ];

        $this->drawPolyline($points, $this->strokeStyle, $this->lineWidth, $this->globalAlpha);
    }

    public function clearRect(float $x, float $y, float $w, float $h): void
    {
        // Transform the rectangle corners
        $points = [
            ['x' => $x, 'y' => $y],
            ['x' => $x + $w, 'y' => $y],
            ['x' => $x + $w, 'y' => $y + $h],
            ['x' => $x, 'y' => $y + $h],
        ];
        $gdPoints = $this->transformPoints($points);

        // For axis-aligned transforms, convert to rectangle bounds
        // Otherwise, use polygon clearing
        $xs = [];
        $ys = [];
        for ($i = 0; $i < \count($gdPoints); $i += 2) {
            $xs[] = $gdPoints[$i];
            $ys[] = $gdPoints[$i + 1];
        }

        $x1 = (int)\min(...$xs);
        $y1 = (int)\min(...$ys);
        $x2 = (int)\max(...$xs);
        $y2 = (int)\max(...$ys);

        // Disable alpha blending so transparent fill works
        \imagealphablending($this->image, false);

        // Allocate transparent color
        $transparent = \imagecolorallocatealpha($this->image, 0, 0, 0, 127);
        if ($transparent === false) {
            \imagealphablending($this->image, true);
            return;
        }

        for ($py = $y1; $py <= $y2; $py++) {
            for ($px = $x1; $px <= $x2; $px++) {
                if ($px >= 0 && $px < $this->width && $py >= 0 && $py < $this->height) {
                    \imagesetpixel($this->image, $px, $py, $transparent);
                }
            }
        }

        // Re-enable alpha blending
        \imagealphablending($this->image, true);
    }

    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        // Simplified text rendering using built-in GD fonts
        $fontSize = $this->parseFontSize($this->font);
        $fontFile = $this->parseFontFile($this->font);

        if (!$fontFile || !\file_exists($fontFile)) {
            // Use built-in font
            $color = $this->allocateColor($this->fillStyle, $this->globalAlpha);
            \imagestring($this->image, 1, (int)$x, (int)$y, $text, $color);
        } else {
            // Use TTF font
            $color = $this->allocateColor($this->fillStyle, $this->globalAlpha);
            $angle = 0;
            \imagettftext($this->image, $fontSize, $angle, (int)$x, (int)$y, $color, $fontFile, $text);
        }
    }

    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        // Same as fillText for simplicity
        $this->fillText($text, $x, $y, $maxWidth);
    }

    public function measureText(string $text): array
    {
        $fontFile = $this->parseFontFile($this->font);
        $fontSize = $this->parseFontSize($this->font);

        if (!$fontFile || !\file_exists($fontFile)) {
            // Approximate based on text length
            return ['width' => \strlen($text) * 8];
        }

        // Get TTF text bounds
        $bbox = \imagettfbbox($fontSize, 0, $fontFile, $text);
        if ($bbox === false) {
            return ['width' => \strlen($text) * 8];
        }

        $width = $bbox[2] - $bbox[0];
        return ['width' => (float)$width];
    }

    public function drawImage($source, ...$args): void
    {
        // Get the image to draw
        if ($source instanceof \GdImage) {
            $sourceImage = $source;
            $sourceWidth = \imagesx($sourceImage);
            $sourceHeight = \imagesy($sourceImage);
        } elseif ($source instanceof \Imagick) {
            $sourceImage = $this->imagickToGd($source);
            $sourceWidth = (int)$source->getImageWidth();
            $sourceHeight = (int)$source->getImageHeight();
        } elseif (\is_string($source)) {
            // File path or image bytes
            if (\strlen($source) < 256 && \file_exists($source)) {
                $content = \file_get_contents($source);
                if ($content === false) {
                    return;
                }
                $sourceImage = \imagecreatefromstring($content);
                if ($sourceImage === false) {
                    return;
                }
            } else {
                // Assume image bytes
                $sourceImage = \imagecreatefromstring($source);
                if ($sourceImage === false) {
                    return;
                }
            }
            $sourceWidth = \imagesx($sourceImage);
            $sourceHeight = \imagesy($sourceImage);
        } else {
            return;
        }

        // Handle different argument counts
        $dx = 0;
        $dy = 0;
        $dw = $sourceWidth;
        $dh = $sourceHeight;
        $sx = 0;
        $sy = 0;
        $sw = $sourceWidth;
        $sh = $sourceHeight;

        if (\count($args) === 2) {
            [$dx, $dy] = $args;
        } elseif (\count($args) === 4) {
            [$dx, $dy, $dw, $dh] = $args;
        } elseif (\count($args) === 8) {
            [$sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh] = $args;
        }

        // Transform the destination rectangle
        $destPoints = [
            ['x' => $dx, 'y' => $dy],
            ['x' => $dx + $dw, 'y' => $dy],
            ['x' => $dx + $dw, 'y' => $dy + $dh],
            ['x' => $dx, 'y' => $dy + $dh],
        ];
        $gdDestPoints = $this->transformPoints($destPoints);

        // For axis-aligned transforms, use imagecopyresampled directly
        // For non-axis-aligned transforms, we would need more complex handling
        // For now, extract the bounding box and destination position
        $destXs = [];
        $destYs = [];
        for ($i = 0; $i < \count($gdDestPoints); $i += 2) {
            $destXs[] = $gdDestPoints[$i];
            $destYs[] = $gdDestPoints[$i + 1];
        }

        $destX = (int)\min(...$destXs);
        $destY = (int)\min(...$destYs);
        $destMaxX = (int)\max(...$destXs);
        $destMaxY = (int)\max(...$destYs);
        $destWidth = $destMaxX - $destX;
        $destHeight = $destMaxY - $destY;

        // Copy the image with resampling to preserve alpha
        \imagecopyresampled(
            $this->image,
            $sourceImage,
            $destX,
            $destY,
            (int)$sx,
            (int)$sy,
            $destWidth,
            $destHeight,
            (int)$sw,
            (int)$sh
        );
    }

    public function getImageData(float $x, float $y, float $w, float $h): array
    {
        $x = \max(0, \min((int)$x, $this->width - 1));
        $y = \max(0, \min((int)$y, $this->height - 1));
        $w = \min((int)$w, $this->width - $x);
        $h = \min((int)$h, $this->height - $y);

        if ($w <= 0 || $h <= 0) {
            return ['width' => 0, 'height' => 0, 'data' => ''];
        }

        $rgba = '';
        for ($row = $y; $row < $y + $h; $row++) {
            for ($col = $x; $col < $x + $w; $col++) {
                $pixelIndex = \imagecolorat($this->image, $col, $row);
                if ($pixelIndex === false) {
                    $rgba .= "\x00\x00\x00\xff";
                    continue;
                }

                $rgba .= \chr(($pixelIndex >> 16) & 0xFF);
                $rgba .= \chr(($pixelIndex >> 8) & 0xFF);
                $rgba .= \chr($pixelIndex & 0xFF);
                $rgba .= \chr(($pixelIndex >> 24) & 0xFF);
            }
        }

        return ['width' => $w, 'height' => $h, 'data' => $rgba];
    }

    public function putImageData(array $imageData, float $x, float $y): void
    {
        $width = $imageData['width'] ?? 0;
        $height = $imageData['height'] ?? 0;
        $data = $imageData['data'] ?? '';

        if ($width <= 0 || $height <= 0 || empty($data)) {
            return;
        }

        $x = (int)$x;
        $y = (int)$y;

        $offset = 0;
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                if ($offset + 4 > \strlen($data)) {
                    break 2;
                }

                $r = \ord($data[$offset++]);
                $g = \ord($data[$offset++]);
                $b = \ord($data[$offset++]);
                $a = \ord($data[$offset++]);

                $px = $x + $col;
                $py = $y + $row;

                if ($px >= 0 && $px < $this->width && $py >= 0 && $py < $this->height) {
                    $color = \imagecolorallocatealpha($this->image, $r, $g, $b, (int)(127 * (1 - $a / 255)));
                    if ($color !== false) {
                        \imagesetpixel($this->image, $px, $py, $color);
                    }
                }
            }
        }
    }

    public function translate(float $x, float $y): void
    {
        $t = $this->transform;
        $this->transform = [
            'a' => $t['a'],
            'b' => $t['b'],
            'c' => $t['c'],
            'd' => $t['d'],
            'e' => $t['e'] + $x * $t['a'] + $y * $t['c'],
            'f' => $t['f'] + $x * $t['b'] + $y * $t['d'],
        ];
    }

    public function scale(float $x, float $y): void
    {
        $t = $this->transform;
        $this->transform = [
            'a' => $t['a'] * $x,
            'b' => $t['b'] * $x,
            'c' => $t['c'] * $y,
            'd' => $t['d'] * $y,
            'e' => $t['e'],
            'f' => $t['f'],
        ];
    }

    public function rotate(float $angle): void
    {
        $cos = \cos($angle);
        $sin = \sin($angle);

        $t = $this->transform;
        $this->transform = [
            'a' => $t['a'] * $cos + $t['c'] * $sin,
            'b' => $t['b'] * $cos + $t['d'] * $sin,
            'c' => -$t['a'] * $sin + $t['c'] * $cos,
            'd' => -$t['b'] * $sin + $t['d'] * $cos,
            'e' => $t['e'],
            'f' => $t['f'],
        ];
    }

    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $t = $this->transform;
        $this->transform = [
            'a' => $t['a'] * $a + $t['c'] * $b,
            'b' => $t['b'] * $a + $t['d'] * $b,
            'c' => $t['a'] * $c + $t['c'] * $d,
            'd' => $t['b'] * $c + $t['d'] * $d,
            'e' => $t['a'] * $e + $t['c'] * $f + $t['e'],
            'f' => $t['b'] * $e + $t['d'] * $f + $t['f'],
        ];
    }

    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->transform = ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e, 'f' => $f];
    }

    public function resetTransform(): void
    {
        $this->transform = ['a' => 1, 'b' => 0, 'c' => 0, 'd' => 1, 'e' => 0, 'f' => 0];
    }

    public function clip(): void
    {
        if (empty($this->currentPath)) {
            return;
        }

        // Create a temporary layer with the clipped region
        if (!$this->clipMask) {
            $this->clipMask = \imagecreatetruecolor($this->width, $this->height);
            if ($this->clipMask === false) {
                return;
            }
            \imagesavealpha($this->clipMask, true);
            $transparent = \imagecolorallocatealpha($this->clipMask, 0, 0, 0, 127);
            if ($transparent !== false) {
                \imagefill($this->clipMask, 0, 0, $transparent);
            }
        }

        $points = $this->flattenPath($this->currentPath);
        if (\count($points) >= 3) {
            $gdPoints = $this->transformPoints($points);
            $white = \imagecolorallocate($this->clipMask, 255, 255, 255);
            if ($white !== false) {
                \imagefilledpolygon($this->clipMask, $gdPoints, $white);
            }
        }
    }

    public function createLinearGradient(float $x0, float $y0, float $x1, float $y1): Gradient
    {
        return new Gradient('linear', $x0, $y0, $x1, $y1);
    }

    public function createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1): Gradient
    {
        return new Gradient('radial', $x0, $y0, $x1, $y1, $r0, $r1);
    }

    public function toPng(): string
    {
        \ob_start();
        \imagepng($this->image);
        $png = \ob_get_clean();
        return $png ?: '';
    }

    public function toJpeg(int $quality): string
    {
        \ob_start();
        \imagejpeg($this->image, null, $quality);
        $jpeg = \ob_get_clean();
        return $jpeg ?: '';
    }

    public function toGd(): \GdImage
    {
        return $this->image;
    }

    public function toImagick(): \Imagick
    {
        throw new NotSupportedException('Imagick not available for GD backend');
    }

    public function toPixels(): array
    {
        // Use imagebmp() as a reliable fallback (PHP >= 7.2)
        // BMP format: 24-bit BGR rows, bottom-up, 4-byte row padding
        \ob_start();
        \imagebmp($this->image);
        $bmpData = \ob_get_clean();
        if ($bmpData === false) {
            throw new \RuntimeException('Failed to generate BMP data');
        }

        // Parse BMP header and extract RGB data
        // BMP structure: 14-byte file header + 40-byte DIB header + pixel data
        if (\strlen($bmpData) < 54) {
            throw new \RuntimeException('Invalid BMP data');
        }

        // Get offset to pixel data from byte 10-13 (little-endian)
        $pixelOffset = \unpack('V', \substr($bmpData, 10, 4))[1];

        // Extract RGB pixel data (BGR in BMP, convert to RGB)
        $rgba = '';
        $pixelData = \substr($bmpData, $pixelOffset);

        // BMP rows are bottom-up, so we need to read from the end
        $rowBytes = (int)(($this->width * 3 + 3) / 4) * 4; // 4-byte aligned
        $totalRows = \strlen($pixelData) / $rowBytes;

        for ($y = 0; $y < $this->height; $y++) {
            // Read from bottom to top
            $rowIndex = $this->height - 1 - $y;
            $rowStart = $rowIndex * $rowBytes;

            for ($x = 0; $x < $this->width; $x++) {
                $pixelPos = $rowStart + $x * 3;

                if ($pixelPos + 2 < \strlen($pixelData)) {
                    $b = \ord($pixelData[$pixelPos]);
                    $g = \ord($pixelData[$pixelPos + 1]);
                    $r = \ord($pixelData[$pixelPos + 2]);
                } else {
                    $r = $g = $b = 0;
                }

                // Add RGB + full opacity
                $rgba .= \chr($r) . \chr($g) . \chr($b) . "\xff";
            }
        }

        return ['width' => $this->width, 'height' => $this->height, 'rgba' => $rgba];
    }

    public function toDots(int $w, int $h, ?string $background = null): array
    {
        // Use source image directly if size matches, avoid imagescale segfault bug
        if ($w === $this->width && $h === $this->height) {
            $sourceImage = $this->image;
        } else {
            // Scale the canvas down to the target resolution with exact downsampling
            $sourceImage = $this->downsampleImage($w, $h);
        }

        $on = '';
        $rgb = '';

        // Parse background color if provided
        $bgR = 0;
        $bgG = 0;
        $bgB = 0;
        if ($background !== null) {
            $bgColor = ColorParser::parseColor($background);
            $bgR = $bgColor['r'];
            $bgG = $bgColor['g'];
            $bgB = $bgColor['b'];
        }

        // Read each pixel from the (possibly scaled) image
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $pixel = \imagecolorat($sourceImage, $x, $y);
                if ($pixel === false) {
                    $on .= "\x00";
                    $rgb .= "\x00\x00\x00";
                    continue;
                }

                // Extract RGBA from pixel
                $r = ($pixel >> 16) & 0xFF;
                $g = ($pixel >> 8) & 0xFF;
                $b = $pixel & 0xFF;
                $a = ($pixel >> 24) & 0xFF;

                // Determine if dot is "on"
                if ($background !== null) {
                    // If background provided, check if pixel differs from background color
                    $isDifferent = ($r !== $bgR) || ($g !== $bgG) || ($b !== $bgB);
                    $on .= $isDifferent ? "\x01" : "\x00";
                } else {
                    // Otherwise, check if pixel is not fully transparent (alpha < 127 in GD)
                    $isDifferent = $a < 127;
                    $on .= $isDifferent ? "\x01" : "\x00";
                }

                $rgb .= \chr($r) . \chr($g) . \chr($b);
            }
        }

        return ['width' => $w, 'height' => $h, 'on' => $on, 'rgb' => $rgb];
    }

    /**
     * Downsample an image using coverage-based logic.
     * Each output dot is set when coverage >= 50% of its block are non-transparent,
     * and the color is the average of opaque pixels in the block.
     */
    private function downsampleImage(int $targetW, int $targetH): \GdImage
    {
        $result = \imagecreatetruecolor($targetW, $targetH);
        if ($result === false) {
            throw new \RuntimeException('Failed to create downsampled image');
        }

        \imagesavealpha($result, true);
        $transparent = \imagecolorallocatealpha($result, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new \RuntimeException('Failed to allocate color');
        }
        \imagefill($result, 0, 0, $transparent);

        // Calculate block size
        $blockW = $this->width / $targetW;
        $blockH = $this->height / $targetH;

        for ($ty = 0; $ty < $targetH; $ty++) {
            for ($tx = 0; $tx < $targetW; $tx++) {
                // Calculate source block boundaries
                $srcX1 = (int)($tx * $blockW);
                $srcY1 = (int)($ty * $blockH);
                $srcX2 = (int)(($tx + 1) * $blockW);
                $srcY2 = (int)(($ty + 1) * $blockH);

                // Sample pixels in this block
                $totalR = 0;
                $totalG = 0;
                $totalB = 0;
                $opaqueCount = 0;
                $nonTransparentCount = 0;

                for ($y = $srcY1; $y < $srcY2; $y++) {
                    for ($x = $srcX1; $x < $srcX2; $x++) {
                        $pixel = \imagecolorat($this->image, $x, $y);
                        if ($pixel === false) {
                            continue;
                        }

                        $a = ($pixel >> 24) & 0xFF;

                        // Count non-transparent pixels
                        if ($a < 127) {
                            $nonTransparentCount++;
                            $r = ($pixel >> 16) & 0xFF;
                            $g = ($pixel >> 8) & 0xFF;
                            $b = $pixel & 0xFF;

                            $totalR += $r;
                            $totalG += $g;
                            $totalB += $b;
                            $opaqueCount++;
                        }
                    }
                }

                $blockSize = ($srcX2 - $srcX1) * ($srcY2 - $srcY1);
                $coverage = $blockSize > 0 ? $nonTransparentCount / $blockSize : 0;

                if ($coverage >= 0.5 && $opaqueCount > 0) {
                    // Block is "on" - compute average color
                    $avgR = (int)($totalR / $opaqueCount);
                    $avgG = (int)($totalG / $opaqueCount);
                    $avgB = (int)($totalB / $opaqueCount);

                    $color = \imagecolorallocatealpha($result, $avgR, $avgG, $avgB, 0);
                    if ($color !== false) {
                        \imagesetpixel($result, $tx, $ty, $color);
                    }
                }
                // else: leave as transparent (already filled above)
            }
        }

        return $result;
    }

    public function fromGd(\GdImage $image): void
    {
        $width = \imagesx($image);
        $height = \imagesy($image);

        \imagecopy($this->image, $image, 0, 0, 0, 0, $width, $height);
    }

    public function fromImagick(\Imagick $imagick): void
    {
        $gdImage = $this->imagickToGd($imagick);
        $this->fromGd($gdImage);
    }

    // Private helper methods

    /**
     * @param array<array{x: float, y: float, move?: bool}> $path
     */
    private function flattenPath(array $path, bool $closeIfNeeded = true): array
    {
        if (empty($path)) {
            return [];
        }

        $result = [];
        $movePoint = null;

        foreach ($path as $point) {
            if (isset($point['move'])) {
                // Save the move point but don't add it to result yet
                $movePoint = $point;
            } else {
                // On first line operation after a move, add the move point first
                if ($movePoint !== null && empty($result)) {
                    $result[] = $movePoint;
                }
                $result[] = $point;
                $movePoint = null;
            }
        }

        // Add starting point if path is closed
        if ($closeIfNeeded && !empty($result) && $this->pathClosed) {
            if ($result[0] !== end($result)) {
                $result[] = $result[0];
            }
        }

        return $result;
    }

    /**
     * @param array<array{x: float, y: float}> $points
     * @return array<int>
     */
    private function transformPoints(array $points): array
    {
        $gdPoints = [];
        foreach ($points as $point) {
            $x = $point['x'];
            $y = $point['y'];

            // Apply transformation matrix
            $tx = $this->transform['a'] * $x + $this->transform['c'] * $y + $this->transform['e'];
            $ty = $this->transform['b'] * $x + $this->transform['d'] * $y + $this->transform['f'];

            $gdPoints[] = (int)\round($tx);
            $gdPoints[] = (int)\round($ty);
        }

        return $gdPoints;
    }

    /**
     * Draw a polyline with the given stroke style.
     * @param array<array{x: float, y: float}> $points
     */
    private function drawPolyline(array $points, string $color, float $lineWidth, float $alpha): void
    {
        if (\count($points) < 2) {
            return;
        }

        $color = $this->allocateColor($color, $alpha);

        // Transform points first - returns flat array [x1, y1, x2, y2, ...]
        $transformedFlat = $this->transformPoints($points);

        // lineWidth is in user units; the transform's scale makes it pixels, never thinner than 1 pixel
        $t = $this->transform;
        $pixelWidth = $lineWidth * \sqrt(\abs($t['a'] * $t['d'] - $t['b'] * $t['c']));

        if ($pixelWidth <= 1) {
            for ($i = 0; $i < \count($transformedFlat) - 3; $i += 2) {
                $x1 = (int)\round($transformedFlat[$i]);
                $y1 = (int)\round($transformedFlat[$i + 1]);
                $x2 = (int)\round($transformedFlat[$i + 2]);
                $y2 = (int)\round($transformedFlat[$i + 3]);

                \imageline($this->image, $x1, $y1, $x2, $y2, $color);
            }
        } else {
            // Draw thick line as a polygon
            for ($i = 0; $i < \count($points) - 1; $i++) {
                $x1 = $points[$i]['x'];
                $y1 = $points[$i]['y'];
                $x2 = $points[$i + 1]['x'];
                $y2 = $points[$i + 1]['y'];

                $dx = $x2 - $x1;
                $dy = $y2 - $y1;
                $len = \sqrt($dx * $dx + $dy * $dy);

                if ($len < 1e-10) {
                    continue;
                }

                // Perpendicular vector
                $px = -$dy / $len * ($lineWidth / 2);
                $py = $dx / $len * ($lineWidth / 2);

                $rect = [
                    ['x' => $x1 + $px, 'y' => $y1 + $py],
                    ['x' => $x2 + $px, 'y' => $y2 + $py],
                    ['x' => $x2 - $px, 'y' => $y2 - $py],
                    ['x' => $x1 - $px, 'y' => $y1 - $py],
                ];

                $gdPoints = $this->transformPoints($rect);
                \imagefilledpolygon($this->image, $gdPoints, $color);
            }
        }
    }

    /**
     * Fill a polygon with a gradient.
     * @param array<int> $gdPoints
     */
    private function fillWithGradient(array $gdPoints, Gradient $gradient): void
    {
        if (\count($gdPoints) < 6) { // At least 2 points needed for polygon
            return;
        }

        // Create a temporary image for the gradient
        $gradientImg = \imagecreatetruecolor($this->width, $this->height);
        if ($gradientImg === false) {
            return;
        }

        // Generate gradient on temporary image
        if ($gradient->getType() === 'linear') {
            $this->drawLinearGradient($gradientImg, $gradient);
        } else {
            $this->drawRadialGradient($gradientImg, $gradient);
        }

        // Create a mask image for the path
        $maskImg = \imagecreatetruecolor($this->width, $this->height);
        if ($maskImg === false) {
            return;
        }

        // Fill mask with black
        $black = \imagecolorallocate($maskImg, 0, 0, 0);
        if ($black !== false) {
            \imagefill($maskImg, 0, 0, $black);
        }

        // Draw the path as white on the mask image
        $white = \imagecolorallocate($maskImg, 255, 255, 255);
        if ($white === false) {
            return;
        }
        \imagefilledpolygon($maskImg, $gdPoints, $white);

        // Now copy the gradient through the mask
        // For each row, copy gradient pixels where mask has white pixels
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                // Check if pixel is white in mask (part of path)
                $maskPixel = \imagecolorat($maskImg, $x, $y);
                if ($maskPixel === false) {
                    continue;
                }

                // Extract R value (should be 255 for white, less for other colors)
                $maskR = ($maskPixel >> 16) & 0xFF;

                // If mask is white (R == 255), copy gradient pixel
                if ($maskR === 255) {
                    $gradPixel = \imagecolorat($gradientImg, $x, $y);
                    if ($gradPixel !== false) {
                        \imagesetpixel($this->image, $x, $y, $gradPixel);
                    }
                }
            }
        }
    }

    /**
     * Draw a linear gradient on an image.
     */
    private function drawLinearGradient(\GdImage $target, Gradient $gradient): void
    {
        $x0 = $gradient->getX0();
        $y0 = $gradient->getY0();
        $x1 = $gradient->getX1();
        $y1 = $gradient->getY1();
        $stops = $gradient->getStops();

        if (empty($stops)) {
            return;
        }

        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $len = \sqrt($dx * $dx + $dy * $dy);

        if ($len < 1e-10) {
            return;
        }

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                // Project point onto gradient line
                $px = $x - $x0;
                $py = $y - $y0;

                $t = ($px * $dx + $py * $dy) / ($len * $len);
                $t = \max(0, \min(1, $t));

                // Find the color at this position
                $color = $this->getGradientColor($stops, $t);
                $gdColor = $this->allocateColor($color, 1.0);
                \imagesetpixel($target, $x, $y, $gdColor);
            }
        }
    }

    /**
     * Draw a radial gradient on an image.
     */
    private function drawRadialGradient(\GdImage $target, Gradient $gradient): void
    {
        $x0 = $gradient->getX0();
        $y0 = $gradient->getY0();
        $r0 = $gradient->getR0();
        $x1 = $gradient->getX1();
        $y1 = $gradient->getY1();
        $r1 = $gradient->getR1();
        $stops = $gradient->getStops();

        if (empty($stops)) {
            return;
        }

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                // Distance from starting circle
                $dx0 = $x - $x0;
                $dy0 = $y - $y0;
                $dist0 = \sqrt($dx0 * $dx0 + $dy0 * $dy0);

                // Distance from ending circle
                $dx1 = $x - $x1;
                $dy1 = $y - $y1;
                $dist1 = \sqrt($dx1 * $dx1 + $dy1 * $dy1);

                // Calculate gradient parameter
                $dr = $r1 - $r0;
                $t = 0;
                if ($dr !== 0) {
                    $t = ($dist0 - $r0) / $dr;
                } elseif ($dist1 !== 0) {
                    $t = $dist1 / \max(1, $r1);
                }

                $t = \max(0, \min(1, $t));

                // Find the color at this position
                $color = $this->getGradientColor($stops, $t);
                $gdColor = $this->allocateColor($color, 1.0);
                \imagesetpixel($target, $x, $y, $gdColor);
            }
        }
    }

    /**
     * Get the interpolated color at a position in the gradient.
     * @param array<array{offset: float, color: string}> $stops
     */
    private function getGradientColor(array $stops, float $t): string
    {
        if (empty($stops)) {
            return '#000000';
        }

        if ($t <= $stops[0]['offset']) {
            return $stops[0]['color'];
        }

        if ($t >= $stops[\count($stops) - 1]['offset']) {
            return $stops[\count($stops) - 1]['color'];
        }

        // Find the two stops surrounding this position
        for ($i = 0; $i < \count($stops) - 1; $i++) {
            $stop1 = $stops[$i];
            $stop2 = $stops[$i + 1];

            if ($t >= $stop1['offset'] && $t <= $stop2['offset']) {
                // Interpolate between the two colors
                $range = $stop2['offset'] - $stop1['offset'];
                $ratio = ($t - $stop1['offset']) / $range;

                return $this->interpolateColors($stop1['color'], $stop2['color'], $ratio);
            }
        }

        return $stops[\count($stops) - 1]['color'];
    }

    /**
     * Interpolate between two colors.
     */
    private function interpolateColors(string $color1, string $color2, float $ratio): string
    {
        $rgb1 = ColorParser::parseColor($color1);
        $rgb2 = ColorParser::parseColor($color2);

        $r = (int)($rgb1['r'] + ($rgb2['r'] - $rgb1['r']) * $ratio);
        $g = (int)($rgb1['g'] + ($rgb2['g'] - $rgb1['g']) * $ratio);
        $b = (int)($rgb1['b'] + ($rgb2['b'] - $rgb1['b']) * $ratio);

        return \sprintf('rgb(%d, %d, %d)', $r, $g, $b);
    }

    /**
     * Allocate a color on the image.
     */
    private function allocateColor(string $colorStr, float $alpha): int
    {
        $color = ColorParser::parseColor($colorStr);

        // Adjust alpha
        $alphaValue = (int)(127 * (1 - $color['a'] * $alpha));

        $gdColor = \imagecolorallocatealpha(
            $this->image,
            $color['r'],
            $color['g'],
            $color['b'],
            $alphaValue
        );

        return $gdColor === false ? 0 : $gdColor;
    }

    /**
     * Parse font size from font spec.
     */
    private function parseFontSize(string $font): int
    {
        if (\preg_match('/(\d+)px/', $font, $matches)) {
            return (int)$matches[1];
        }
        return 16;
    }

    /**
     * Parse font file path from font spec.
     */
    private function parseFontFile(string $font): ?string
    {
        // Check for file paths like "16px /path/to/font.ttf"
        if (\preg_match('/16px\s+(.+)\.ttf/i', $font, $matches)) {
            return $matches[1] . '.ttf';
        }
        return null;
    }

    /**
     * Add arc segment to the current path.
     */
    private function arcSegment(float $cx, float $cy, float $rx, float $ry, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise): void
    {
        // Flatten arc into line segments
        $angle = $startAngle;
        $step = \M_PI / 180; // 1 degree steps

        if ($counterclockwise) {
            $step = -$step;
            if ($endAngle > $startAngle) {
                $endAngle -= 2 * \M_PI;
            }
        } else {
            if ($endAngle < $startAngle) {
                $endAngle += 2 * \M_PI;
            }
        }

        $steps = (int)\ceil(\abs($endAngle - $startAngle) / $step);
        $actualStep = ($endAngle - $startAngle) / \max(1, $steps);

        for ($i = 0; $i <= $steps; $i++) {
            $a = $startAngle + $actualStep * $i;

            $x = $cx + $rx * \cos($a) * \cos($rotation) - $ry * \sin($a) * \sin($rotation);
            $y = $cy + $rx * \cos($a) * \sin($rotation) + $ry * \sin($a) * \cos($rotation);

            if ($i === 0) {
                $this->lineTo($x, $y);
            } else {
                $this->lineTo($x, $y);
            }
        }
    }

    /**
     * Convert Imagick instance to GD image.
     */
    private function imagickToGd(\Imagick $imagick): \GdImage
    {
        $blob = $imagick->getImageBlob();
        $tempFile = \tempnam(\sys_get_temp_dir(), 'canvas_');
        \file_put_contents($tempFile, $blob);
        $image = \imagecreatefromfile($tempFile);
        @\unlink($tempFile);

        if ($image === false) {
            throw new \RuntimeException('Failed to convert Imagick to GD');
        }

        return $image;
    }
}
