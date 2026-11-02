<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * Scanline coverage rasteriser.
 *
 * Coverage is what makes the difference between "a filled polygon" and HTML5
 * antialiasing: a pixel's value is the fraction of its area inside the shape,
 * so geometry that lands on pixel boundaries must produce full-coverage spans
 * and diagonals must produce partial ones.
 *
 * Each pixel row is sampled at $samples evenly spaced positions; within a
 * sample the horizontal span extents are exact, which makes coverage exact for
 * axis-aligned geometry and close for oblique edges.
 */
final class Raster
{
    public const NONZERO = 'nonzero';
    public const EVENODD = 'evenodd';

    /**
     * Walk every affected pixel row of a polygon set.
     *
     * @param list<list<float>> $polygons  each polygon a flat [x0,y0,x1,y1,...] list
     * @param self::NONZERO|self::EVENODD $rule
     * @param int $width   surface width in pixels (bounds the work)
     * @param int $height  surface height in pixels
     * @param int $samples vertical samples per pixel row (>= 1)
     * @param callable(int, array<int,float>, int, int, int, int):void $rowFn
     *        receives (row index, coverage map keyed by absolute column, minX, maxX, fullMinX, fullMaxX)
     *        where fullMinX..fullMaxX is the inclusive range guaranteed to be
     *        fully covered (only sound when every sample produced one span).
     */
    public static function walk(
        array $polygons,
        string $rule,
        int $width,
        int $height,
        int $samples,
        callable $rowFn,
    ): void {
        if ($polygons === [] || $width <= 0 || $height <= 0) {
            return;
        }
        $samples = max(1, $samples);

        // ---- build the edge table -------------------------------------
        $edges = [];
        $topMost = null;
        $bottomMost = null;
        foreach ($polygons as $flat) {
            $n = count($flat);
            if ($n < 6) {
                continue;
            }
            $closedX = $flat[$n - 2];
            $closedY = $flat[$n - 1];
            if (abs($closedX - $flat[0]) < 1e-9 && abs($closedY - $flat[1]) < 1e-9) {
                $n -= 2; // drop the duplicated closing vertex
            }
            if ($n < 6) {
                continue;
            }
            for ($i = 0; $i < $n; $i += 2) {
                $x0 = $flat[$i];
                $y0 = $flat[$i + 1];
                $j = $i + 2;
                if ($j >= $n) {
                    $j = 0;
                }
                $x1 = $flat[$j];
                $y1 = $flat[$j + 1];
                if ($y0 === $y1) {
                    continue; // horizontal edges never cross a scanline
                }
                if ($y0 < $y1) {
                    $edges[] = [
                        'y0' => $y0, 'y1' => $y1, 'x0' => $x0,
                        'dx' => ($x1 - $x0) / ($y1 - $y0), 'dir' => 1,
                    ];
                } else {
                    $edges[] = [
                        'y0' => $y1, 'y1' => $y0, 'x0' => $x1,
                        'dx' => ($x0 - $x1) / ($y0 - $y1), 'dir' => -1,
                    ];
                }
            }
        }
        if ($edges === []) {
            return;
        }

        foreach ($edges as $e) {
            $topMost = $topMost === null ? $e['y0'] : min($topMost, $e['y0']);
            $bottomMost = $bottomMost === null ? $e['y1'] : max($bottomMost, $e['y1']);
        }

        $yStart = max(0, (int) floor($topMost));
        $yEnd = min($height - 1, (int) ceil($bottomMost) - 1);
        if ($yStart > $yEnd) {
            return;
        }

        usort($edges, fn(array $a, array $b): int => $a['y0'] <=> $b['y0']);
        $edgeCount = count($edges);
        $nextEdge = 0;
        $active = [];
        $nonZero = $rule !== self::EVENODD;

        for ($y = $yStart; $y <= $yEnd; $y++) {
            $rowTop = (float) $y;
            $rowBottom = $rowTop + 1.0;

            while ($nextEdge < $edgeCount && $edges[$nextEdge]['y0'] < $rowBottom) {
                if ($edges[$nextEdge]['y1'] > $rowTop) {
                    $active[] = $edges[$nextEdge];
                }
                $nextEdge++;
            }
            foreach ($active as $k => $e) {
                if ($e['y1'] <= $rowTop) {
                    unset($active[$k]);
                }
            }
            if ($active === []) {
                continue;
            }

            $cov = [];
            $minX = PHP_INT_MAX;
            $maxX = PHP_INT_MIN;
            $spanCount = 0;
            $fullMin = PHP_INT_MAX;
            $fullMax = PHP_INT_MIN;
            $singleSpanEverySample = true;
            $any = false;

            for ($s = 0; $s < $samples; $s++) {
                $ys = $rowTop + (($s + 0.5) / $samples);

                $crossings = [];
                foreach ($active as $e) {
                    if ($ys >= $e['y0'] && $ys < $e['y1']) {
                        $crossings[] = [$e['x0'] + ($ys - $e['y0']) * $e['dx'], $e['dir']];
                    }
                }
                if ($crossings === []) {
                    $singleSpanEverySample = false;
                    continue;
                }
                usort($crossings, fn(array $a, array $b): int => $a[0] <=> $b[0]);

                $spans = self::spans($crossings, $nonZero);
                if (count($spans) !== 1) {
                    $singleSpanEverySample = false;
                }
                $spanCount += count($spans);
                if ($spans === []) {
                    continue;
                }
                $any = true;

                foreach ($spans as [$xa, $xb]) {
                    if ($xb <= 0.0 || $xa >= (float) $width) {
                        continue;
                    }
                    if ($xa < $minX) {
                        $minX = (int) floor($xa);
                    }
                    if ($xb > $maxX) {
                        $maxX = (int) (ceil($xb) - 1);
                    }
                    $first = (int) floor($xa);
                    $last = (int) ceil($xb) - 1;
                    for ($c = $first; $c <= $last; $c++) {
                        $left = $xa > $c ? $xa : (float) $c;
                        $right = $xb < $c + 1 ? $xb : (float) ($c + 1);
                        $overlap = $right - $left;
                        if ($overlap <= 0.0) {
                            continue;
                        }
                        $cov[$c] = ($cov[$c] ?? 0.0) + $overlap / $samples;
                    }
                }
            }

            if (!$any) {
                continue;
            }

            if ($singleSpanEverySample && $minX <= $maxX) {
                // With one span per sample the fully covered columns form one
                // contiguous run, which callers can paint without per-pixel
                // coverage work.
                $fullMin = self::firstFullColumn($cov);
                $fullMax = self::lastFullColumn($cov);
                if ($fullMin > $fullMax) {
                    $fullMin = 1;
                    $fullMax = 0;
                }
            } else {
                $fullMin = 1;
                $fullMax = 0;
            }

            $rowFn($y, $cov, $minX, $maxX, $fullMin, $fullMax);
        }
    }

