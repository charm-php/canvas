# Changelog

## [0.2.4]
### Changed
- `toDots()`: a dot is on when at least half of its block is covered, not when
  any pixel in it is. An antialiased edge no longer spreads one dot outwards, and
  a line a quarter of a cell wide shows as one Braille dot, not two. Colours are
  unchanged (the coverage-weighted mean of the covered pixels).
- `toDots()` at the canvas's own size takes a direct path, one pixel per dot
  (4.6 → ~1 ms for 104x80). A consumer that only needs dots can rasterise at the
  dot grid with a `scale()` and skip the downsampling: the graph benchmark's
  frame drops from 26 to 9 ms interpreted, with 3 of 584 lit dots differing.

## [0.2.3]
A second renderer on ext-imagick, opt-in with `Canvas::$renderer = 'imagick'`
(see README, "Renderers"). The PHP renderer stays the default: on the two
benchmark frames it is faster than ImageMagick 6 in every case with the JIT
warm (graph 13 vs 172 ms, mixed 138 vs 281 ms) and closer to Chrome. The public
`Canvas`/`Context` API is unchanged.

### Added
- `RendererInterface` (what `Context` needs from a pixel holder), implemented by
  `Surface` (PHP) and the new `ImagickSurface`
- `Canvas::$renderer` (`null|'php'|'imagick'`), `Canvas::rendererName()`,
  `CANVAS_RENDERER` for phpunit, `bin/conformance` (also `--renderer=`) and
  `benchmarks/run.php`
- `Paint::linearStrip()` so the Imagick renderer paints linear gradients from a
  512-sample strip instead of per pixel

### Conformance vs Chrome
PHP renderer: 335/336 (as before). Imagick renderer: 329/336, with these cases
below the tier tolerance and declared in `Runner::IMAGICK_KNOWN`; each is an
engine difference that supersampling and calibration cannot remove:

| case | agreement | reason |
| --- | --- | --- |
| `gco/color-dodge`, `gco/color-dodge/alpha` | 89.1% | IM6 color-dodge is not the W3C formula (zero backdrop, full source gives 1, Chrome 0) |
| `path/rect-in-path-stroke` | 95.0% | IM6 polygon antialiasing is a distance ramp whose error depends on edge direction: a half-covered 1px row reads 109 or 135, Chrome 128 |
| `roundrect/stroke` | 94.8% | same, 1px stroke |
| `clip/triangle` | 97.5% | same, diagonal clip edge (<= 12/255 per edge pixel; strict tier needs 99.5%) |
| `clip/evenodd-donut` | 96.7% | same, curved clip edge |

How IM6 was made to fit: its polygon fill is a distance ramp that draws about
1.4% too much coverage (6% on a 1px stroke), so polygons are drawn at 8x, 4x,
2x or 1x (fewer for bigger regions, 1.5M sample budget), box-filtered, and a
black point of 0.56/k is applied to alpha. `filter=point` is set on every image
because `distortImage` otherwise resamples with EWA instead of bilinear. Fractional
subrect and rotated images use `distortImage` with edge replication and the
quad's outline as the mask (whole pixels with half rounding up when
axis-aligned, as Chrome does).

### Not done with Imagick
- Strokes are outlined in PHP (`Stroker`) and filled as polygons, not stroked by
  `ImagickDraw`: the path commands cost ~0.5 ms per segment in IM6, and the
  outline already carries caps, joins, miters, dashes and the CTM scaling
- Radial gradients and patterns are sampled per pixel in PHP (no Imagick
  primitive matches the canvas semantics)
- Text still rasterises glyph masks with GD (`TextRaster`) and composites them
- Subpaths of one fill are combined as union / difference / XOR of per-ring
  masks, which equals the fill rule for nested or disjoint rings but not for
  overlapping subpaths of opposite winding

### Benchmarks
ms/frame (`php benchmarks/run.php graph|mixed [--jit]`, `CANVAS_RENDERER=...`),
interpreter / tracing JIT:

| frame | php | imagick |
| --- | --- | --- |
| graph (416x320, 3 shapes, toDots) | 25.3 / 13.8 | 178 / 174 |
| mixed (640x480) | 434 / 139 | 287 / 286 |

