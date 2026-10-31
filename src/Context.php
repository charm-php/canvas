<?php declare(strict_types=1);

namespace phasync\Canvas;

use phasync\Canvas\Backend\BackendInterface;

/**
 * 2D rendering context for a Canvas.
 */
class Context
{
    private BackendInterface $backend;

    /**
     * @var string|Gradient
     */
    private string|Gradient $fillStyle = '#000000';

    /**
     * @var string|Gradient
     */
    private string|Gradient $strokeStyle = '#000000';

    private float $lineWidth = 1.0;
    private string $lineCap = 'butt';
    private string $lineJoin = 'miter';
    private float $globalAlpha = 1.0;
    private string $font = '16px sans-serif';
    private string $textAlign = 'start';
    private string $textBaseline = 'alphabetic';

    public function __construct(BackendInterface $backend)
    {
        $this->backend = $backend;
    }

    // Properties with magic getters/setters

    public function __get(string $name): mixed
    {
        return match ($name) {
            'fillStyle' => $this->fillStyle,
            'strokeStyle' => $this->strokeStyle,
            'lineWidth' => $this->lineWidth,
            'lineCap' => $this->lineCap,
            'lineJoin' => $this->lineJoin,
            'globalAlpha' => $this->globalAlpha,
            'font' => $this->font,
            'textAlign' => $this->textAlign,
            'textBaseline' => $this->textBaseline,
            default => throw new \InvalidArgumentException("Unknown property: $name"),
        };
    }

    public function __set(string $name, mixed $value): void
    {
        match ($name) {
            'fillStyle' => $this->setFillStyle($value),
            'strokeStyle' => $this->setStrokeStyle($value),
            'lineWidth' => $this->setLineWidth($value),
            'lineCap' => $this->setLineCap($value),
            'lineJoin' => $this->setLineJoin($value),
            'globalAlpha' => $this->setGlobalAlpha($value),
            'font' => $this->setFont($value),
            'textAlign' => $this->setTextAlign($value),
            'textBaseline' => $this->setTextBaseline($value),
            default => throw new \InvalidArgumentException("Unknown property: $name"),
        };
    }

    // Setters

    private function setFillStyle(mixed $value): void
    {
        if ($value instanceof Gradient) {
            $this->fillStyle = $value;
            $this->backend->setFillStyleGradient($value);
        } elseif (\is_string($value)) {
            $this->fillStyle = $value;
            $this->backend->setFillStyle($value);
        } else {
            throw new \InvalidArgumentException('fillStyle must be a color string or Gradient');
        }
    }

    private function setStrokeStyle(mixed $value): void
    {
        if ($value instanceof Gradient) {
            $this->strokeStyle = $value;
            $this->backend->setStrokeStyleGradient($value);
        } elseif (\is_string($value)) {
            $this->strokeStyle = $value;
            $this->backend->setStrokeStyle($value);
        } else {
            throw new \InvalidArgumentException('strokeStyle must be a color string or Gradient');
        }
    }

    private function setLineWidth(mixed $value): void
    {
        $this->lineWidth = (float)$value;
        $this->backend->setLineWidth($this->lineWidth);
    }

    private function setLineCap(mixed $value): void
    {
        $this->lineCap = (string)$value;
        $this->backend->setLineCap($this->lineCap);
    }

    private function setLineJoin(mixed $value): void
    {
        $this->lineJoin = (string)$value;
        $this->backend->setLineJoin($this->lineJoin);
    }

    private function setGlobalAlpha(mixed $value): void
    {
        $this->globalAlpha = (float)$value;
        $this->backend->setGlobalAlpha($this->globalAlpha);
    }

    private function setFont(mixed $value): void
    {
        $this->font = (string)$value;
        $this->backend->setFont($this->font);
    }

    private function setTextAlign(mixed $value): void
    {
        $this->textAlign = (string)$value;
        $this->backend->setTextAlign($this->textAlign);
    }

    private function setTextBaseline(mixed $value): void
    {
        $this->textBaseline = (string)$value;
        $this->backend->setTextBaseline($this->textBaseline);
    }

