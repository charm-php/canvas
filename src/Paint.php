<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * A resolved paint: what colour a given device pixel gets.
 *
 * Gradient and pattern coordinates live in the user space that was current
 * when the paint was created, so a paint carries the inverse of that CTM to
 * map device pixels back into them.
 */
final class Paint
{
    private float $r = 0.0;
    private float $g = 0.0;
    private float $b = 0.0;
    private float $a = 0.0;

    private ?Gradient $gradient = null;
    private ?Pattern $pattern = null;
    private ?Matrix $deviceToUser = null;

    private float $firstT = 0.0;
    private float $lastT = 0.0;
    /** @var array{0:float,1:float,2:float,3:float} */
    private array $firstColor = [0.0, 0.0, 0.0, 0.0];
    private array $lastColor = [0.0, 0.0, 0.0, 0.0];

    /** Per stop pair: [t0, t1, range, r0, g0, b0, a0, dr, dg, db, da] (premultiplied). */
    private array $segs = [];

    /** Interpolated gradient stop cache: [offset, r, g, b, a] with r,g,b premultiplied. */
    private array $stops = [];
    private bool $radial = false;
    private float $x0 = 0.0, $y0 = 0.0, $x1 = 0.0, $y1 = 0.0, $r0 = 0.0, $r1 = 0.0;
    private float $dx = 0.0, $dy = 0.0, $lenSq = 0.0;
    private bool $gradientEmpty = false;
    private bool $gradientInvisible = false;

    private function __construct(private bool $constant)
    {
    }

    public static function transparent(): self
    {
        $p = new self(true);
        return $p;
    }

    public static function solid(float $r, float $g, float $b, float $a): self
    {
        $p = new self(true);
        $p->r = $r;
        $p->g = $g;
        $p->b = $b;
        $p->a = $a;
        return $p;
    }

    public static function fromColor(string $css): self
    {
        $c = ColorParser::parseNormalized($css);
        return self::solid($c[0], $c[1], $c[2], $c[3]);
    }

    public static function fromGradient(Gradient $gradient, Matrix $deviceToUser): self
    {
        $p = new self(false);
        $p->gradient = $gradient;
        $p->deviceToUser = $deviceToUser;
        $p->radial = $gradient->getType() === 'radial';
        $p->x0 = $gradient->getX0();
        $p->y0 = $gradient->getY0();
        $p->x1 = $gradient->getX1();
        $p->y1 = $gradient->getY1();
        $p->r0 = $gradient->getR0();
        $p->r1 = $gradient->getR1();
        $p->dx = $p->x1 - $p->x0;
        $p->dy = $p->y1 - $p->y0;
        $p->lenSq = $p->dx * $p->dx + $p->dy * $p->dy;

        $raw = $gradient->getStops();
        if ($raw === []) {
            $p->gradientEmpty = true;
            return $p;
        }
        foreach ($raw as $stop) {
            $c = ColorParser::parseNormalized($stop['color']);
            // Gradients interpolate in premultiplied space, like browsers.
            $p->stops[] = [
                $stop['offset'],
                $c[0] * $c[3],
                $c[1] * $c[3],
                $c[2] * $c[3],
                $c[3],
            ];
        }

        $first = $p->stops[0];
        $last = $p->stops[count($p->stops) - 1];
        $p->firstT = $first[0];
        $p->lastT = $last[0];
        $p->firstColor = self::unpremultiply($first[1], $first[2], $first[3], $first[4]);
        $p->lastColor = self::unpremultiply($last[1], $last[2], $last[3], $last[4]);
        for ($i = 0; $i + 1 < count($p->stops); $i++) {
            [$t0, $r0, $g0, $b0, $a0] = $p->stops[$i];
            [$t1, $r1, $g1, $b1, $a1] = $p->stops[$i + 1];
            $p->segs[] = [$t0, $t1, $t1 - $t0, $r0, $g0, $b0, $a0, $r1 - $r0, $g1 - $g0, $b1 - $b0, $a1 - $a0];
        }

        if ($p->radial) {
            if ($p->r0 < 0.0 || $p->r1 < 0.0) {
                $p->gradientInvisible = true;
            } elseif ($p->r0 === 0.0 && $p->r1 === 0.0) {
                $p->gradientInvisible = true;
            } elseif ($p->r0 === $p->r1 && $p->dx === 0.0 && $p->dy === 0.0) {
                $p->gradientInvisible = true;
            }
        }
        return $p;
    }

    public static function fromPattern(Pattern $pattern, Matrix $deviceToUser): self
    {
        $p = new self(false);
        $p->pattern = $pattern;
        $p->deviceToUser = $deviceToUser;
        return $p;
    }