Imagick is **slower** on graph (about 7x interpreted, 13x with the JIT): each of
its three shapes spans most of the canvas, IM6 fills a polygon at ~70 ns per
supersampled pixel times the edge count, and every layer costs several
full-region allocations and composites; toDots on the Imagick pixels adds ~25 ms
of PHP unpacking. On mixed it is faster without the JIT (a 640x480 gradient,
ellipse, clip, two images) but 2x slower than the JIT'd PHP renderer. It does not
get faster with `--jit` because the time is inside ImageMagick. Hosts without
the JIT, large images and gradients are where it pays; many small or thin shapes
and JIT'd hot loops are where the PHP renderer wins.

## [0.2.2]
Pixels are stored in GD's truecolor format, so nothing is converted when they
cross into or out of GD. This is an internal change: the public API is
unchanged. Alpha now has 7 bits instead of 8 (ImageData still reads and writes
8-bit alpha, stretched through lookup tables).

### Changed
- `Surface` holds one int per pixel as 0xAARRGGBB with alpha 0 = opaque ..
  127 = transparent. Source-over blends in that 7-bit domain with an exact
  integer division by 127. `clonePixels()` and `setPixels()` use the same GD ints
- `GdCodec` no longer remaps alpha. Measured at 640x480: `imagecolorat` loop
  18 ms out, `imagesetpixel` loop 11.5 ms in (the bulk route through
  `imagegd2()` is not available: common PHP builds are compiled without GD/GD2
  format support)
- `bin/conformance`: still 335/336; 31 cases moved by at most 1.5 points (see
  the commit message)

### Added
- Pixel access on `Surface`: `idx($x, $y)`, `getPixel($x, $y)` (raw GD int,
  transparent outside), `setPixel($x, $y, $v)` and `blendPixel($x, $y, $v,
  $alpha7)` (source-over, same arithmetic as the row fast path). Single pixels
  are addressed through them everywhere (blits, glyph masks, clear edges);
  only the whole-run row loops use the array directly. `rawPixel()` is now
  `getPixel()`; the old float-RGBA `getPixel()` is `colorAt()`
- `benchmarks/run.php <graph|mixed> [--jit]`: 10 warm passes, mean of 10
  frames; `--jit` re-executes with the tracing JIT

### Performance
Found with excimer under the warm tracing JIT: `toDots` no longer packs rows to
strings, flat blocks are memoised by pixel value, `Raster::walk` skips a sort
call per sample, `round()` calls on the per-pixel path are integer arithmetic,
`inQuad` is unrolled. Output is unchanged. ms/frame, interpreter / JIT:

| frame | before | after |
| --- | --- | --- |
| graph (416x320, toDots 104x80) | 26.6 / 17.9 | 25.2 / 13.2 |
| mixed (640x480) | 471.8 / 177.1 | 431 / 136.6 |

## [0.2.1]
Rendering is much faster with the same output. A 416x320 graph frame
(`php benchmarks/graph-frame.php .`) went from 220 ms to 28.5 ms, and a 640x480
mixed frame (`php benchmarks/mixed-frame.php .`: gradient fill, translucent
ellipse, clip, a photo drawn scaled and rotated, text) from 1412 ms to 490 ms.
`bin/conformance` still agrees with Chrome on 335 of 336 cases, with the same
documented divergence and no case's per-pixel agreement lower.

### Changed
- Pixels are one packed int per pixel (0xRRGGBBAA, so `pack('N*')` is the
  ImageData byte order). Measured against RGBA row strings: graph-frame
  26.5 -> 28.6 ms (strings win on bulk runs), mixed-frame 1412 -> 1222 ms (ints
  win on per-pixel work). Costs 16 bytes per pixel instead of 4
- Fills cost per edge rather than per covered column; opaque shapes and flat
  runs are written a row segment at a time. The rasteriser orders a row's
  edges once when they all span it, and adds a span's samples as one term when
  its ends stay in their cell
- Source-over, drawImage, fillRect and gradient paints no longer allocate per
  pixel; `pushClip` skips runs where the new mask is 0 or 255; the GD codec
  converts pixels through a lookup table without allocating colours
- `toDots` reads pixels a row at a time and reuses identical blocks
- A fully cleared pixel no longer keeps its old colour behind alpha 0

### Tried and not kept
- drawImage with an axis-aligned scale through GD's `imagecopyresampled`:
  saved 23 ms of the 490 ms mixed frame, but GD's kernel differs from
  Chrome's bilinear and 7 image cases fell below tolerance (conformance
  328/336, image 12/18), so the PHP sampler stays

### Added
- `benchmarks/graph-frame.php` and `benchmarks/mixed-frame.php`, the frames
  used for the numbers above
- Regression tests for thin strokes under non-uniform scale, `clearRect` and
  `drawImage` under scale, and `toDots` at the canvas size and on fractional edges

