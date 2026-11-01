<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * Porter-Duff compositing plus the CSS blend modes.
 *
 * All values are straight (non-premultiplied) floats in 0..1.  The blend
 * equations follow the W3C Compositing and Blending Level 1 model: separable
 * blend modes produce a colour which is then composited source-over.
 */
final class Compositing
{
    public const PORTER_DUFF = [
        'source-over', 'source-in', 'source-out', 'source-atop',
        'destination-over', 'destination-in', 'destination-out', 'destination-atop',
        'lighter', 'copy', 'xor',
    ];

    public const SEPARABLE = [
        'multiply', 'screen', 'overlay', 'darken', 'lighten',
        'color-dodge', 'color-burn', 'hard-light', 'soft-light',
        'difference', 'exclusion',
    ];

    public const MODES = [
        'source-over', 'source-in', 'source-out', 'source-atop',
        'destination-over', 'destination-in', 'destination-out', 'destination-atop',
        'lighter', 'copy', 'xor',
        'multiply', 'screen', 'overlay', 'darken', 'lighten',
        'color-dodge', 'color-burn', 'hard-light', 'soft-light',
        'difference', 'exclusion',
        'hue', 'saturation', 'color', 'luminosity',
    ];

    /**
     * Composite a source pixel over a destination pixel.
     *
     * @param string $mode  globalCompositeOperation
     * @param array{0:float,1:float,2:float,3:float} $src   straight RGBA
     * @param array{0:float,1:float,2:float,3:float} $dst   straight RGBA
     * @return array{0:float,1:float,2:float,3:float}
     */
    /**
     * Does a draw with this mode change destination pixels the shape does not
     * cover?  source-in, copy and friends work like a layer composite, so they
     * have to visit the whole canvas; source-over and the blend modes do not.
     */
    public static function reachesBeyondShape(string $mode): bool
    {
        static $cache = [];
        if (isset($cache[$mode])) {
            return $cache[$mode];
        }
        $probes = [[0.0, 0.0, 0.0, 1.0], [1.0, 0.0, 0.0, 1.0], [0.5, 0.25, 0.75, 0.5]];
        $result = false;
        foreach ($probes as $dst) {
            $out = self::composite($mode, [0.0, 0.0, 0.0, 0.0], $dst);
            for ($i = 0; $i < 4; $i++) {
                if (abs($out[$i] - $dst[$i]) > 1e-9) {
                    $result = true;
                    break 2;
                }
            }
        }
        return $cache[$mode] = $result;
    }

    /**
     * Composite a source pixel over a destination pixel, both unpremultiplied
     * with straight alpha.
     *
     * The Porter-Duff modes are applied from the specification's factor table,
     * Co = Fa x Cs + Fb x Cd, in premultiplied space; the CSS blend modes use
     * the general form with B() as the blend function.
     *
     * @param array{0:float,1:float,2:float,3:float} $src
     * @param array{0:float,1:float,2:float,3:float} $dst
     * @return array{0:float,1:float,2:float,3:float}
     */
    public static function composite(string $mode, array $src, array $dst): array
    {
        $as = max(0.0, min(1.0, $src[3]));
        $ad = max(0.0, min(1.0, $dst[3]));

        if ($mode === 'lighter') {
            // Canvas "lighter" is plus-lighter: channels clamp independently.
            $ao = min(1.0, $as + $ad);
            if ($ao <= 0.0) {
                return [0.0, 0.0, 0.0, 0.0];
            }
            return [
                min(1.0, ($src[0] * $as + $dst[0] * $ad) / $ao),
                min(1.0, ($src[1] * $as + $dst[1] * $ad) / $ao),
                min(1.0, ($src[2] * $as + $dst[2] * $ad) / $ao),
                $ao,
            ];
        }

        $f = self::factors($mode, $as, $ad);
        if ($f !== null) {
            [$fa, $fb] = $f;
            $ao = $fa * $as + $fb * $ad;
            if ($ao <= 0.0) {
                return [0.0, 0.0, 0.0, 0.0];
            }
            $out = [0.0, 0.0, 0.0, $ao];
            for ($i = 0; $i < 3; $i++) {
                $v = ($fa * $as * $src[$i] + $fb * $ad * $dst[$i]) / $ao;
                $out[$i] = $v <= 0.0 ? 0.0 : ($v >= 1.0 ? 1.0 : $v);
            }
            return $out;
        }

        if (!\in_array($mode, self::SEPARABLE, true)) {
            // Unknown mode: browsers ignore the assignment, and a fresh context
            // composites source-over.
            return self::composite('source-over', $src, $dst);
        }

        $ao = $as + $ad * (1 - $as);
        if ($ao <= 0.0) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $out = [0.0, 0.0, 0.0, $ao];
        for ($i = 0; $i < 3; $i++) {
            $b = self::separable($mode, $src[$i], $dst[$i]);
            $v = ($as * (1 - $ad) * $src[$i] + $as * $ad * $b + (1 - $as) * $ad * $dst[$i]) / $ao;
            $out[$i] = $v <= 0.0 ? 0.0 : ($v >= 1.0 ? 1.0 : $v);
        }
        return $out;
    }

