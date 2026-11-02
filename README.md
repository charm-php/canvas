# phasync/canvas

The browser's 2D canvas API for PHP. Draw with `CanvasRenderingContext2D` calls and
export PNG or JPEG — no browser, no headless Chrome, no native drawing library.

```php
use phasync\Canvas\Canvas;

$canvas = new Canvas(400, 300);
$ctx = $canvas->getContext('2d');

$ctx->fillStyle = '#0b1220';
$ctx->fillRect(0, 0, 400, 300);

$ctx->translate(0, 300);
$ctx->scale(1, -1);                       // the usual maths-oriented flip
$ctx->strokeStyle = 'rgb(99, 179, 237)';
$ctx->lineWidth = 2;
$ctx->beginPath();
$ctx->moveTo(0, 0);
foreach ([[40, 60], [120, 180], [200, 90], [300, 240]] as [$x, $y]) {
    $ctx->lineTo($x, $y);
}
$ctx->stroke();

file_put_contents('chart.png', $canvas->toPng());
```

## What this is made of

Everything that decides what a pixel looks like — the CTM, path flattening,
scanline coverage, fills, strokes, clipping, Porter-Duff compositing, gradients,
patterns, text placement — is implemented in this package in plain PHP.
`ext-gd` (or `ext-imagick`) is only a **codec**: it decodes and encodes PNG and
JPEG. No GD primitive draws anything, so a different codec, or a pure-PHP
encoder, can be dropped in without touching the semantics.

### Pixel format

A `Surface` is a flat PHP int array, one int per pixel, in GD's truecolor
format: `0xAARRGGBB` with alpha 0 = opaque .. 127 = transparent, so pixels cross
into and out of GD (`fromGd()`, `toGd()`, PNG/JPEG) without conversion. Alpha
therefore has 7 bits; `getImageData()`/`putImageData()`/`toPixels()` still speak
8-bit RGBA and stretch alpha through lookup tables, so translucent results can
differ from a browser by a step or two. GD is used to decode and encode, and to
rasterise glyphs (`TextRaster`), whose pixels are composited as they are.

| File | Role |
| --- | --- |
| `Context.php` | the `CanvasRenderingContext2D` API, state and property rules |
| `Matrix.php` | the current transformation matrix |
| `Path.php` | path building and curve flattening |
| `Raster.php` | polygon coverage, fill rules, point-in-path |
| `Stroker.php` | outlines, caps, joins, miters, dashes |
| `Surface.php` | the pixel buffer, clip masks, compositing, blits |
| `Compositing.php` | the 26 global composite operations |
| `Gradient.php`, `Pattern.php`, `Paint.php` | what a style paints with |
| `Fonts.php`, `TextRaster.php` | CSS font shorthand, TTF metrics, glyph masks |
| `Codec/GdCodec.php` | PNG/JPEG in and out, GD and Imagick handoff |

## Conformance

`bin/conformance` runs 336 drawing programs twice — once here, once in headless
Google Chrome — and compares the resulting pixels:

```
$ bin/conformance
phasync/canvas conformance  (Google Chrome 144.0.7559.109)
──────────────────────────────────────────────────────────────────────────
  335/336 passing (99.7%) in 0.9s   (1 documented divergence)
```

Every case is written once and executed by both engines, so the suite is a
question about behaviour rather than about this code's structure. Where the two
engines disagree beyond a rasteriser's reasonable difference, the case carries a
note that says so and why — the report prints those notes instead of hiding
them, and a case that stops diverging fails until the note is removed.

```bash
bin/conformance                 # summary
bin/conformance --tag clip      # one area
bin/conformance -f 'stroke/*'   # filter by case id
bin/conformance -v              # a text map per failure
bin/conformance --dump arc/circle-stroke   # both renders plus a diff list
```

Chrome writes its pixels to a cache (`CONFORMANCE_NOCACHE=0` to ignore it), and
the comparison uses tiers — `strict`, `normal`, `thin`, `aa-edge`, `text` — the
looser ones existing only where a measurement is written next to them.

## Installation

```bash
composer require phasync/canvas
```