## [0.2.0] - 2026-11-02
Reworked so that the API means what the browser's means. The renderer no longer
asks GD to draw anything: pixels, geometry and compositing live in this package,
and GD or Imagick only encode and decode files. Every behaviour below was
measured against headless Chrome with `bin/conformance`, which runs 336 programs
in both engines and compares pixels — 335 agree, and the one that does not is
listed as a documented divergence with the reason.

### Added
- `bin/conformance`: a Chrome-referenced pixel harness. One op program, run by
  this library and by Chrome, compared by coverage, bounding box and per-pixel
  agreement. `--tag`, `--filter`, `--verbose`, `--maps`, `--list`, `--dump CASE`
- Conformance cases for transforms, clipping, fill rules, paths, curves, strokes,
  caps, joins, dashes, gradients, compositing, text, images, image data, hit tests
- `tests/CanvasTest.php` and `tests/GeometryTest.php`: 39 tests, 215 assertions of
  pixels, state and geometry that need no browser
- `Codec\CodecInterface` and `Codec\GdCodec`: image decoding and encoding only
- `Context::reset()`, `fillRule` as a property, `getTransform`, `createImageData`
- Whole-canvas compositing for the operations whose result reaches outside the
  drawn shape (source-in/out, destination-in/atop, copy), decided from the
  Porter-Duff factors rather than a hand-maintained list

### Changed
- Coverage replaces masking: a pixel's value is the fraction of its area inside
  the shape, so fractional geometry, diagonal edges and clipped curves look the
  way a browser draws them
- Strokes are built as outlines with real caps, joins, miters and dash phases;
  a closed shape strokes as a ring, a zero-length segment dots
- `clearRect` rounds to whole pixels and is limited by the clip region, which is
  what Chrome does
- `drawImage` honours the source rectangle, snaps a one-to-one copy to whole
  pixels, clamps its filter at the source edge, antialiases a rotated quad, and
  treats a negative destination size as the same rectangle rather than a mirror
- Text metrics read the font's units per em once instead of scaling them twice,
  and stroked text outlines the glyph
- `getImageData` no longer inverts alpha; `toPixels` reports true alpha
- Invalid property values are ignored instead of throwing, as in a browser, and
  an unknown `getContext()` type returns null

### Fixed
- Subpaths no longer join to each other when filling or stroking
- `clip()` composes with the CTM and with other clips, and pops with `restore()`
- Gradients interpolate in premultiplied space and extend their end stops
- `isPointInStroke` reports the inside of a stroke wider than its own path
- Division-by-zero guards for 180-degree joins, degenerate curves and arcs

## [0.1.1] - 2026-11-01
### Fixed
- `stroke()` with `lineWidth <= 1` now applies the current transform matrix to thin lines (previously drawn untransformed)
- A fractional `lineWidth` under `scale()` is drawn at its scaled width in pixels (a 0.25-unit line under `scale(8, 16)` is 2-4 px, not 1 px)
- `strokeRect()` now respects the current transform matrix
- `clearRect()` now properly clears to transparent (alpha blending was preventing transparent fills)
- `drawImage()` now uses `imagecreatefromstring()` instead of non-existent `imagecreatefromfile()` for file paths and bytes
- `drawImage()` now applies the current transform matrix to the destination rectangle
- `toDots()` no longer segfaults when downsampling to the canvas's own pixel dimensions (GD 2.3.3 `imagescale` bug)
- `toDots()` now uses exact coverage-based downsampling instead of bilinear interpolation (prevents edge bleed artifacts)
- Added `Canvas::fromBackend()` factory method to construct a Canvas from a backend instance with optional custom Context class

## [0.1.0] - 2026-11-01
### Added
- Initial release of phasync/canvas
- Canvas class with GD and Imagick backend support
- Context class implementing browser Canvas 2D API
- Support for paths, shapes, text, images, and transformations
- Linear and radial gradient support
- Clipping via temporary layer compositing
- Multiple output formats: PNG, JPEG, GD image, Imagick image, raw pixels
- Terminal rendering support via `toDots()`
- Full test suite with pixel-level verification

### Fixed
- Gradient fill of paths now properly composites gradient through path mask
- `toPixels()` now uses `imagebmp()` fallback instead of disabled `imagegd()`
- `toDots()` now correctly samples pixels and compares against background color
- Imagick backend now properly requires GD extension to prevent silent failures
- Removed PHP warnings from pixel extraction operations
