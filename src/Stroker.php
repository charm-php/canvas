<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * Turns a stroked path into fillable geometry.
 *
 * Outlines are built in user space and mapped by the CTM afterwards, which is
 * what the canvas model requires: stroke width is a user-space measure, so a
 * non-uniform scale thickens a line directionally instead of rescaling an
 * already-rasterised line.
 *
 * A stroke piece becomes one polygon: the +normal boundary from the first
 * vertex to the last, the end cap, the -normal boundary back, and the start
 * cap.  Caps and joins add points; nothing is rasterised here.
 */
final class Stroker
{
    private float $half;
    private string $cap;
    private string $join;
    private float $miterLimit;
    /** @var list<float> */
    private array $dash;

    /**
     * @param list<array{pts: list<float>, closed: bool}> $polylines
     * @param list<float> $dash
     * @return list<list<float>>
     */
    public static function build(
        array $polylines,
        float $width,
        string $cap,
        string $join,
        float $miterLimit,
        array $dash = [],
        float $dashOffset = 0.0,
    ): array {
        if ($width <= 0.0 || $polylines === []) {
            return [];
        }
        return (new self($width, $cap, $join, $miterLimit, $dash))->outlineAll($polylines, $dashOffset);
    }

    /** @param list<float> $dash */
    private function __construct(float $width, string $cap, string $join, float $miterLimit, array $dash)
    {
        $this->half = $width / 2;
        $this->cap = $cap;
        $this->join = $join;
        $this->miterLimit = max(1.0, $miterLimit);

        $clean = [];
        foreach ($dash as $d) {
            if (is_numeric($d) && $d > 0.0) {
                $clean[] = (float) $d;
            }
        }
        if ($clean === [] || array_sum($clean) <= 0.0) {
            $this->dash = [];
        } else {
            // An odd-length dash pattern repeats itself.
            $this->dash = count($clean) % 2 === 1 ? array_merge($clean, $clean) : $clean;
        }
    }

    private function outlineAll(array $polylines, float $dashOffset): array
    {
        $out = [];
        foreach ($polylines as $line) {
            $closed = (bool) $line['closed'];
            $pts = self::dedupe($line['pts'], $closed);
            if (count($pts) < 2) {
                continue;
            }
            if ($this->dash !== []) {
                foreach ($this->dashPieces($pts, $closed, $dashOffset) as $piece) {
                    $this->pieceOutline($piece, false, $out);
                }
                continue;
            }
            $this->pieceOutline($pts, $closed, $out);
        }
        return $out;
    }

    /** March the path, cutting it into the dash pattern's "on" runs. */
    private function dashPieces(array $pts, bool $closed, float $offset): array
    {
        $pattern = $this->dash;
        $pn = count($pattern);
        $period = array_sum($pattern);
        $index = 0;
        $remain = $pattern[0];
        $on = true;

        $skip = fmod(max(0.0, $offset), $period);
        while ($skip > 1e-9) {
            if ($skip < $remain) {
                $remain -= $skip;
                $skip = 0.0;
            } else {
                $skip -= $remain;
                $index = ($index + 1) % $pn;
                $remain = $pattern[$index];
                $on = !$on;
            }
        }

        $pieces = [];
        $current = [];
        $n = intdiv(count($pts), 2);
        $limit = $closed ? $n : $n - 1;

        for ($i = 0; $i < $limit; $i++) {
            $j = ($i + 1) % $n;
            $x0 = $pts[$i * 2];
            $y0 = $pts[$i * 2 + 1];
            $dx = $pts[$j * 2] - $x0;
            $dy = $pts[$j * 2 + 1] - $y0;
            $segLen = hypot($dx, $dy);
            if ($segLen <= 1e-9) {
                continue;
            }
            $travelled = 0.0;
            while ($travelled < $segLen - 1e-9) {
                if ($remain <= 1e-9) {
                    if ($on && count($current) >= 4) {
                        $pieces[] = $current;
                    }
                    $index = ($index + 1) % $pn;
                    $remain = $pattern[$index];
                    $on = !$on;
                    if ($on) {
                        $t = $travelled / $segLen;
                        $current = [$x0 + $dx * $t, $y0 + $dy * $t];
                    }
                    continue;
                }
                $step = min($remain, $segLen - $travelled);
                if ($on) {
                    if ($current === []) {
                        $t0 = $travelled / $segLen;
                        $current = [$x0 + $dx * $t0, $y0 + $dy * $t0];
                    }
                    $t1 = ($travelled + $step) / $segLen;
                    $current[] = $x0 + $dx * $t1;
                    $current[] = $y0 + $dy * $t1;
                }
                $remain -= $step;
                $travelled += $step;
            }
        }
        if (count($current) >= 4) {
            $pieces[] = $current;
        }
        return $pieces;
    }

