# Changelog

## [0.1.1] - 2026-10-31

### Fixed
- Gradient fill of paths now properly composites gradient through path mask
- `toPixels()` now uses `imagebmp()` fallback instead of disabled `imagegd()`
- `toDots()` now correctly samples pixels and compares against background color
- Imagick backend now properly requires GD extension to prevent silent failures
- Removed PHP warnings from pixel extraction operations

## [0.1.0] - 2026-10-31

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
