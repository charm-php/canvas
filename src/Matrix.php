<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * A 2D affine transform, matching the HTML canvas matrix
 *
 *     | a c e |
 *     | b d f |
 *     | 0 0 1 |
 *
 * so that a user-space point (x, y) maps to
 *
 *     x' = a*x + c*y + e
 *     y' = b*x + d*y + f
 *
 * The canvas convention is that transform() post-multiplies: applying T after
 * an existing CTM C yields C x T, i.e. the newest transform acts on the
 * innermost (user space) coordinates.
 */
final class Matrix
{
    public function __construct(
        public readonly float $a = 1.0,
        public readonly float $b = 0.0,
        public readonly float $c = 0.0,
        public readonly float $d = 1.0,
        public readonly float $e = 0.0,
        public readonly float $f = 0.0,
    ) {
    }

    public static function identity(): self
    {
        return new self();
    }

    /** Apply a transform after this one: returns $this x $m. */
    public function multiply(self $m): self
    {
        return new self(
            $this->a * $m->a + $this->c * $m->b,
            $this->b * $m->a + $this->d * $m->b,
            $this->a * $m->c + $this->c * $m->d,
            $this->b * $m->c + $this->d * $m->d,
            $this->a * $m->e + $this->c * $m->f + $this->e,
            $this->b * $m->e + $this->d * $m->f + $this->f,
        );
    }

    public function translated(float $x, float $y): self
    {
        return $this->multiply(new self(1, 0, 0, 1, $x, $y));
    }

    public function scaled(float $x, float $y): self
    {
        return $this->multiply(new self($x, 0, 0, $y, 0, 0));
    }

    public function rotated(float $angle): self
    {
        $cos = cos($angle);
        $sin = sin($angle);
        return $this->multiply(new self($cos, $sin, -$sin, $cos, 0, 0));
    }

    /** @return array{x: float, y: float} */
    public function mapPoint(float $x, float $y): array
    {
        return [
            'x' => $this->a * $x + $this->c * $y + $this->e,
            'y' => $this->b * $x + $this->d * $y + $this->f,
        ];
    }

    public function determinant(): float
    {
        return $this->a * $this->d - $this->b * $this->c;
    }

    public function isSingular(): bool
    {
        return abs($this->determinant()) < 1e-12;
    }

    public function inverse(): ?self
    {
        $det = $this->determinant();
        if (abs($det) < 1e-12) {
            return null;
        }
        return new self(
            $this->d / $det,
            -$this->b / $det,
            -$this->c / $det,
            $this->a / $det,
            ($this->c * $this->f - $this->d * $this->e) / $det,
            ($this->b * $this->e - $this->a * $this->f) / $det,
        );
    }

    /**
     * A scale factor representative of this matrix, used where a device-space
     * size is needed (line widths under a uniform scale, glyph rasterisation).
     */
    public function averageScale(): float
    {
        $det = $this->determinant();
        if (abs($det) < 1e-12) {
            return 0.0;
        }
        return sqrt(abs($det));
    }

    public function isIdentity(): bool
    {
        return $this->a === 1.0 && $this->b === 0.0 && $this->c === 0.0
            && $this->d === 1.0 && $this->e === 0.0 && $this->f === 0.0;
    }

    /** Is this a pure integer translation of the identity? */
    public function isIntegerTranslation(): bool
    {
        return $this->a === 1.0 && $this->b === 0.0 && $this->c === 0.0 && $this->d === 1.0
            && $this->e === floor($this->e) && $this->f === floor($this->f);
    }

    /** Is this an axis-aligned scale + translation (no rotation, no shear)? */
    public function isAxisAligned(): bool
    {
        return ($this->b === 0.0 && $this->c === 0.0);
    }

    /**
     * Axis-aligned device bounds of the device-space rectangle that is the
     * image of the user-space rectangle ($x,$y,$w,$h).  Only valid when the
     * matrix is axis aligned; callers must check.
     *
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function mapAxisRect(float $x, float $y, float $w, float $h): array
    {
        $p0 = $this->mapPoint($x, $y);
        $p1 = $this->mapPoint($x + $w, $y + $h);
        return [
            'x' => min($p0['x'], $p1['x']),
            'y' => min($p0['y'], $p1['y']),
            'w' => abs($p1['x'] - $p0['x']),
            'h' => abs($p1['y'] - $p0['y']),
        ];
    }

    /**
     * Device-space bounding box of a transformed arbitrary quad.
     *
     * @param list<array{x: float, y: float}> $points
     * @return array{x0: float, y0: float, x1: float, y1: float}
     */
    public static function boundsOf(array $points): array
    {
        $x0 = $y0 = INF;
        $x1 = $y1 = -INF;
        foreach ($points as $p) {
            $x0 = min($x0, $p['x']);
            $y0 = min($y0, $p['y']);
            $x1 = max($x1, $p['x']);
            $y1 = max($y1, $p['y']);
        }
        return ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1];
    }
}
