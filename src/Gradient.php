<?php declare(strict_types=1);

namespace Charm\Canvas;

/**
 * Represents a gradient (linear or radial) for use with fillStyle/strokeStyle.
 */
class Gradient
{
    /**
     * @var array<array{offset: float, color: string}>
     */
    private array $stops = [];

    public function __construct(
        private string $type, // 'linear' or 'radial'
        private float $x0,
        private float $y0,
        private float $x1,
        private float $y1,
        private float $r0 = 0, // for radial
        private float $r1 = 0  // for radial
    ) {
    }

    /**
     * Add a color stop to the gradient.
     */
    public function addColorStop(float $offset, string $color): void
    {
        if ($offset < 0 || $offset > 1) {
            throw new \InvalidArgumentException('Color stop offset must be between 0 and 1');
        }

        $this->stops[] = ['offset' => $offset, 'color' => $color];
        // Sort stops by offset
        \usort($this->stops, fn($a, $b) => $a['offset'] <=> $b['offset']);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getX0(): float
    {
        return $this->x0;
    }

    public function getY0(): float
    {
        return $this->y0;
    }

    public function getX1(): float
    {
        return $this->x1;
    }

    public function getY1(): float
    {
        return $this->y1;
    }

    public function getR0(): float
    {
        return $this->r0;
    }

    public function getR1(): float
    {
        return $this->r1;
    }

    /**
     * @return array<array{offset: float, color: string}>
     */
    public function getStops(): array
    {
        return $this->stops;
    }
}