PHP 8.2+ with `ext-gd`. With `ext-imagick` installed it is used for decoding and
for `toImagick()`/`fromImagick()`.

## API

### Canvas

- `new Canvas(int $width, int $height)`
- `Canvas::fromSurface(Surface $s): Canvas`
- `Canvas::fromBytes(string $bytes): Canvas` — PNG, JPEG, GIF, WebP by content
- `Canvas::fromFile(string $path): Canvas`
- `Canvas::fromPng(string $bytes): Canvas`
- `Canvas::fromGd(\GdImage $image): Canvas`
- `Canvas::fromImagick(\Imagick $imagick): Canvas`
- `getContext(?string $type = '2d'): ?Context` — `null` for anything but `2d`
- `getWidth()`, `getHeight()`, `$width`, `$height` — setting either clears the canvas
- `reset(): void` — clear and hand out a fresh context
- `copy(): Canvas`, `resize(int $w, int $h): Canvas`
- `toPng()`, `toJpeg(int $quality = 90)`, `toDataURL(string $mime = 'image/png')`
- `toGd(): \GdImage`, `toImagick(): \Imagick`
- `toPixels(): array{width:int, height:int, rgba:string}`
- `toDots(int $w, int $h, ?string $background = null)` — for terminals

### Context properties

`fillStyle`, `strokeStyle`, `lineWidth`, `lineCap`, `lineJoin`, `miterLimit`,
`fillRule`, `globalAlpha`, `globalCompositeOperation`, `font`, `textAlign`,
`textBaseline`, `direction`, `lineDashOffset`, `imageSmoothingEnabled`,
`imageSmoothingQuality`, `filter`, `shadowBlur`, `shadowColor`,
`shadowOffsetX`, `shadowOffsetY`, `canvas`.

Assignment follows the browser rules: a value that a browser would reject is
ignored and leaves the previous value in place, so `$ctx->fillStyle = 'red-dish'`
is a no-op rather than an error.

### Context methods

Paths: `beginPath`, `closePath`, `moveTo`, `lineTo`, `rect`, `roundRect`, `arc`,
`arcTo`, `ellipse`, `bezierCurveTo`, `quadraticCurveTo`.

Painting: `fill`, `stroke`, `fillRect`, `strokeRect`, `clearRect`, `clip`.

Text: `fillText`, `strokeText`, `measureText`.

Images: `drawImage` (3, 5 and 9 arguments), `getImageData`, `putImageData`,
`createImageData`.

Transforms and state: `save`, `restore`, `reset`, `translate`, `scale`, `rotate`,
`transform`, `setTransform`, `resetTransform`, `getTransform`.

Queries: `isPointInPath`, `isPointInStroke`, `setLineDash`, `getLineDash`.

Styles: `createLinearGradient`, `createRadialGradient`, `createPattern`
(gradients and patterns take `addColorStop(offset, color)` and are accepted by
`fillStyle` and `strokeStyle`).

## Differences from the browser

- **Shadows and `filter` are accepted and stored but not rendered.** The state
  survives `save()`/`restore()`; nothing reads it yet.
- **Text is set by your FreeType, not by Skia.** Glyph outlines come from the
  same TTF a browser would use, but hinting and stem weight differ by up to
  around a fifth, so text is compared on placement and extent rather than
  per-pixel.
- **Thin diagonal strokes carry more ink than Chrome does.** This library
  integrates the area a stroke covers; Chrome samples an edge on a grid, which
  under-reads narrow diagonals by about a quarter at 1px. At 2px and above the
  two agree.
- **`clearRect` rounds to whole pixels and is limited by the clip region**, and
  **`drawImage` with a negative destination size names the mirrored rectangle
  without mirroring the bitmap**. Both are measured behaviour of Chrome; the
  spec text is worded differently.
- **Curves are flattened** before rasterising, at a tolerance derived from the
  transform, so a curve under a 1000x zoom is sampled more finely.
- **One canvas, one context.** There is no `willReadFrequently`, no second layer
  and no `requestAnimationFrame`; everything is immediate mode.

## Tests

```bash
vendor/bin/phpunit    # behaviour and geometry, no browser needed
bin/conformance       # pixel-for-pixel against Chrome
```

## License

MIT
