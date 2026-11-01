<?php declare(strict_types=1);

namespace phasync\Canvas;

use phasync\Canvas\Backend\BackendInterface;
use phasync\Canvas\Backend\Gd;
use phasync\Canvas\Backend\Imagick;

/**
 * Canvas represents a drawing surface compatible with the browser's Canvas API.
 */
class Canvas
{
    /**
     * @var ?class-string<BackendInterface>
     */
    public static ?string $backendClass = null;

    private BackendInterface $backend;
    private int $width;
    private int $height;
    private Context $context;

    /**
     * Create a new canvas with the given dimensions.
     */
    public function __construct(int $width, int $height)
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Canvas dimensions must be positive');
        }

        $this->width = $width;
        $this->height = $height;

        $backendClass = self::$backendClass ?? $this->selectBackend();
        $this->backend = new $backendClass($width, $height);
        $this->context = new Context($this->backend);
    }

    /**
     * Create a Canvas from a backend instance.
     * @param class-string<Context> $contextClass
     */
    public static function fromBackend(BackendInterface $backend, ?string $contextClass = null): self
    {
        $width = $backend->getWidth();
        $height = $backend->getHeight();

        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Backend dimensions must be positive');
        }

        $canvas = new self($width, $height);
        $canvas->backend = $backend;

        $contextClass ??= Context::class;
        if (!\is_a($contextClass, Context::class, true)) {
            throw new \InvalidArgumentException("Context class must extend Context");
        }

        $canvas->context = new $contextClass($backend);
        return $canvas;
    }

    /**
     * Create a Canvas from a GD image resource.
     */
    public static function fromGd(\GdImage $image): self
    {
        $canvas = new self(\imagesx($image), \imagesy($image));
        $canvas->backend->fromGd($image);
        return $canvas;
    }

    /**
     * Create a Canvas from an Imagick instance.
     */
    public static function fromImagick(\Imagick $imagick): self
    {
        if (!extension_loaded('imagick')) {
            throw new NotSupportedException('Imagick extension is not loaded');
        }

        $canvas = new self(
            (int)$imagick->getImageWidth(),
            (int)$imagick->getImageHeight()
        );
        $canvas->backend->fromImagick($imagick);
        return $canvas;
    }

    /**
     * Create a Canvas from a file (PNG, JPEG, GIF, WebP).
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: $path");
        }

        $backendClass = self::$backendClass ?? self::selectBackendStatic();

        if ($backendClass === Gd::class || $backendClass === null) {
            try {
                $image = \imagecreatefromfile($path);
                if ($image === false) {
                    throw new \RuntimeException("Failed to load image from file: $path");
                }
                return self::fromGd($image);
            } catch (\Exception $e) {
                if ($backendClass === Gd::class) {
                    throw $e;
                }
                // Fall through to Imagick
            }
        }

        if ($backendClass === Imagick::class || $backendClass === null) {
            if (!extension_loaded('imagick')) {
                throw new NotSupportedException('Neither GD nor Imagick could load the image');
            }
            $imagick = new \Imagick($path);
            return self::fromImagick($imagick);
        }

        throw new NotSupportedException('No suitable backend to load image');
    }

    /**
     * Create a Canvas from PNG bytes.
     */
    public static function fromPng(string $bytes): self
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'canvas_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        try {
            \file_put_contents($tempFile, $bytes);
            return self::fromFile($tempFile);
        } finally {
            @\unlink($tempFile);
        }
    }

    /**
     * Get the 2D rendering context.
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Get the width of the canvas.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Get the height of the canvas.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Export the canvas as PNG bytes.
     */
    public function toPng(): string
    {
        return $this->backend->toPng();
    }

    /**
     * Export the canvas as JPEG bytes.
     */
    public function toJpeg(int $quality = 90): string
    {
        return $this->backend->toJpeg($quality);
    }

    /**
     * Get the underlying GD image.
     */
    public function toGd(): \GdImage
    {
        return $this->backend->toGd();
    }

    /**
     * Get the underlying Imagick instance.
     */
    public function toImagick(): \Imagick
    {
        return $this->backend->toImagick();
    }

    /**
     * Get raw RGBA pixel data.
     * @return array{width: int, height: int, rgba: string}
     */
    public function toPixels(): array
    {
        return $this->backend->toPixels();
    }

    /**
     * Get downscaled bitmap for terminal rendering.
     * @return array{width: int, height: int, on: string, rgb: string}
     */
    /**
     * Get downscaled bitmap for terminal rendering.
     * A dot is "on" when it contains non-transparent pixels (alpha < 127).
     * Optionally compare against a background color instead.
     * Note: Uses imagecolorat() per dot when no background provided (performance cost).
     * @return array{width: int, height: int, on: string, rgb: string}
     */
    public function toDots(int $w, int $h, ?string $background = null): array
    {
        return $this->backend->toDots($w, $h, $background);
    }

    /**
     * Create a resized copy of this canvas.
     */
    public function resize(int $w, int $h): Canvas
    {
        $resized = new Canvas($w, $h);
        $resized->getContext()->drawImage($this, 0, 0, $this->width, $this->height, 0, 0, $w, $h);
        return $resized;
    }

    /**
     * Select the best available backend.
     * @return class-string<BackendInterface>
     */
    private function selectBackend(): string
    {
        return self::selectBackendStatic();
    }

    /**
     * Select the best available backend (static version).
     * @return class-string<BackendInterface>
     */
    private static function selectBackendStatic(): string
    {
        if (extension_loaded('gd')) {
            return Gd::class;
        }

        if (extension_loaded('imagick')) {
            return Imagick::class;
        }

        throw new NotSupportedException('Neither GD nor Imagick extension is loaded');
    }
}
