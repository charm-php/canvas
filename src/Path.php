<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * A canvas path: subpaths in user space, with curves kept symbolic until they
 * are flattened against the CTM.
 *
 * Keeping curves symbolic matters for fidelity: flattening happens at paint
 * time with the current transform, so a circle scaled 20x is sampled finely
 * and a hairline path is not oversampled.
 */
final class Path
{
    /**
     * @var list<array{origin: array{0:float,1:float}, segs: list<array>, closed: bool}>
     */
    private array $subpaths = [];

    /** @var ?array{0:float,1:float} */
    private ?array $current = null;

    public function isEmpty(): bool
    {
        return $this->subpaths === [];
    }

    /** @return list<array{origin: array{0:float,1:float}, segs: list<array>, closed: bool}> */
    public function subpaths(): array
    {
        return $this->subpaths;
    }

    public function clear(): void
    {
        $this->subpaths = [];
        $this->current = null;
    }

    public function moveTo(float $x, float $y): void
    {
        $this->subpaths[] = ['origin' => [$x, $y], 'segs' => [], 'closed' => false];
        $this->current = [$x, $y];
    }

    public function lineTo(float $x, float $y): void
    {
        if ($this->current === null) {
            $this->moveTo($x, $y);
            return;
        }
        $this->pushLine($x, $y);
    }

    public function closePath(): void
    {
        if ($this->subpaths === []) {
            return;
        }
        $i = count($this->subpaths) - 1;
        if ($this->subpaths[$i]['segs'] === []) {
            return; // closePath on a subpath with fewer than two points is a no-op
        }
        $this->subpaths[$i]['closed'] = true;
    }

    public function quadraticCurveTo(float $cx, float $cy, float $x, float $y): void
    {
        if ($this->current === null) {
            $this->moveTo($cx, $cy);
            $this->pushLine($x, $y);
            return;
        }
        $this->pushSeg([1, $cx, $cy, $x, $y], [$x, $y]);
    }

    public function bezierCurveTo(float $c1x, float $c1y, float $c2x, float $c2y, float $x, float $y): void
    {
        if ($this->current === null) {
            // No current point: the curve starts at its first control point and
            // is still a curve, not a polyline through the controls.
            $this->moveTo($c1x, $c1y);
            $this->pushSeg([2, $c1x, $c1y, $c2x, $c2y, $x, $y], [$x, $y]);
            return;
        }
        $this->pushSeg([2, $c1x, $c1y, $c2x, $c2y, $x, $y], [$x, $y]);
    }

    public function arc(
        float $cx,
        float $cy,
        float $radius,
        float $startAngle,
        float $endAngle,
        bool $counterclockwise = false,
    ): void {
        if ($radius < 0.0) {
            throw new \InvalidArgumentException('arc radius must not be negative');
        }
        $this->ellipse($cx, $cy, $radius, $radius, 0.0, $startAngle, $endAngle, $counterclockwise);
    }

    public function ellipse(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $rotation,
        float $startAngle,
        float $endAngle,
        bool $counterclockwise = false,
    ): void {
        if ($rx < 0.0 || $ry < 0.0) {
            throw new \InvalidArgumentException('ellipse radii must not be negative');
        }

        $point = self::ellipsePoint($cx, $cy, $rx, $ry, $rotation, $startAngle);

        if ($this->current !== null) {
            $this->pushLine($point[0], $point[1]);
        } else {
            $this->moveTo($point[0], $point[1]);
        }

        $delta = self::sweep($startAngle, $endAngle, $counterclockwise);
        if ($delta === 0.0) {
            return;
        }
        $end = self::ellipsePoint($cx, $cy, $rx, $ry, $rotation, $startAngle + $delta);
        $this->pushSeg([3, $cx, $cy, $rx, $ry, $rotation, $startAngle, $delta], $end);
    }

    /**
     * The arc sweep between two angles in canvas direction, clamped to one
     * full turn, as the specification defines it.
     */
    public static function sweep(float $start, float $end, bool $ccw): float
    {
        $delta = $end - $start;
        $two = M_PI * 2;
        if (!$ccw) {
            while ($delta < 0.0) {
                $delta += $two;
            }
            if ($delta > $two) {
                $delta = $two;
            }
        } else {
            while ($delta > 0.0) {
                $delta -= $two;
            }
            if ($delta < -$two) {
                $delta = -$two;
            }
        }
        return $delta;
    }