    /**
     * @param list<float> $pts  consecutive points, no duplicates
     * @param-out list<list<float>> $out
     */
    private function pieceOutline(array $pts, bool $closed, array &$out): void
    {
        $n = intdiv(count($pts), 2);
        if ($n < 1) {
            return;
        }
        $segCount = $closed ? $n : $n - 1;
        if ($segCount < 1) {
            return;
        }

        $dirs = [];
        $total = 0.0;
        for ($i = 0; $i < $segCount; $i++) {
            $j = ($i + 1) % $n;
            $dx = $pts[$j * 2] - $pts[$i * 2];
            $dy = $pts[$j * 2 + 1] - $pts[$i * 2 + 1];
            $len = hypot($dx, $dy);
            $total += $len;
            $dirs[] = $len <= 1e-9 ? null : [$dx / $len, $dy / $len];
        }

        if ($total <= 1e-9) {
            // Zero-length subpath: only round caps and joins paint a dot.
            if ($this->cap === 'round' || $this->join === 'round') {
                $out[] = self::circle($pts[0], $pts[1], $this->half);
            }
            return;
        }

        $first = null;
        foreach ($dirs as $d) {
            if ($d !== null) {
                $first = $d;
                break;
            }
        }
        $lastDir = null;
        for ($i = count($dirs) - 1; $i >= 0; $i--) {
            if ($dirs[$i] !== null) {
                $lastDir = $dirs[$i];
                break;
            }
        }
        if ($first === null || $lastDir === null) {
            return;
        }

        $plus = [];
        $minus = [];

        for ($i = 0; $i < $n; $i++) {
            $x = $pts[$i * 2];
            $y = $pts[$i * 2 + 1];

            $din = null;
            for ($k = $i - 1; $k >= ($closed ? -$segCount : 0); $k--) {
                $idx = $closed ? (($k % $segCount) + $segCount) % $segCount : $k;
                if (isset($dirs[$idx]) && $dirs[$idx] !== null) {
                    $din = $dirs[$idx];
                    break;
                }
            }
            $dout = null;
            for ($k = $i; $k < ($closed ? $i + $segCount : $segCount); $k++) {
                $idx = $closed ? $k % $segCount : $k;
                if (isset($dirs[$idx]) && $dirs[$idx] !== null) {
                    $dout = $dirs[$idx];
                    break;
                }
            }

            $atStart = !$closed && $i === 0;
            $atEnd = !$closed && $i === $n - 1;

            if ($atStart || $atEnd || $din === null || $dout === null) {
                // Endpoint (or a single-segment piece): the cap owns it.
                $d = $din ?? $dout;
                if ($d === null) {
                    continue;
                }
                $shift = 0.0;
                if ($this->cap === 'square' && ($atStart || $atEnd)) {
                    $shift = $atStart ? -$this->half : $this->half;
                }
                $px = $x + $d[0] * $shift;
                $py = $y + $d[1] * $shift;
                $plus[] = $px + $d[1] * $this->half;
                $plus[] = $py - $d[0] * $this->half;
                $minus[] = $px - $d[1] * $this->half;
                $minus[] = $py + $d[0] * $this->half;
                continue;
            }

            $c = max(-1.0, min(1.0, $din[0] * $dout[0] + $din[1] * $dout[1]));
            if ($c >= 0.999999) {
                $this->sidePoint($plus, $x, $y, $dout, 1.0);
                $this->sidePoint($minus, $x, $y, $dout, -1.0);
                continue;
            }

            $cross = $din[0] * $dout[1] - $din[1] * $dout[0];
            $plusIsOuter = $cross > 0.0;
            $outer = $plusIsOuter ? 1.0 : -1.0;
            $inner = -$outer;

            // Inner side always mitres, which keeps the band gap-free.
            $this->mitrePoint($plus, $minus, $inner, $x, $y, $din, $dout, $c);

            $ratio = sqrt(2 / (1 + $c));
            if ($this->join === 'miter' && $ratio <= $this->miterLimit) {
                $this->mitrePoint($plus, $minus, $outer, $x, $y, $din, $dout, $c);
            } elseif ($this->join === 'round') {
                $nin = [$din[1], -$din[0]];
                $a0 = atan2($outer * $nin[1], $outer * $nin[0]);
                $sweep = $outer * acos($c);
                $steps = $this->arcSteps(abs($sweep), $this->half);
                for ($k = 0; $k <= $steps; $k++) {
                    $a = $a0 + $sweep * ($k / $steps);
                    $px = $x + cos($a) * $this->half;
                    $py = $y + sin($a) * $this->half;
                    if ($plusIsOuter) {
                        $plus[] = $px;
                        $plus[] = $py;
                    } else {
                        $minus[] = $px;
                        $minus[] = $py;
                    }
                }
            } else {
                // Bevel: both offset endpoints, joined by a straight edge.
                $this->sidePoint($plusIsOuter ? $plus : $minus, $x, $y, $din, $outer);
                $this->sidePoint($plusIsOuter ? $plus : $minus, $x, $y, $dout, $outer);
            }
        }

        if (count($plus) < 4 || count($minus) < 4) {
            return;
        }

        $poly = $plus;
        if (!$closed) {
            $this->capArc($poly, $pts[($n - 1) * 2], $pts[($n - 1) * 2 + 1], $lastDir, true);
        }
        for ($i = intdiv(count($minus), 2) - 1; $i >= 0; $i--) {
            $poly[] = $minus[$i * 2];
            $poly[] = $minus[$i * 2 + 1];
        }
        if (!$closed) {
            $this->capArc($poly, $pts[0], $pts[1], $first, false);
        }

        if (intdiv(count($poly), 2) >= 3) {
            $out[] = $poly;
        }
    }

