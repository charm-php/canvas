<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * Glyph rasterisation: turns text into a coverage mask.
 *
 * Only the alpha channel matters here — the caller paints the mask with the
 * current fill or stroke style, which is what lets gradients and alpha work on
 * text and lets an outline stroke be derived from the same mask.
 */
final class TextRaster
{
    /** @var array<string,array{surface:Surface,originX:float,originY:float,width:float,height:float}> */
    private static array $cache = [];

    /**
     * @return ?array{surface:Surface,originX:float,originY:float,width:float,height:float}
     */
    public static function render(string $text, string $fontFile, float $pixelSize, float $strokeWidth = 0.0): ?array
    {
        if ($text === '' || $pixelSize < 0.5) {
            return null;
        }
        $key = $fontFile . '|' . round($pixelSize, 3) . '|' . round($strokeWidth, 3) . '|' . $text;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (!\function_exists('imagettftext')) {
            return null;
        }

        $size = max(0.5, $pixelSize);
        $bbox = \imagettfbbox($size, 0.0, $fontFile, $text);
        if ($bbox === false) {
            return null;
        }
        $xs = [];
        $ys = [];
        for ($i = 0; $i < 8; $i += 2) {
            $xs[] = $bbox[$i];
            $ys[] = $bbox[$i + 1];
        }
        $pad = (int) ceil(max(2.0, $strokeWidth / 2 + 2));
        $inkLeft = min($xs);
        $inkRight = max($xs);
        $inkTop = min($ys);
        $inkBottom = max($ys);

        $width = max(1, $inkRight - $inkLeft + 2 * $pad);
        $height = max(1, $inkBottom - $inkTop + 2 * $pad);

        // Pen origin inside the raster: the baseline-left point.
        $originX = -$inkLeft + $pad;
        $originY = -$inkTop + $pad;

        $image = \imagecreatetruecolor($width, $height);
        \imagealphablending($image, false);
        \imagesavealpha($image, true);
        $transparent = \imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent === false) {
            return null;
        }
        \imagefill($image, 0, 0, $transparent);
        \imagealphablending($image, true);

        $white = \imagecolorallocatealpha($image, 255, 255, 255, 0);
        if ($white === false) {
            return null;
        }
        if (@\imagettftext($image, $size, 0.0, (int) round($originX), (int) round($originY), $white, $fontFile, $text) === false) {
            return null;
        }

        $surface = (new Codec\GdCodec())->fromGdImage($image);
        \imagedestroy($image);

        if ($strokeWidth > 0.0) {
            $surface = self::outline($surface, $strokeWidth);
        }

        $result = [
            'surface' => $surface,
            'originX' => $originX,
            'originY' => $originY,
            'width' => (float) $width,
            'height' => (float) $height,
        ];
        if (count(self::$cache) > 64) {
            self::$cache = [];
        }
        self::$cache[$key] = $result;
        return $result;
    }

    /**
     * Derive a centred outline from a coverage mask.
     *
     * A browser strokes the glyph outline exactly; here the band is built from
     * the mask by dilating and eroding it, which gives an annulus of the right
     * width without needing to parse outlines.
     */
    private static function outline(Surface $mask, float $strokeWidth): Surface
    {
        $w = $mask->width;
        $h = $mask->height;
        $r = max(1, (int) round($strokeWidth / 2));

        $coverage = [];
        $pixels = $mask->clonePixels();
        foreach ($pixels as $p) {
            $coverage[] = (($p >> 24) & 0xFF);
        }

        // band = dilate(mask, r) - erode(mask, r): an annulus of the right
        // width around the glyph outline, built with separable max/min filters.
        $dilated = self::filter2d($coverage, $w, $h, $r, true);
        $eroded = self::filter2d($coverage, $w, $h, $r, false);

        $px = [];
        $n = $w * $h;
        for ($i = 0; $i < $n; $i++) {
            $v = $dilated[$i] - $eroded[$i];
            $px[] = $v > 0 ? (($v > 255 ? 255 : $v) << 24) : 0;
        }
        $out = new Surface($w, $h);
        $out->setPixels($px);
        return $out;
    }

    /**
     * Separable grey max (or min) filter over a square window.
     *
     * @param list<int> $src
     * @return list<int>
     */
    private static function filter2d(array $src, int $w, int $h, int $r, bool $max): array
    {
        $horizontal = array_fill(0, $w * $h, 0);
        for ($y = 0; $y < $h; $y++) {
            $base = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $best = $max ? 0 : 255;
                $from = max(0, $x - $r);
                $to = min($w - 1, $x + $r);
                for ($k = $from; $k <= $to; $k++) {
                    $v = $src[$base + $k];
                    if (($max && $v > $best) || (!$max && $v < $best)) {
                        $best = $v;
                    }
                }
                $horizontal[$base + $x] = $best;
            }
        }

        $out = array_fill(0, $w * $h, 0);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $best = $max ? 0 : 255;
                $from = max(0, $y - $r);
                $to = min($h - 1, $y + $r);
                for ($k = $from; $k <= $to; $k++) {
                    $v = $horizontal[$k * $w + $x];
                    if (($max && $v > $best) || (!$max && $v < $best)) {
                        $best = $v;
                    }
                }
                $out[$y * $w + $x] = $best;
            }
        }
        return $out;
    }
}