    /** @return array{0:float,1:float} */
    public static function ellipsePoint(float $cx, float $cy, float $rx, float $ry, float $rot, float $angle): array
    {
        $cos = cos($angle);
        $sin = sin($angle);
        return [
            $cx + $rx * $cos * cos($rot) - $ry * $sin * sin($rot),
            $cy + $rx * $cos * sin($rot) + $ry * $sin * cos($rot),
        ];
    }

    public function arcTo(float $x1, float $y1, float $x2, float $y2, float $radius): void
    {
        if ($radius < 0.0) {
            throw new \InvalidArgumentException('arcTo radius must not be negative');
        }
        if ($this->current === null) {
            $this->moveTo($x1, $y1);
            return;
        }
        [$x0, $y0] = $this->current;

        $dx0 = $x0 - $x1;
        $dy0 = $y0 - $y1;
        $dx2 = $x2 - $x1;
        $dy2 = $y2 - $y1;
        $len0 = sqrt($dx0 * $dx0 + $dy0 * $dy0);
        $len2 = sqrt($dx2 * $dx2 + $dy2 * $dy2);

        if ($len0 < 1e-10 || $len2 < 1e-10 || $radius === 0.0) {
            $this->lineTo($x1, $y1);
            return;
        }

        $ux0 = $dx0 / $len0;
        $uy0 = $dy0 / $len0;
        $ux2 = $dx2 / $len2;
        $uy2 = $dy2 / $len2;

        $cross = $ux0 * $uy2 - $uy0 * $ux2;
        $dot = $ux0 * $ux2 + $uy0 * $uy2;
        if (abs($cross) < 1e-10) {
            $this->lineTo($x1, $y1);
            return;
        }
        $dot = max(-1.0, min(1.0, $dot));
        $angle = acos($dot);
        if ($angle < 1e-9 || M_PI - $angle < 1e-9) {
            $this->lineTo($x1, $y1);
            return;
        }

        $tangent = $radius / tan($angle / 2);
        $t1 = [$x1 + $ux0 * $tangent, $y1 + $uy0 * $tangent];
        $t2 = [$x1 + $ux2 * $tangent, $y1 + $uy2 * $tangent];

        $bx = $ux0 + $ux2;
        $by = $uy0 + $uy2;
        $blen = sqrt($bx * $bx + $by * $by);
        $hyp = $radius / sin($angle / 2);
        $cx = $x1 + ($bx / $blen) * $hyp;
        $cy = $y1 + ($by / $blen) * $hyp;

        $a1 = atan2($t1[1] - $cy, $t1[0] - $cx);
        $a2Raw = atan2($t2[1] - $cy, $t2[0] - $cx);

        // Take the minor arc, in the direction that turns from t1 to t2.
        $sweep = $a2Raw - $a1;
        while ($sweep > M_PI) {
            $sweep -= M_PI * 2;
        }
        while ($sweep < -M_PI) {
            $sweep += M_PI * 2;
        }

        if ($t1[0] != $this->current[0] || $t1[1] != $this->current[1]) {
            $this->pushLine($t1[0], $t1[1]);
        }
        $this->pushSeg([3, $cx, $cy, $radius, $radius, 0.0, $a1, $sweep], $t2);
    }

    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->subpaths[] = [
            'origin' => [$x, $y],
            'segs' => [[0, $x + $w, $y], [0, $x + $w, $y + $h], [0, $x, $y + $h]],
            'closed' => true,
        ];
        $this->current = [$x, $y];
    }

    /**
     * roundRect() per the HTML specification: 0-4 radii, scaled down when the
     * corners would overflow, and the special two-arc form when exactly one
     * corner is rounded.
     *
     * @param float|array<int|float> $radii
     */
    public function roundRect(float $x, float $y, float $w, float $h, float|array $radii = 0.0): void
    {
        if ($w < 0.0 || $h < 0.0) {
            throw new \InvalidArgumentException('roundRect requires non-negative dimensions');
        }

        $list = is_array($radii) ? array_values($radii) : [$radii];
        foreach ($list as $r) {
            if (!is_numeric($r) || $r < 0.0) {
                throw new \InvalidArgumentException('roundRect radii must be non-negative numbers');
            }
        }
        $count = count($list);
        $pick = function (int $i) use ($list, $count): float {
            if ($count === 0) {
                return 0.0;
            }
            if ($count <= 2) {
                return (float) $list[min($i, $count - 1) < 0 ? 0 : ($i % $count)];
            }
            // 3: tl, tr/br, bl ; 4: tl, tr, br, bl
            return $count === 3
                ? (float) $list[[0, 1, 1, 2][$i]]
                : (float) $list[$i];
        };

        $tl = $pick(0);
        $tr = $pick(1);
        $br = $pick(2);
        $bl = $pick(3);

        $max = min($w, $h) / 2;
        $sum = max(
            $tl + $tr > 0 ? $tl + $tr : 0,
            $bl + $br > 0 ? $bl + $br : 0,
        );
        $scale = 1.0;
        if ($sum > $w && $w > 0) {
            $scale = min($scale, $w / $sum);
        }
        $sumV = max(
            $tl + $bl > 0 ? $tl + $bl : 0,
            $tr + $br > 0 ? $tr + $br : 0,
        );
        if ($sumV > $h && $h > 0) {
            $scale = min($scale, $h / $sumV);
        }
        unset($max);
        $tl *= $scale;
        $tr *= $scale;
        $br *= $scale;
        $bl *= $scale;

        if ($tl === 0.0 && $tr === 0.0 && $br === 0.0 && $bl === 0.0) {
            $this->rect($x, $y, $w, $h);
            return;
        }

        // Spec: if only one corner is rounded, add an extra arc on each side.
        $rounded = ($tl > 0 ? 1 : 0) + ($tr > 0 ? 1 : 0) + ($br > 0 ? 1 : 0) + ($bl > 0 ? 1 : 0);
        $extraTop = $rounded === 1 && $tl > 0 ? $tl : 0.0;
        $extraRight = $rounded === 1 && $tr > 0 ? $tr : 0.0;
        $extraBottom = $rounded === 1 && $br > 0 ? $br : 0.0;
        $extraLeft = $rounded === 1 && $bl > 0 ? $bl : 0.0;

        $this->moveTo($x + $tl, $y);

        if ($extraTop > 0.0) {
            $this->arc($x + $tl + $extraTop, $y + $extraTop, $extraTop, M_PI, 1.5 * M_PI, false);
        }
        $this->lineTo($x + $w - $tr, $y);
        if ($tr > 0.0) {
            $this->arc($x + $w - $tr, $y + $tr, $tr, -0.5 * M_PI, 0.0, false);
        }
        if ($extraRight > 0.0) {
            $this->arc($x + $w - $extraRight, $y + $tr + $extraRight, $extraRight, -0.5 * M_PI, 0.0, false);
        }
        $this->lineTo($x + $w, $y + $h - $br);
        if ($br > 0.0) {
            $this->arc($x + $w - $br, $y + $h - $br, $br, 0.0, 0.5 * M_PI, false);
        }
        if ($extraBottom > 0.0) {
            $this->arc($x + $w - $br - $extraBottom, $y + $h - $extraBottom, $extraBottom, 0.0, 0.5 * M_PI, false);
        }
        $this->lineTo($x + $bl, $y + $h);
        if ($bl > 0.0) {
            $this->arc($x + $bl, $y + $h - $bl, $bl, 0.5 * M_PI, M_PI, false);
        }
        if ($extraLeft > 0.0) {
            $this->arc($x + $extraLeft, $y + $h - $bl - $extraLeft, $extraLeft, 0.5 * M_PI, M_PI, false);
        }
        $this->lineTo($x, $y + $tl);
        if ($tl > 0.0) {
            $this->arc($x + $tl, $y + $tl, $tl, M_PI, 1.5 * M_PI, false);
        }
        $this->closePath();
    }

    /**
     * Flatten to polygons, mapped through $matrix into device space.
     *
     * Curves are subdivided until every segment lies within $tolerance device
     * pixels of the true curve, so quality follows the CTM rather than being
     * decided when the path was built.
     *
     * @return list<array{pts: list<float>, closed: bool}>
     */
    public function flatten(Matrix $matrix, float $tolerance = 0.12): array
    {
        $tol = max(0.005, $tolerance);
        $out = [];
        foreach ($this->subpaths as $sub) {
            $pts = [];
            self::pushPoint($pts, $matrix, $sub['origin'][0], $sub['origin'][1]);
            foreach ($sub['segs'] as $seg) {
                switch ($seg[0]) {
                    case 0:
                        self::pushPoint($pts, $matrix, $seg[1], $seg[2]);
                        break 1;
                    case 1:
                        $this->flattenQuad($pts, $matrix, $seg);
                        break 1;
                    case 2:
                        $this->flattenCubic($pts, $matrix, $seg);
                        break 1;
                    case 3:
                        $this->flattenArc($pts, $matrix, $seg, $tol);
                        break 1;
                }
            }
            $count = intdiv(count($pts), 2);
            if ($count >= 2) {
                $out[] = ['pts' => $pts, 'closed' => (bool) $sub['closed']];
            }
        }
        return $out;
    }

    /** @param list<float> $pts */
    private static function pushPoint(array &$pts, Matrix $m, float $x, float $y): void
    {
        $p = $m->mapPoint($x, $y);
        $n = count($pts);
        if ($n >= 2) {
            // Drop duplicate points: they produce degenerate edges that make
            // the join machinery stutter.
            if (abs($p['x'] - $pts[$n - 2]) < 1e-9 && abs($p['y'] - $pts[$n - 1]) < 1e-9) {
                return;
            }
        }
        $pts[] = $p['x'];
        $pts[] = $p['y'];
    }

    private function flattenQuad(array &$pts, Matrix $m, array $seg): void
    {
        $last = self::lastPoint($pts);
        $x0 = $last[0];
        $y0 = $last[1];
        $c = $m->mapPoint($seg[1], $seg[2]);
        $e = $m->mapPoint($seg[3], $seg[4]);
        $this->subdivideQuad($pts, $m, $x0, $y0, $c['x'], $c['y'], $e['x'], $e['y'], 0);
    }

    private function subdivideQuad(array &$pts, Matrix $m, float $x0, float $y0, float $cx, float $cy, float $x1, float $y1, int $depth): void
    {
        // Flatness: distance of the control point from the chord.
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 > 1e-12) {
            $t = (($cx - $x0) * $dx + ($cy - $y0) * $dy) / $len2;
            $t = max(0.0, min(1.0, $t));
            $px = $x0 + $t * $dx;
            $py = $y0 + $t * $dy;
            $dist = sqrt(($cx - $px) ** 2 + ($cy - $py) ** 2);
        } else {
            $dist = sqrt(($cx - $x0) ** 2 + ($cy - $y0) ** 2);
        }

        if ($dist <= 0.15 || $depth > 12) {
            $pts[] = $x1;
            $pts[] = $y1;
            return;
        }

        $mx0 = ($x0 + $cx) / 2;
        $my0 = ($y0 + $cy) / 2;
        $mx1 = ($cx + $x1) / 2;
        $my1 = ($cy + $y1) / 2;
        $nx = ($mx0 + $mx1) / 2;
        $ny = ($my0 + $my1) / 2;

        $this->subdivideQuad($pts, $m, $x0, $y0, $mx0, $my0, $nx, $ny, $depth + 1);
        $this->subdivideQuad($pts, $m, $nx, $ny, $mx1, $my1, $x1, $y1, $depth + 1);
    }

    private function flattenCubic(array &$pts, Matrix $m, array $seg): void
    {
        $last = self::lastPoint($pts);
        $x0 = $last[0];
        $y0 = $last[1];
        $c1 = $m->mapPoint($seg[1], $seg[2]);
        $c2 = $m->mapPoint($seg[3], $seg[4]);
        $e = $m->mapPoint($seg[5], $seg[6]);
        $this->subdivideCubic($pts, $x0, $y0, $c1['x'], $c1['y'], $c2['x'], $c2['y'], $e['x'], $e['y'], 0);
    }

    private function subdivideCubic(
        array &$pts,
        float $x0,
        float $y0,
        float $c1x,
        float $c1y,
        float $c2x,
        float $c2y,
        float $x1,
        float $y1,
        int $depth,
    ): void {
        $d1 = self::pointLineDistance($c1x, $c1y, $x0, $y0, $x1, $y1);
        $d2 = self::pointLineDistance($c2x, $c2y, $x0, $y0, $x1, $y1);
        $chord = sqrt(($x1 - $x0) ** 2 + ($y1 - $y0) ** 2);

        if (($d1 + $d2) <= 0.15 || ($chord < 1e-6 && ($d1 + $d2) < 0.05) || $depth > 14) {
            $pts[] = $x1;
            $pts[] = $y1;
            return;
        }

        $a1x = ($x0 + $c1x) / 2;
        $a1y = ($y0 + $c1y) / 2;
        $a2x = ($c1x + $c2x) / 2;
        $a2y = ($c1y + $c2y) / 2;
        $a3x = ($c2x + $x1) / 2;
        $a3y = ($c2y + $y1) / 2;
        $b1x = ($a1x + $a2x) / 2;
        $b1y = ($a1y + $a2y) / 2;
        $b2x = ($a2x + $a3x) / 2;
        $b2y = ($a2y + $a3y) / 2;
        $cx = ($b1x + $b2x) / 2;
        $cy = ($b1y + $b2y) / 2;

        $this->subdivideCubic($pts, $x0, $y0, $a1x, $a1y, $b1x, $b1y, $cx, $cy, $depth + 1);
        $this->subdivideCubic($pts, $cx, $cy, $b2x, $b2y, $a3x, $a3y, $x1, $y1, $depth + 1);
    }

    private function flattenArc(array &$pts, Matrix $m, array $seg, float $tol): void
    {
        [, $cxu, $cyu, $rx, $ry, $rot, $start, $delta] = $seg;
        if ($delta === 0.0 || ($rx === 0.0 && $ry === 0.0)) {
            return;
        }

        // Step size from the largest local radius, measured in device space.
        $scale = max(0.0001, $m->averageScale());
        $maxRadius = max($rx, $ry) * $scale;
        $ratio = 1.0 - min(0.9999, $tol / $maxRadius);
        $step = acos(max(-1.0, min(1.0, $ratio)));
        if ($step <= 0.0 || !is_finite($step)) {
            $step = 0.02;
        }
        $steps = max(1, (int) ceil(abs($delta) / $step));
        $steps = min($steps, 4096);

        for ($i = 1; $i <= $steps; $i++) {
            $angle = $start + $delta * ($i / $steps);
            $p = self::ellipsePoint($cxu, $cyu, $rx, $ry, $rot, $angle);
            self::pushPoint($pts, $m, $p[0], $p[1]);
        }
    }

    /** @return array{0:float,1:float} */
    private static function lastPoint(array $pts): array
    {
        $n = count($pts);
        return $n >= 2 ? [$pts[$n - 2], $pts[$n - 1]] : [0.0, 0.0];
    }

    private static function pointLineDistance(float $px, float $py, float $x0, float $y0, float $x1, float $y1): float
    {
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 <= 1e-12) {
            return sqrt(($px - $x0) ** 2 + ($py - $y0) ** 2);
        }
        $t = (($px - $x0) * $dx + ($py - $y0) * $dy) / $len2;
        $t = max(0.0, min(1.0, $t));
        return sqrt(($px - ($x0 + $t * $dx)) ** 2 + ($py - ($y0 + $t * $dy)) ** 2);
    }

    private function pushLine(float $x, float $y): void
    {
        $this->pushSeg([0, $x, $y], [$x, $y]);
    }

    private function pushSeg(array $seg, array $endPoint): void
    {
        if ($this->subpaths === []) {
            $this->moveTo($endPoint[0], $endPoint[1]);
            return;
        }
        $i = count($this->subpaths) - 1;
        $this->subpaths[$i]['segs'][] = $seg;
        $this->current = [$endPoint[0], $endPoint[1]];
    }
}