    /**
     * Turn sorted crossings into interior spans.
     *
     * @param list<array{0:float,1:int}> $crossings
     * @return list<array{0:float,1:float}>
     */
    private static function spans(array $crossings, bool $nonZero): array
    {
        $spans = [];
        if ($nonZero) {
            $w = 0;
            $start = null;
            foreach ($crossings as [$x, $dir]) {
                $before = $w;
                $w += $dir;
                if ($before === 0 && $w !== 0) {
                    $start = $x;
                } elseif ($before !== 0 && $w === 0 && $start !== null) {
                    $spans[] = [$start, $x];
                    $start = null;
                }
            }
            return $spans;
        }

        $n = count($crossings);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $spans[] = [$crossings[$i][0], $crossings[$i + 1][0]];
        }
        return $spans;
    }

    /**
     * Point-in-path test using the given fill rule.
     *
     * @param list<list<float>> $polygons
     */
    public static function contains(array $polygons, float $x, float $y, string $rule = self::NONZERO): bool
    {
        $nonZero = $rule !== self::EVENODD;
        $winding = 0;
        $crossings = 0;

        // A point exactly on the outline counts as inside, which is what
        // browsers report for isPointInPath on an integer boundary.
        $eps = 1e-9;
        foreach ($polygons as $flat) {
            $n = count($flat);
            for ($i = 0; $i + 3 < $n; $i += 2) {
                $x0 = $flat[$i];
                $y0 = $flat[$i + 1];
                $x1 = $flat[$i + 2];
                $y1 = $flat[$i + 3];
                $cross = ($x1 - $x0) * ($y - $y0) - ($y1 - $y0) * ($x - $x0);
                if (abs($cross) <= $eps
                    && $x >= min($x0, $x1) - $eps && $x <= max($x0, $x1) + $eps
                    && $y >= min($y0, $y1) - $eps && $y <= max($y0, $y1) + $eps) {
                    return true;
                }
            }
        }

        foreach ($polygons as $flat) {
            $n = count($flat);
            if ($n < 6) {
                continue;
            }
            for ($i = 0; $i < $n; $i += 2) {
                $j = $i + 2;
                if ($j >= $n) {
                    $j = 0;
                }
                $x0 = $flat[$i];
                $y0 = $flat[$i + 1];
                $x1 = $flat[$j];
                $y1 = $flat[$j + 1];
                if (($y0 <= $y && $y1 > $y) || ($y1 <= $y && $y0 > $y)) {
                    $t = ($y - $y0) / ($y1 - $y0);
                    $xi = $x0 + $t * ($x1 - $x0);
                    if ($xi > $x) {
                        if ($y0 < $y1) {
                            $winding++;
                        } else {
                            $winding--;
                        }
                        $crossings++;
                    }
                }
            }
        }
        return $nonZero ? $winding !== 0 : ($crossings % 2 === 1);
    }

    private static function firstFullColumn(array $cov): int
    {
        foreach ($cov as $c => $v) {
            if ($v >= 0.999999) {
                return (int) $c;
            }
        }
        return PHP_INT_MAX;
    }

    private static function lastFullColumn(array $cov): int
    {
        $right = PHP_INT_MIN;
        foreach ($cov as $c => $v) {
            if ($v >= 0.999999) {
                $right = (int) $c;
            }
        }
        return $right;
    }
}
