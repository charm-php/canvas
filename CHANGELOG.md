# Changelog

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
