<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

/**
 * Numeric comparison between the library's pixels and the browser's pixels.
 *
 * Browsers and GD will never produce byte-identical antialiasing, so a verdict
 * is not a single pixel-diff number: it combines agreement ratio, coverage
 * (alpha-weighted painted area, which is what geometry errors move) and the
 * bounding box of painted pixels (which is what transform errors move).
 */
final class Metrics
{
    /** tier => [tolerance, min agreement, max coverage delta, max bbox delta] */
    private const TIERS = [
        'strict' => [4, 0.995, 0.010, 0],
        'normal' => [16, 0.950, 0.030, 1],
        'loose'  => [48, 0.800, 0.120, 2],
    ];

    /**
     * @return array{pass:bool, reason:string, agreement:float, coverageDelta:float,
     *               bboxDelta:int, mad:float, maxDiff:int,
     *               phpBbox:array, chromeBbox:array, phpCoverage:float, chromeCoverage:float}
     */
    public static function compare(string $php, string $chrome, int $w, int $h, string $tier = 'normal'): array
    {
        [$tol, $minAgreement, $maxCov, $maxBbox] = self::TIERS[$tier] ?? self::TIERS['normal'];

        $n = $w * $h;
        if (strlen($php) < $n * 4 || strlen($chrome) < $n * 4) {
            return [
                'pass' => false, 'reason' => sprintf(
                    'buffer too small (php=%d chrome=%d expected=%d)',
                    strlen($php),
                    strlen($chrome),
                    $n * 4
                ),
                'agreement' => 0.0, 'coverageDelta' => 1.0, 'bboxDelta' => 999, 'mad' => 255.0,
                'maxDiff' => 255, 'phpBbox' => [0, 0, 0, 0], 'chromeBbox' => [0, 0, 0, 0],
                'phpCoverage' => 0.0, 'chromeCoverage' => 0.0,
            ];
        }

        $same = 0;
        $sum = 0.0;
        $max = 0;
        $phpCov = 0.0;
        $chromeCov = 0.0;
        $phpBox = null;
        $chromeBox = null;

        for ($i = 0, $p = 0; $p < $n; $p++, $i += 4) {
            $pa = ord($php[$i + 3]);
            $ca = ord($chrome[$i + 3]);

            $phpCov += $pa / 255.0;
            $chromeCov += $ca / 255.0;

            if ($pa > 8) {
                $x = $p % $w;
                $y = intdiv($p, $w);
                if ($phpBox === null) {
                    $phpBox = [$x, $y, $x, $y];
                } else {
                    $phpBox[0] = min($phpBox[0], $x);
                    $phpBox[1] = min($phpBox[1], $y);
                    $phpBox[2] = max($phpBox[2], $x);
                    $phpBox[3] = max($phpBox[3], $y);
                }
            }
            if ($ca > 8) {
                $x = $p % $w;
                $y = intdiv($p, $w);
                if ($chromeBox === null) {
                    $chromeBox = [$x, $y, $x, $y];
                } else {
                    $chromeBox[0] = min($chromeBox[0], $x);
                    $chromeBox[1] = min($chromeBox[1], $y);
                    $chromeBox[2] = max($chromeBox[2], $x);
                    $chromeBox[3] = max($chromeBox[3], $y);
                }
            }

            $d = 0;
            for ($c = 0; $c < 4; $c++) {
                $diff = abs(ord($php[$i + $c]) - ord($chrome[$i + $c]));
                if ($diff > $d) {
                    $d = $diff;
                }
                $sum += $diff;
            }
            if ($d > $max) {
                $max = $d;
            }
            if ($d <= $tol) {
                $same++;
            }
        }

        $agreement = $n > 0 ? $same / $n : 0.0;
        $coverageDelta = $chromeCov > 0.5
            ? abs($phpCov - $chromeCov) / $chromeCov
            : (abs($phpCov - $chromeCov) > 4 ? 1.0 : 0.0);

        $phpBox ??= [-1, -1, -1, -1];
        $chromeBox ??= [-1, -1, -1, -1];
        $bboxDelta = 0;
        for ($k = 0; $k < 4; $k++) {
            $bboxDelta = max($bboxDelta, abs($phpBox[$k] - $chromeBox[$k]));
        }

        $reasons = [];
        if ($agreement < $minAgreement) {
            $reasons[] = sprintf('agreement %.1f%% < %.1f%%', $agreement * 100, $minAgreement * 100);
        }
        if ($coverageDelta > $maxCov) {
            $reasons[] = sprintf('coverage %.1f%% off > %.1f%%', $coverageDelta * 100, $maxCov * 100);
        }
        if ($bboxDelta > $maxBbox) {
            $reasons[] = sprintf('bbox off by %d > %d', $bboxDelta, $maxBbox);
        }

        return [
            'pass' => $reasons === [],
            'reason' => implode('; ', $reasons),
            'agreement' => $agreement,
            'coverageDelta' => $coverageDelta,
            'bboxDelta' => $bboxDelta,
            'mad' => $sum / max(1, $n * 4),
            'maxDiff' => $max,
            'phpBbox' => $phpBox,
            'chromeBbox' => $chromeBox,
            'phpCoverage' => $phpCov,
            'chromeCoverage' => $chromeCov,
        ];
    }

    /**
     * A text picture of where two renderings disagree.
     *
     * There is no image inspection available in this environment, so this is
     * how a divergence is read: '#' disagreeing pixels, '+' painted only by us,
     * '.' painted only by the browser, '-' agreeing painted pixels, ' ' empty.
     */
    public static function asciiMap(string $php, string $chrome, int $w, int $h, int $tol = 16, int $maxW = 96, int $maxH = 40): string
    {
        $bw = max(1, intdiv($w + $maxW - 1, $maxW));
        $bh = max(1, intdiv($h + $maxH - 1, $maxH));

        $rank = [' ' => 0, '-' => 1, '.' => 2, '+' => 3, '#' => 4];
        $lines = [];
        for ($by = 0; $by * $bh < $h; $by++) {
            $line = '';
            for ($bx = 0; $bx * $bw < $w; $bx++) {
                $worst = ' ';
                $worstRank = 0;
                for ($y = $by * $bh; $y < min($h, ($by + 1) * $bh); $y++) {
                    for ($x = $bx * $bw; $x < min($w, ($bx + 1) * $bw); $x++) {
                        $i = ($y * $w + $x) * 4;
                        $pa = strlen($php) > $i + 3 ? ord($php[$i + 3]) : 0;
                        $ca = strlen($chrome) > $i + 3 ? ord($chrome[$i + 3]) : 0;
                        $ch = ' ';
                        if ($pa > 8 && $ca > 8) {
                            $d = 0;
                            for ($c = 0; $c < 4; $c++) {
                                $d = max($d, abs(ord($php[$i + $c]) - ord($chrome[$i + $c])));
                            }
                            $ch = $d > $tol ? '#' : '-';
                        } elseif ($pa > 8) {
                            $ch = '+';
                        } elseif ($ca > 8) {
                            $ch = '.';
                        }
                        if ($rank[$ch] > $worstRank) {
                            $worstRank = $rank[$ch];
                            $worst = $ch;
                        }
                    }
                }
                $line .= $worst;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
