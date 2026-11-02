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

        $user = $this->deviceToUser !== null
            ? $this->deviceToUser->mapPoint($deviceX, $deviceY)
            : ['x' => $deviceX, 'y' => $deviceY];

        if ($this->pattern !== null) {
            return $this->pattern->colorAt($user['x'], $user['y']);
        }

        return $this->gradientColor($user['x'], $user['y']);
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
     * Interpolate the stop list at t (pad: clamped to the end stops).
     *
     * @return array{0:float,1:float,2:float,3:float}
     */
    private function sample(float $t): array
    {
        $stops = $this->stops;
        $n = count($stops);
        if ($n === 0) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        if ($t <= $stops[0][0]) {
            return self::unpremultiply($stops[0][1], $stops[0][2], $stops[0][3], $stops[0][4]);
        }
        $last = $stops[$n - 1];
        if ($t >= $last[0]) {
            return self::unpremultiply($last[1], $last[2], $last[3], $last[4]);
        }

        for ($i = 0; $i < $n - 1; $i++) {
            $s0 = $stops[$i];
            $s1 = $stops[$i + 1];
            if ($t >= $s0[0] && $t <= $s1[0]) {
                $range = $s1[0] - $s0[0];
                $f = $range > 0.0 ? ($t - $s0[0]) / $range : 1.0;
                return self::unpremultiply(
                    $s0[1] + ($s1[1] - $s0[1]) * $f,
                    $s0[2] + ($s1[2] - $s0[2]) * $f,
                    $s0[3] + ($s1[3] - $s0[3]) * $f,
                    $s0[4] + ($s1[4] - $s0[4]) * $f,
                );
            }
        }
        return self::unpremultiply($last[1], $last[2], $last[3], $last[4]);
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