    public function isConstant(): bool
    {
        return $this->constant;
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public function constant(): array
    {
        return [$this->r, $this->g, $this->b, $this->a];
    }

    public function alpha(): float
    {
        return $this->constant ? $this->a : 1.0;
    }

    public function isEmpty(): bool
    {
        if ($this->gradientEmpty || $this->gradientInvisible) {
            return true;
        }
        if ($this->constant) {
            return $this->a <= 0.0;
        }
        return false;
    }

    /**
     * Colour of this paint at a device pixel.
     *
     * @return array{0:float,1:float,2:float,3:float} straight RGBA 0..1
     */
    public function colorAt(float $deviceX, float $deviceY): array
    {
        if ($this->constant) {
            return [$this->r, $this->g, $this->b, $this->a];
        }
        if ($this->gradientEmpty || $this->gradientInvisible) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $m = $this->deviceToUser;
        if ($m !== null) {
            $ux = $m->a * $deviceX + $m->c * $deviceY + $m->e;
            $uy = $m->b * $deviceX + $m->d * $deviceY + $m->f;
        } else {
            $ux = $deviceX;
            $uy = $deviceY;
        }

        if ($this->pattern !== null) {
            return $this->pattern->colorAt($ux, $uy);
        }

        return $this->gradientColor($ux, $uy);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    private function gradientColor(float $ux, float $uy): array
    {
        if (!$this->radial) {
            if ($this->lenSq <= 0.0) {
                // Zero-length linear gradient: browsers paint nothing.
                return [0.0, 0.0, 0.0, 0.0];
            }
            $t = (($ux - $this->x0) * $this->dx + ($uy - $this->y0) * $this->dy) / $this->lenSq;
            return $this->sample($t);
        }

        $t = $this->radialParameter($ux, $uy);
        if ($t === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        return $this->sample($t);
    }

    /**
     * Parameter t of the radial cone through a user-space point, or null when
     * the point lies outside the cone entirely.
     */
    private function radialParameter(float $ux, float $uy): ?float
    {
        $fx = $ux - $this->x0;
        $fy = $uy - $this->y0;
        $dr = $this->r1 - $this->r0;
        $a = $this->lenSq - $dr * $dr;
        $b = -2.0 * ($fx * $this->dx + $fy * $this->dy + $this->r0 * $dr);
        $c = $fx * $fx + $fy * $fy - $this->r0 * $this->r0;

        $candidates = [];
        if (abs($a) < 1e-9) {
            if (abs($b) > 1e-9) {
                $candidates[] = -$c / $b;
            }
        } else {
            $disc = $b * $b - 4 * $a * $c;
            if ($disc < 0.0) {
                return null;
            }
            $sq = sqrt($disc);
            $candidates[] = (-$b - $sq) / (2 * $a);
            $candidates[] = (-$b + $sq) / (2 * $a);
        }

        $best = null;
        $bestRadius = -INF;
        foreach ($candidates as $t) {
            $radius = $this->r0 + $t * $dr;
            if ($radius < -1e-9) {
                continue; // the cone only exists where its radius is positive
            }
            if ($radius > $bestRadius) {
                $bestRadius = $radius;
                $best = $t;
            }
        }
        return $best;
    }

    /**
     * Interpolate the stop list at t (pad: clamped to the end stops), then
     * undo the premultiplication.
     *
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function sample(float $t): array
    {
        if ($t <= $this->firstT) {
            return $this->firstColor;
        }
        if ($t >= $this->lastT) {
            return $this->lastColor;
        }
        foreach ($this->segs as $g) {
            if ($t >= $g[0] && $t <= $g[1]) {
                $f = $g[2] > 0.0 ? ($t - $g[0]) / $g[2] : 1.0;
                $a = $g[6] + $g[10] * $f;
                if ($a <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                $r = ($g[3] + $g[7] * $f) / $a;
                $gr = ($g[4] + $g[8] * $f) / $a;
                $b = ($g[5] + $g[9] * $f) / $a;
                return [
                    $r < 0.0 ? 0.0 : ($r > 1.0 ? 1.0 : $r),
                    $gr < 0.0 ? 0.0 : ($gr > 1.0 ? 1.0 : $gr),
                    $b < 0.0 ? 0.0 : ($b > 1.0 ? 1.0 : $b),
                    $a > 1.0 ? 1.0 : $a,
                ];
            }
        }
        return $this->lastColor;
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    private static function unpremultiply(float $pr, float $pg, float $pb, float $a): array
    {
        if ($a <= 0.0) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        return [
            max(0.0, min(1.0, $pr / $a)),
            max(0.0, min(1.0, $pg / $a)),
            max(0.0, min(1.0, $pb / $a)),
            max(0.0, min(1.0, $a)),
        ];
    }
}