    /**
     * Fa and Fb from the Porter-Duff table, or null for a blend mode.
     *
     * @return ?array{0:float,1:float}
     */
    private static function factors(string $mode, float $as, float $ad): ?array
    {
        return match ($mode) {
            'copy' => [1.0, 0.0],
            'source-over' => [1.0, 1.0 - $as],
            'source-in' => [$ad, 0.0],
            'source-out' => [1.0 - $ad, 0.0],
            'source-atop' => [$ad, 1.0 - $as],
            'destination-over' => [1.0 - $ad, 1.0],
            'destination-in' => [0.0, $as],
            'destination-out' => [0.0, 1.0 - $as],
            'destination-atop' => [1.0 - $ad, $as],
            'xor' => [1.0 - $ad, 1.0 - $as],
            'clear' => [0.0, 0.0],
            default => null,
        };
    }

    private static function separable(string $mode, float $cs, float $cb): float
    {
        switch ($mode) {
            case 'multiply':
                return $cs * $cb;
            case 'screen':
                return $cs + $cb - $cs * $cb;
            case 'darken':
                return \min($cs, $cb);
            case 'lighten':
                return \max($cs, $cb);
            case 'overlay':
                return $cb <= 0.5
                    ? 2 * $cs * $cb
                    : 1 - 2 * (1 - $cs) * (1 - $cb);
            case 'hard-light':
                return $cs <= 0.5
                    ? 2 * $cs * $cb
                    : 1 - 2 * (1 - $cs) * (1 - $cb);
            case 'color-dodge':
                if ($cb <= 0.0) {
                    return 0.0;
                }
                if ($cs >= 1.0) {
                    return 1.0;
                }
                return \min(1.0, $cb / (1 - $cs));
            case 'color-burn':
                // Guards in this order: a white backdrop burns to white even
                // with a black source, which is the limit of the formula.
                if ($cb >= 1.0) {
                    return 1.0;
                }
                if ($cb <= 0.0 || $cs <= 0.0) {
                    return 0.0;
                }
                if ($cs >= 1.0) {
                    return 1.0;
                }
                return max(0.0, min(1.0, 1.0 - min(1.0, (1.0 - $cb) / $cs)));
            case 'soft-light':
                if ($cs <= 0.5) {
                    return $cb - (1 - 2 * $cs) * $cb * (1 - $cb);
                }
                $d = $cb <= 0.25
                    ? ((16 * $cb - 12) * $cb + 4) * $cb
                    : sqrt($cb);
                return $cb + (2 * $cs - 1) * ($d - $cb);
            case 'difference':
                return abs($cb - $cs);
            case 'exclusion':
                return $cb + $cs - 2 * $cb * $cs;
        }
        return $cs;
    }
}
