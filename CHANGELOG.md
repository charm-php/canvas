# Changelog

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
