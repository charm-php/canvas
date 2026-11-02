<?php declare(strict_types=1);

namespace phasync\Canvas;

use phasync\Canvas\Codec\CodecInterface;
use phasync\Canvas\Codec\GdCodec;

/**
 * A drawing surface: the canvas element of the browser model.
 *
 * Pixels live in a Surface; getting them in and out of files and native image
 * objects is the codec's job.
 */
class Canvas
{
    /** @var class-string<CodecInterface>|null */
    public static ?string $codecClass = null;

    private Surface $surface;
    private CodecInterface $codec;
    private ?Context $context = null;

    public function __construct(int $width, int $height)
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Canvas dimensions must be positive');
        }
        $this->codec = self::makeCodec();
        $this->surface = new Surface($width, $height);
    }

    private static function makeCodec(): CodecInterface
    {
        $class = self::$codecClass ?? GdCodec::class;
        if (!method_exists($class, 'isAvailable') || !$class::isAvailable()) {
            throw new NotSupportedException("Image backend $class is not available on this system");
        }
        return new $class();
    }

    // -----------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------

    public static function fromSurface(Surface $surface): self
    {
        $canvas = new self($surface->width, $surface->height);
        $canvas->surface = $surface;
        return $canvas;
    }

    /** Decode encoded image bytes (PNG, JPEG, GIF, WebP, BMP, …). */
    public static function fromBytes(string $bytes): self
    {
        $surface = self::makeCodec()->decode($bytes);
        if ($surface === null) {
            throw new \RuntimeException('Unable to decode image data');
        }
        return self::fromSurface($surface);
    }

    public static function fromFile(string $path): self
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new \RuntimeException("File not found or unreadable: $path");
        }
        $bytes = \file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read file: $path");
        }
        return self::fromBytes($bytes);
    }

    public static function fromPng(string $bytes): self
    {
        return self::fromBytes($bytes);
    }

    public static function fromGd(\GdImage $image): self
    {
        return self::fromSurface(self::makeCodec()->fromGdImage($image));
    }

    public static function fromImagick(\Imagick $imagick): self
    {
        return self::fromSurface(self::makeCodec()->fromImagick($imagick));
    }

    // -----------------------------------------------------------------
    // Element surface
    // -----------------------------------------------------------------

    /**
     * getContext('2d') — the only context type this library implements.
     * Unknown types return null, exactly as a browser does for WebGL here.
     *
     * @param array{alpha?:bool, desynchronized?:bool, colorSpace?:string} $options
     */
    public function getContext(?string $type = '2d', array $options = []): ?Context
    {
        if ($type !== null && !\in_array(\strtolower($type), ['2d', '2dlayer'], true)) {
            return null;
        }
        if ($this->context === null) {
            $this->context = new Context($this->surface);
        }
        return $this->context;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'width' => $this->surface->width,
            'height' => $this->surface->height,
            default => throw new \InvalidArgumentException("Unknown property: $name"),
        };
    }

    /**
     * Assigning width or height resizes the surface and clears it, and resets
     * the drawing state, as the canvas element does.
     */
    public function __set(string $name, mixed $value): void
    {
        match ($name) {
            'width' => $this->resizeTo((int) $value, $this->surface->height),
            'height' => $this->resizeTo($this->surface->width, (int) $value),
            default => throw new \InvalidArgumentException("Unknown property: $name"),
        };
    }

    private function resizeTo(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            return; // browsers ignore non-positive sizes
        }
        $this->surface = new Surface($width, $height);
        $this->context = null;
    }

    public function getWidth(): int
    {
        return $this->surface->width;
    }

    public function getHeight(): int
    {
        return $this->surface->height;
    }

    public function surface(): Surface
    {
        return $this->surface;
    }

    public function codec(): CodecInterface
    {
        return $this->codec;
    }

    /** canvas.reset(): clear and restore the default drawing state. */
    public function reset(): void
    {
        $this->surface->clear();
        $this->context = null;
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    public function toPng(): string
    {
        return $this->codec->encodePng($this->surface);
    }

    public function toJpeg(int $quality = 90): string
    {
        return $this->codec->encodeJpeg($this->surface, $quality);
    }

    /** toDataURL('image/png'|'image/jpeg', quality) */
    public function toDataURL(string $mime = 'image/png', ?float $quality = null): string
    {
        $mime = \strtolower($mime);
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            return 'data:image/jpeg;base64,' . \base64_encode($this->toJpeg((int) round(($quality ?? 0.92) * 100)));
        }
        if ($mime !== 'image/png' && $mime !== '') {
            throw new \InvalidArgumentException("Unsupported data URL type: $mime");
        }
        return 'data:image/png;base64,' . \base64_encode($this->toPng());
    }

    public function toGd(): \GdImage
    {
        return $this->codec->toGdImage($this->surface);
    }

    public function toImagick(): \Imagick
    {
        return $this->codec->toImagick($this->surface);
    }

    /**
     * Raw RGBA bytes, one row after another, alpha last — the same layout as a
     * browser's ImageData.
     *
     * @return array{width:int,height:int,rgba:string}
     */
    public function toPixels(): array
    {
        return [
            'width' => $this->surface->width,
            'height' => $this->surface->height,
            'rgba' => $this->surface->toRgbaString(),
        ];
    }

    /**
     * Downsample for terminal rendering.
     *
     * Each dot reports whether anything was painted in its block and the
     * average colour of the painted pixels in it.
     *
     * @return array{width:int,height:int,on:string,rgb:string}
     */
    public function toDots(int $w, int $h, ?string $background = null): array
    {
        if ($w <= 0 || $h <= 0) {
            throw new \InvalidArgumentException('toDots requires positive dimensions');
        }
        $sw = $this->surface->width;
        $sh = $this->surface->height;
        $on = '';
        $rgb = '';
        $bg = $background === null ? null : ColorParser::parseNormalized($background);
        $rows = $this->surface->toRgbaString();
        $stride = $sw * 4;
        $rowCache = [];

        for ($ty = 0; $ty < $h; $ty++) {
            $y0 = (int) floor($ty * $sh / $h);
            $y1 = min($sh, max($y0 + 1, (int) ceil(($ty + 1) * $sh / $h)));
            // Bytes of each source row in this dot row, as [R,G,B,A,R,...] from index 1.
            $lines = [];
            for ($y = $y0; $y < $y1; $y++) {
                $lines[$y] = $rowCache[$y] ??= \unpack('C*', \substr($rows, $y * $stride, $stride));
            }
            unset($rowCache[$y0 - 1]);
            for ($tx = 0; $tx < $w; $tx++) {
                $x0 = (int) floor($tx * $sw / $w);
                $x1 = min($sw, max($x0 + 1, (int) ceil(($tx + 1) * $sw / $w)));

                $r = $g = $b = 0.0;
                $weight = 0.0;
                foreach ($lines as $px) {
                    for ($i = $x0 * 4 + 1, $end = $x1 * 4 + 1; $i < $end; $i += 4) {
                        if ($bg === null) {
                            if ($px[$i + 3] === 0) {
                                continue;
                            }
                            $a = $px[$i + 3] / 255;
                        } else {
                            $a = (min(abs($px[$i] / 255 - $bg[0]), 1.0) + min(abs($px[$i + 1] / 255 - $bg[1]), 1.0) + min(abs($px[$i + 2] / 255 - $bg[2]), 1.0)) / 3.0;
                            if ($a <= 0.0) {
                                continue;
                            }
                        }
                        $r += $px[$i] / 255 * $a;
                        $g += $px[$i + 1] / 255 * $a;
                        $b += $px[$i + 2] / 255 * $a;
                        $weight += $a;
                    }
                }

                if ($weight <= 0.0) {
                    $on .= "\0";
                    $rgb .= "\0\0\0";
                    continue;
                }
                $on .= "\1";
                $rgb .= \chr((int) round($r / $weight * 255))
                    . \chr((int) round($g / $weight * 255))
                    . \chr((int) round($b / $weight * 255));
            }
        }

        return ['width' => $w, 'height' => $h, 'on' => $on, 'rgb' => $rgb];
    }

    // -----------------------------------------------------------------
    // Derived canvases
    // -----------------------------------------------------------------

    /** A copy of this canvas at the same size. */
    public function copy(): self
    {
        $clone = new self($this->surface->width, $this->surface->height);
        $clone->surface = $this->surface->readRect(0, 0, $this->surface->width, $this->surface->height);
        return $clone;
    }

    /**
     * A copy scaled to the given size, drawn through the same resampling the
     * canvas uses for images.
     */
    public function resize(int $w, int $h): self
    {
        if ($w <= 0 || $h <= 0) {
            throw new \InvalidArgumentException('resize requires positive dimensions');
        }
        $out = new self($w, $h);
        $out->getContext()->drawImage(
            $this,
            0,
            0,
            $this->surface->width,
            $this->surface->height,
            0,
            0,
            $w,
            $h,
        );
        return $out;
    }
}
