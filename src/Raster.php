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
     * @param callable(int, array<int,float>, int, int, list<array{0:int,1:int}>):void $rowFn
     *        receives (row index, coverage map keyed by absolute column, minX, maxX, runs)
     *        where each run is an inclusive [from, to] range of fully covered
     *        columns.  The map holds every other covered column and omits the
     *        columns of the runs.
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

        // ---- build the edge table (parallel arrays, sorted by top y) -------
        $ey0 = $ey1 = $ex0 = $edx = $edir = [];
        foreach ($polygons as $flat) {
            $n = count($flat);
            if ($n < 6) {
                continue;
            }
            if (abs($flat[$n - 2] - $flat[0]) < 1e-9 && abs($flat[$n - 1] - $flat[1]) < 1e-9) {
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
                    $ey0[] = $y0;
                    $ey1[] = $y1;
                    $ex0[] = $x0;
                    $edx[] = ($x1 - $x0) / ($y1 - $y0);
                    $edir[] = 1;
                } else {
                    $ey0[] = $y1;
                    $ey1[] = $y0;
                    $ex0[] = $x1;
                    $edx[] = ($x0 - $x1) / ($y0 - $y1);
                    $edir[] = -1;
                }
            }
        }
        $edgeCount = count($ey0);
        if ($edgeCount === 0) {
            return;
        }

        $yStart = max(0, (int) floor(min($ey0)));
        $yEnd = min($height - 1, (int) ceil(max($ey1)) - 1);
        if ($yStart > $yEnd) {
            return;
        }

        array_multisort($ey0, SORT_ASC, SORT_NUMERIC, $ey1, $ex0, $edx, $edir);
        $nextEdge = 0;
        $active = [];
        $nonZero = $rule !== self::EVENODD;
        $fw = (float) $width;
        $inv = 1.0 / $samples;
        $ysAt = [];

        for ($y = $yStart; $y <= $yEnd; $y++) {
            $rowTop = (float) $y;
            $rowBottom = $rowTop + 1.0;
            for ($s = 0; $s < $samples; $s++) {
                $ysAt[$s] = $rowTop + ($s + 0.5) * $inv;
            }

            while ($nextEdge < $edgeCount && $ey0[$nextEdge] < $rowBottom) {
                if ($ey1[$nextEdge] > $rowTop) {
                    $active[] = $nextEdge;
                }
                $nextEdge++;
            }
            foreach ($active as $k => $e) {
                if ($ey1[$e] <= $rowTop) {
                    unset($active[$k]);
                }
            }
            if ($active === []) {
                continue;
            }

            // part[c]: summed horizontal overlap of the spans with cell c at
            // the span ends; diff[c]: change in the number of samples that
            // cover cells from c onward completely.  Cost is per edge, not
            // per column.
            $part = [];
            $diff = [];

            // When every active edge spans the whole row and none crosses
            // another inside it, the left-to-right order found at the first
            // sample holds for all samples: sort once, then only evaluate.
            $ordered = null;
            $full = true;
            foreach ($active as $e) {
                if ($ey0[$e] > $ysAt[0] || $ey1[$e] <= $ysAt[$samples - 1]) {
                    $full = false;
                    break;
                }
            }
            if ($full) {
                $x1s = [];
                foreach ($active as $e) {
                    $x1s[$e] = $ex0[$e] + ($ysAt[0] - $ey0[$e]) * $edx[$e];
                }
                asort($x1s);
                $ordered = array_keys($x1s);
                $prevX = -INF;
                $ys = $ysAt[$samples - 1];
                foreach ($ordered as $e) {
                    $x = $ex0[$e] + ($ys - $ey0[$e]) * $edx[$e];
                    if ($x < $prevX) {
                        $ordered = null;
                        break;
                    }
                    $prevX = $x;
                }
            }
            if ($ordered !== null) {
                // Pair the edges into spans once for the whole row.
                $w = 0;
                $odd = false;
                $ea = -1;
                $pairs = [];
                foreach ($ordered as $e) {
                    if ($nonZero) {
                        $before = $w;
                        $w += $edir[$e];
                        if ($before === 0 && $w !== 0) {
                            $ea = $e;
                        } elseif ($before !== 0 && $w === 0) {
                            $pairs[] = [$ea, $e];
                        }
                    } else {
                        $odd = !$odd;
                        if ($odd) {
                            $ea = $e;
                        } else {
                            $pairs[] = [$ea, $e];
                        }
                    }
                }
                $ymid = $rowTop + 0.5;
                $yFirst = $ysAt[0];
                $yLast = $ysAt[$samples - 1];
                foreach ($pairs as [$ea, $eb]) {
                    $a0 = $ex0[$ea] - $ey0[$ea] * $edx[$ea];
                    $b0 = $ex0[$eb] - $ey0[$eb] * $edx[$eb];
                    $da = $edx[$ea];
                    $db = $edx[$eb];
                    $xa0 = $a0 + $yFirst * $da;
                    $xa1 = $a0 + $yLast * $da;
                    $xb0 = $b0 + $yFirst * $db;
                    $xb1 = $b0 + $yLast * $db;
                    // Both ends move linearly.  If neither changes cell or
                    // reaches the surface edge during the row, the samples'
                    // contributions are summed from the mean positions.
                    if ($xa0 >= 0.0 && $xa1 >= 0.0 && $xb0 <= $fw && $xb1 <= $fw
                        && $xb0 > $xa0 && $xb1 > $xa1
                        && (int) $xa0 === (int) $xa1
                        && (int) ceil($xb0) === (int) ceil($xb1)) {
                        $ca = (int) $xa0;
                        $cb = (int) ceil($xb0) - 1;
                        $ma = $a0 + $ymid * $da;
                        $mb = $b0 + $ymid * $db;
                        if ($ca === $cb) {
                            $part[$ca] = ($part[$ca] ?? 0.0) + $samples * ($mb - $ma);
                            continue;
                        }
                        $part[$ca] = ($part[$ca] ?? 0.0) + $samples * ($ca + 1 - $ma);
                        $part[$cb] = ($part[$cb] ?? 0.0) + $samples * ($mb - $cb);
                        if ($cb - $ca > 1) {
                            $diff[$ca + 1] = ($diff[$ca + 1] ?? 0) + $samples;
                            $diff[$cb] = ($diff[$cb] ?? 0) - $samples;
                        }
                        continue;
                    }
                    for ($s = 0; $s < $samples; $s++) {
                        $xa = $a0 + $ysAt[$s] * $da;
                        $xb = $b0 + $ysAt[$s] * $db;
                        if ($xa < 0.0) {
                            $xa = 0.0;
                        }
                        if ($xb > $fw) {
                            $xb = $fw;
                        }
                        if ($xb <= $xa) {
                            continue;
                        }
                        $ca = (int) $xa;
                        $cb = (int) ceil($xb) - 1;
                        if ($ca === $cb) {
                            $part[$ca] = ($part[$ca] ?? 0.0) + ($xb - $xa);
                            continue;
                        }
                        $part[$ca] = ($part[$ca] ?? 0.0) + ($ca + 1 - $xa);
                        $part[$cb] = ($part[$cb] ?? 0.0) + ($xb - $cb);
                        if ($cb - $ca > 1) {
                            $diff[$ca + 1] = ($diff[$ca + 1] ?? 0) + 1;
                            $diff[$cb] = ($diff[$cb] ?? 0) - 1;
                        }
                    }
                }
            } else {
                // Crossings of every active edge with every sample line,
                // gathered edge by edge so each edge's data is read once.
                $sx = array_fill(0, $samples, []);
                $sd = $sx;
                foreach ($active as $e) {
                    $y0 = $ey0[$e];
                    $y1 = $ey1[$e];
                    $x0 = $ex0[$e];
                    $dx = $edx[$e];
                    $d = $edir[$e];
                    for ($s = 0; $s < $samples; $s++) {
                        if ($ysAt[$s] >= $y0 && $ysAt[$s] < $y1) {
                            $sx[$s][] = $x0 + ($ysAt[$s] - $y0) * $dx;
                            $sd[$s][] = $d;
                        }
                    }
                }
            }

            for ($s = 0; $ordered === null && $s < $samples; $s++) {
                $xs = $sx[$s];
                $ds = $sd[$s];
                if (count($xs) < 2) {
                    continue;
                }
                if (count($xs) === 2) {
                    // The common case needs no sort call (asort is stable: ties keep order).
                    $order = $xs[1] < $xs[0] ? [1, 0] : [0, 1];
                } else {
                    asort($xs); // C-speed sort; $ds is read through the surviving keys
                    $order = array_keys($xs);
                }

                $w = 0;
                $xa = 0.0;
                $odd = false;
                foreach ($order as $i) {
                    $x = $xs[$i];
                    if ($nonZero) {
                        $before = $w;
                        $w += $ds[$i];
                        if ($before === 0 && $w !== 0) {
                            $xa = $x;
                            continue;
                        }
                        if (!($before !== 0 && $w === 0)) {
                            continue;
                        }
                    } else {
                        $odd = !$odd;
                        if ($odd) {
                            $xa = $x;
                            continue;
                        }
                    }
                    $xb = $x;
                    if ($xa < 0.0) {
                        $xa = 0.0;
                    }
                    if ($xb > $fw) {
                        $xb = $fw;
                    }
                    if ($xb <= $xa) {
                        continue;
                    }
                    $ca = (int) $xa;
                    $cb = (int) ceil($xb) - 1;
                    if ($ca === $cb) {
                        $part[$ca] = ($part[$ca] ?? 0.0) + ($xb - $xa);
                        continue;
                    }
                    $part[$ca] = ($part[$ca] ?? 0.0) + ($ca + 1 - $xa);
                    $part[$cb] = ($part[$cb] ?? 0.0) + ($xb - $cb);
                    if ($cb - $ca > 1) {
                        $diff[$ca + 1] = ($diff[$ca + 1] ?? 0) + 1;
                        $diff[$cb] = ($diff[$cb] ?? 0) - 1;
                    }
                }
            }

            if ($part === []) {
                continue;
            }

            // ---- resolve the difference array into coverage ---------------
            $keys = array_keys($part + $diff);
            sort($keys, SORT_NUMERIC);
            $cov = [];
            $level = 0;
            $prev = -1;
            $runs = [];
            foreach ($keys as $k) {
                if ($level > 0 && $k - 1 > $prev) {
                    if ($level >= $samples) {
                        $runs[] = [$prev + 1, $k - 1];
                    } else {
                        $v = $level * $inv;
                        for ($c = $prev + 1; $c < $k; $c++) {
                            $cov[$c] = $v;
                        }
                    }
                }
                $level += $diff[$k] ?? 0;
                $v = (($part[$k] ?? 0.0) + $level) * $inv;
                if ($v > 0.0) {
                    $cov[$k] = $v;
                }
                $prev = $k;
            }

            $rowFn($y, $cov, $keys[0], $prev, $runs);
        }
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
}