    /**
     * @param list<float> $to
     * @param array{0:float,1:float} $d
     */
    private function sidePoint(array &$to, float $x, float $y, array $d, float $side): void
    {
        $to[] = $x + $side * $d[1] * $this->half;
        $to[] = $y - $side * $d[0] * $this->half;
    }

    /**
     * @param list<float> $plus
     * @param list<float> $minus
     * @param array{0:float,1:float} $din
     * @param array{0:float,1:float} $dout
     */
    private function mitrePoint(array &$plus, array &$minus, float $side, float $x, float $y, array $din, array $dout, float $c): void
    {
        $nin = [$din[1], -$din[0]];
        $nout = [$dout[1], -$dout[0]];
        $k = $this->half / (1 + $c);
        $px = $x + $side * ($nin[0] + $nout[0]) * $k;
        $py = $y + $side * ($nin[1] + $nout[1]) * $k;
        if ($side > 0.0) {
            $plus[] = $px;
            $plus[] = $py;
        } else {
            $minus[] = $px;
            $minus[] = $py;
        }
    }

    /**
     * Round cap arc, appended in polygon order.
     *
     * @param array{0:float,1:float} $d
     * @param bool $atEnd  end of the piece (sweep 0..+pi) or start (-pi..0)
     */
    private function capArc(array &$poly, float $x, float $y, array $d, bool $atEnd): void
    {
        if ($this->cap !== 'round') {
            return;
        }
        $n = [$d[1], -$d[0]];
        $a0 = atan2($n[1], $n[0]);
        $sweep = $atEnd ? M_PI : -M_PI;
        $steps = $this->arcSteps(M_PI, $this->half);
        // Skip the first point: it duplicates the boundary point already in
        // the polygon.
        for ($k = 1; $k <= $steps; $k++) {
            $a = $a0 + $sweep * ($k / $steps);
            $poly[] = $x + cos($a) * $this->half;
            $poly[] = $y + sin($a) * $this->half;
        }
    }

    /** Segment count for an arc of $angle radians and radius $radius. */
    private function arcSteps(float $angle, float $radius): int
    {
        if ($radius <= 0.0) {
            return 1;
        }
        $tol = 0.12;
        $ratio = 1.0 - min(0.999, $tol / $radius);
        $step = acos(max(-1.0, min(1.0, $ratio)));
        if ($step <= 0.001 || !is_finite($step)) {
            $step = 0.05;
        }
        return max(2, (int) ceil($angle / $step));
    }

    private static function circle(float $x, float $y, float $r): array
    {
        $steps = max(8, (int) ceil(M_PI * 2 * max(1.0, $r) / 8));
        $poly = [];
        for ($i = 0; $i < $steps; $i++) {
            $a = $i / $steps * M_PI * 2;
            $poly[] = $x + cos($a) * $r;
            $poly[] = $y + sin($a) * $r;
        }
        return $poly;
    }

    /** @param list<float> $pts */
    private static function dedupe(array $pts, bool $closed): array
    {
        $out = [];
        $n = intdiv(count($pts), 2);
        for ($i = 0; $i < $n; $i++) {
            $x = $pts[$i * 2];
            $y = $pts[$i * 2 + 1];
            $m = count($out);
            if ($m >= 2 && abs($x - $out[$m - 2]) < 1e-9 && abs($y - $out[$m - 1]) < 1e-9) {
                continue;
            }
            $out[] = $x;
            $out[] = $y;
        }
        if ($closed && count($out) >= 6) {
            // A closed ring should not repeat its first vertex: the rasteriser
            // closes it.
            if (abs($out[0] - $out[count($out) - 2]) < 1e-9
                && abs($out[1] - $out[count($out) - 1]) < 1e-9) {
                array_splice($out, count($out) - 2);
            }
        }
        return $out;
    }
}