    // Path methods

    public function beginPath(): void
    {
        $this->backend->beginPath();
    }

    public function closePath(): void
    {
        $this->backend->closePath();
    }

    public function moveTo(float $x, float $y): void
    {
        $this->backend->moveTo($x, $y);
    }

    public function lineTo(float $x, float $y): void
    {
        $this->backend->lineTo($x, $y);
    }

    public function arc(float $x, float $y, float $radius, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->backend->arc($x, $y, $radius, $startAngle, $endAngle, $counterclockwise);
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        $this->backend->arcTo($x1, $y1, $x2, $y2, $radius);
    }

    public function ellipse(float $x, float $y, float $radiusX, float $radiusY, float $rotation, float $startAngle, float $endAngle, bool $counterclockwise = false): void
    {
        $this->backend->ellipse($x, $y, $radiusX, $radiusY, $rotation, $startAngle, $endAngle, $counterclockwise);
    }

    public function bezierCurveTo(float $cp1x, float $cp1y, float $cp2x, float $cp2y, float $x, float $y): void
    {
        $this->backend->bezierCurveTo($cp1x, $cp1y, $cp2x, $cp2y, $x, $y);
    }

    public function quadraticCurveTo(float $cpx, float $cpy, float $x, float $y): void
    {
        $this->backend->quadraticCurveTo($cpx, $cpy, $x, $y);
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->backend->rect($x, $y, $w, $h);
    }

    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii): void
    {
        $this->backend->roundRect($x, $y, $w, $h, $radii);
    }

    // Drawing methods

    public function fill(): void
    {
        $this->backend->fill();
    }

    public function stroke(): void
    {
        $this->backend->stroke();
    }

    public function fillRect(float $x, float $y, float $w, float $h): void
    {
        $this->backend->fillRect($x, $y, $w, $h);
    }

    public function strokeRect(float $x, float $y, float $w, float $h): void
    {
        $this->backend->strokeRect($x, $y, $w, $h);
    }

    public function clearRect(float $x, float $y, float $w, float $h): void
    {
        $this->backend->clearRect($x, $y, $w, $h);
    }

    // Text methods

    public function fillText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->backend->fillText($text, $x, $y, $maxWidth);
    }

    public function strokeText(string $text, float $x, float $y, ?float $maxWidth = null): void
    {
        $this->backend->strokeText($text, $x, $y, $maxWidth);
    }

    public function measureText(string $text): array
    {
        return $this->backend->measureText($text);
    }

    // Image methods

    public function drawImage($source, ...$args): void
    {
        $this->backend->drawImage($source, ...$args);
    }

    public function getImageData(float $x, float $y, float $w, float $h): array
    {
        return $this->backend->getImageData($x, $y, $w, $h);
    }

    public function putImageData(array $imageData, float $x, float $y): void
    {
        $this->backend->putImageData($imageData, $x, $y);
    }

    // Transform methods

    public function save(): void
    {
        $this->backend->save();
    }

    public function restore(): void
    {
        $this->backend->restore();
    }

    public function translate(float $x, float $y): void
    {
        $this->backend->translate($x, $y);
    }

    public function scale(float $x, float $y): void
    {
        $this->backend->scale($x, $y);
    }

    public function rotate(float $angle): void
    {
        $this->backend->rotate($angle);
    }

    public function transform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->backend->transform($a, $b, $c, $d, $e, $f);
    }

    public function setTransform(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $this->backend->setTransform($a, $b, $c, $d, $e, $f);
    }

    public function resetTransform(): void
    {
        $this->backend->resetTransform();
    }

    // Clipping

    public function clip(): void
    {
        $this->backend->clip();
    }

    // Gradient methods

    public function createLinearGradient(float $x0, float $y0, float $x1, float $y1): Gradient
    {
        return $this->backend->createLinearGradient($x0, $y0, $x1, $y1);
    }

    public function createRadialGradient(float $x0, float $y0, float $r0, float $x1, float $y1, float $r1): Gradient
    {
        return $this->backend->createRadialGradient($x0, $y0, $r0, $x1, $y1, $r1);
    }
}
