# phasync/canvas

The browser's 2D canvas API for PHP, backed by GD or Imagick.

## Installation

```bash
composer require phasync/canvas
```

Requires PHP 8.2+ and the `gd` extension. The `imagick` extension is optional for input (fromImagick), but requires `gd` for rendering.

## Quick Example

```php
<?php
use phasync\Canvas\Canvas;

// Create a 400x300 canvas
$canvas = new Canvas(400, 300);
$ctx = $canvas->getContext();

// Set up styles
$ctx->fillStyle = '#ffffff';
$ctx->fillRect(0, 0, 400, 300);

// Draw a chart-like shape
$ctx->fillStyle = 'rgb(255, 99, 71)';
$ctx->beginPath();
$ctx->moveTo(50, 200);
$ctx->lineTo(150, 100);
$ctx->lineTo(250, 150);
$ctx->lineTo(350, 50);
$ctx->stroke();

// Save as PNG
file_put_contents('chart.png', $canvas->toPng());
```

## API

### Canvas

- `new Canvas(int $width, int $height)` — Create a new canvas
- `Canvas::fromGd(GdImage $image)` — Create from a GD image
- `Canvas::fromImagick(Imagick $imagick)` — Create from an Imagick image
- `Canvas::fromFile(string $path)` — Load from file (PNG, JPEG, GIF, WebP)
- `Canvas::fromPng(string $bytes)` — Create from PNG bytes

Output methods:
- `toPng(): string` — Export as PNG
- `toJpeg(int $quality = 90): string` — Export as JPEG
- `toGd(): GdImage` — Get underlying GD image
- `toImagick(): Imagick` — Get underlying Imagick image (if available)
- `toPixels(): array{width:int, height:int, rgba:string}` — Get raw RGBA pixel data
- `toDots(int $w, int $h): array{width:int, height:int, on:string, rgb:string}` — Downscale for terminal rendering
- `resize(int $w, int $h): Canvas` — Create resized copy

### Context

Properties:
- `fillStyle` — Fill color (CSS color string)
- `strokeStyle` — Stroke color
- `lineWidth` — Line width in pixels
- `lineCap` — Line cap style: `'butt'`, `'round'`, `'square'`
- `lineJoin` — Line join style: `'bevel'`, `'round'`, `'miter'`
- `globalAlpha` — Global opacity (0-1)
- `font` — Font specification (e.g., "16px sans-serif" or "16px /path/to/font.ttf")
- `textAlign` — Text alignment: `'start'`, `'center'`, `'end'`
- `textBaseline` — Text baseline: `'top'`, `'hanging'`, `'middle'`, `'alphabetic'`, `'ideographic'`, `'bottom'`

Path methods:
- `beginPath()` — Start a new path
- `closePath()` — Close the current path
- `moveTo(float $x, float $y)` — Move pen without drawing
- `lineTo(float $x, float $y)` — Draw line to point
- `arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false)` — Draw arc
- `arcTo(float $x1, float $y1, float $x2, float $y2, float $radius)` — Draw arc to point
- `ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false)` — Draw ellipse
- `bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y)` — Cubic Bézier curve
- `quadraticCurveTo(float $cpx, float $cpy, float $x, float $y)` — Quadratic Bézier curve
- `rect(float $x, float $y, float $w, float $h)` — Add rectangle path
- `roundRect(float $x, float $y, float $w, float $h, float|array $radii)` — Add rounded rectangle

Drawing methods:
- `fill()` — Fill the current path
- `stroke()` — Stroke the current path
- `fillRect(float $x, float $y, float $w, float $h)` — Fill a rectangle
- `strokeRect(float $x, float $y, float $w, float $h)` — Stroke a rectangle
- `clearRect(float $x, float $y, float $w, float $h)` — Clear a rectangle
- `fillText(string $text, float $x, float $y, ?float $maxWidth = null)` — Draw filled text
- `strokeText(string $text, float $x, float $y, ?float $maxWidth = null)` — Draw stroked text
- `measureText(string $text): array{width: float}` — Measure text width

Image methods:
- `drawImage(Canvas|GdImage|Imagick|string $source, ...)` — Draw an image (3/5/9 args)
- `getImageData(float $x, float $y, float $w, float $h): array{width:int, height:int, data:string}` — Get RGBA data
- `putImageData(array $imageData, float $x, float $y)` — Put RGBA data

Transform methods:
- `save()` — Save context state
- `restore()` — Restore context state
- `translate(float $x, float $y)` — Translate origin
- `scale(float $x, float $y)` — Scale
- `rotate(float $angle)` — Rotate (in radians)
- `transform(float $a, float $b, float $c, float $d, float $e, float $f)` — Apply transformation matrix
- `setTransform(float $a, float $b, float $c, float $d, float $e, float $f)` — Set transformation matrix
- `resetTransform()` — Reset to identity transform

Gradient methods:
- `createLinearGradient(float $x0, float $y0, float $x1, float $y1)` — Create linear gradient
- `createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1)` — Create radial gradient
- Gradient objects have `addColorStop(float $offset, string $color)` method

Clipping:
- `clip()` — Clip to current path

## Differences from the Browser Canvas

- **Even-odd fill rule not supported** — Uses non-zero winding rule only
- **Curves are flattened** — Curves are converted to polylines before rasterization
- **Clipping cost** — `clip()` uses a temporary layer, so it's more expensive than in browsers
- **LineWidth > 1** — Thick lines are drawn as filled polygons; joins use GD's built-in behavior
- **Gradient fill with clipping** — Gradients are rasterized into a temporary image and composited
- **No canvas filter effects** — Blur, shadow, etc. not supported
- **Font support limited** — Built-in fonts or TTF files via `ext-gd`; system fonts via file path

## License

MIT
