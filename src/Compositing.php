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
    public static function composite(string $mode, array $src, array $dst): array
    {
        $as = $src[3];
        if ($as <= 0.0) {
            return $mode === 'copy' ? [0.0, 0.0, 0.0, 0.0] : $dst;
        }
        $ad = $dst[3];

        switch ($mode) {
            case 'copy':
                return $src;
            case 'source-over':
                $ao = $as + $ad * (1 - $as);
                if ($ao <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                return [
                    (($src[0] * $as) + ($dst[0] * $ad * (1 - $as))) / $ao,
                    (($src[1] * $as) + ($dst[1] * $ad * (1 - $as))) / $ao,
                    (($src[2] * $as) + ($dst[2] * $ad * (1 - $as))) / $ao,
                    $ao,
                ];
            case 'source-in':
                $ao = $as * $ad;
                return $ao <= 0.0 ? [0.0, 0.0, 0.0, 0.0] : [$src[0], $src[1], $src[2], $ao];
            case 'source-out':
                $ao = $as * (1 - $ad);
                return $ao <= 0.0 ? [0.0, 0.0, 0.0, 0.0] : [$src[0], $src[1], $src[2], $ao];
            case 'source-atop':
                $ao = $ad;
                if ($ao <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                $k = $as * (1 - $ad);
                return [
                    ($src[0] * $as * $ad + $dst[0] * $k) / $ao,
                    ($src[1] * $as * $ad + $dst[1] * $k) / $ao,
                    ($src[2] * $as * $ad + $dst[2] * $k) / $ao,
                    $ao,
                ];
            case 'destination-over':
                $ao = $ad + $as * (1 - $ad);
                if ($ao <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                return [
                    ($dst[0] * $ad * (1 - $as) + $src[0] * $as * (1 - $ad)) / $ao,
                    ($dst[1] * $ad * (1 - $as) + $src[1] * $as * (1 - $ad)) / $ao,
                    ($dst[2] * $ad * (1 - $as) + $src[2] * $as * (1 - $ad)) / $ao,
                    $ao,
                ];
            case 'destination-in':
                $ao = $ad * $as;
                return $ao <= 0.0 ? [0.0, 0.0, 0.0, 0.0] : [$dst[0], $dst[1], $dst[2], $ao];
            case 'destination-out':
                $ao = $ad * (1 - $as);
                return $ao <= 0.0 ? [0.0, 0.0, 0.0, 0.0] : [$dst[0], $dst[1], $dst[2], $ao];
            case 'destination-atop':
                $ao = $as;
                if ($ao <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                $keep = $ad * (1 - $as);
                return [
                    ($dst[0] * $keep + $src[0] * $as * (1 - $ad)) / $ao,
                    ($dst[1] * $keep + $src[1] * $as * (1 - $ad)) / $ao,
                    ($dst[2] * $keep + $src[2] * $as * (1 - $ad)) / $ao,
                    $ao,
                ];
            case 'lighter':
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
            case 'xor':
                $as2 = $as * (1 - $ad);
                $ad2 = $ad * (1 - $as);
                $ao = $as2 + $ad2;
                if ($ao <= 0.0) {
                    return [0.0, 0.0, 0.0, 0.0];
                }
                return [
                    ($src[0] * $as2 + $dst[0] * $ad2) / $ao,
                    ($src[1] * $as2 + $dst[1] * $ad2) / $ao,
                    ($src[2] * $as2 + $dst[2] * $ad2) / $ao,
                    $ao,
                ];
        }

        if (!\in_array($mode, self::SEPARABLE, true)) {
            // Unknown or unimplemented mode: browsers ignore the assignment and
            // keep the previous operation, which for a fresh context is
            // source-over.
            return self::composite('source-over', $src, $dst);
        }

        $ao = $as + $ad * (1 - $as);
        if ($ao <= 0.0) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $out = [0.0, 0.0, 0.0, $ao];
        for ($i = 0; $i < 3; $i++) {
            $b = self::separable($mode, $src[$i], $dst[$i]);
            $premul = $as * (1 - $ad) * $src[$i]
                + $as * $ad * $b
                + (1 - $as) * $ad * $dst[$i];
            $out[$i] = $premul / $ao;
        }
        return $out;
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
