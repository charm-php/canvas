<?php declare(strict_types=1);

namespace Charm\Canvas;

/**
 * Parses CSS color strings to RGBA values.
 */
class ColorParser
{
    /**
     * CSS color name mapping.
     */
    private static array $namedColors = [
        'aliceblue' => '#f0f8ff',
        'antiquewhite' => '#faebd7',
        'aqua' => '#00ffff',
        'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff',
        'beige' => '#f5f5dc',
        'bisque' => '#ffe4c4',
        'black' => '#000000',
        'blanchedalmond' => '#ffebcd',
        'blue' => '#0000ff',
        'blueviolet' => '#8a2be2',
        'brown' => '#a52a2a',
        'burlywood' => '#deb887',
        'cadetblue' => '#5f9ea0',
        'chartreuse' => '#7fff00',
        'chocolate' => '#d2691e',
        'coral' => '#ff7f50',
        'cornflowerblue' => '#6495ed',
        'cornsilk' => '#fff8dc',
        'crimson' => '#dc143c',
        'cyan' => '#00ffff',
        'darkblue' => '#00008b',
        'darkcyan' => '#008b8b',
        'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9',
        'darkgreen' => '#006400',
        'darkgrey' => '#a9a9a9',
        'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b',
        'darkolivegreen' => '#556b2f',
        'darkorange' => '#ff8c00',
        'darkorchid' => '#9932cc',
        'darkred' => '#8b0000',
        'darksalmon' => '#e9967a',
        'darkseagreen' => '#8fbc8f',
        'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f',
        'darkslategrey' => '#2f4f4f',
        'darkturquoise' => '#00ced1',
        'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493',
        'deepskyblue' => '#00bfff',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff',
        'firebrick' => '#b22222',
        'floralwhite' => '#fffaf0',
        'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff',
        'gainsboro' => '#dcdcdc',
        'ghostwhite' => '#f8f8ff',
        'gold' => '#ffd700',
        'goldenrod' => '#daa520',
        'gray' => '#808080',
        'green' => '#008000',
        'greenyellow' => '#adff2f',
        'grey' => '#808080',
        'honeydew' => '#f0fff0',
        'hotpink' => '#ff69b4',
        'indianred' => '#cd5c5c',
        'indigo' => '#4b0082',
        'ivory' => '#fffff0',
        'khaki' => '#f0e68c',
        'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5',
        'lawngreen' => '#7cfc00',
        'lemonchiffon' => '#fffacd',
        'lightblue' => '#add8e6',
        'lightcoral' => '#f08080',
        'lightcyan' => '#e0ffff',
        'lightgoldenrodyellow' => '#fafad2',
        'lightgray' => '#d3d3d3',
        'lightgreen' => '#90ee90',
        'lightgrey' => '#d3d3d3',
        'lightpink' => '#ffb6c1',
        'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa',
        'lightskyblue' => '#87cefa',
        'lightslategray' => '#778899',
        'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de',
        'lightyellow' => '#ffffe0',
        'lime' => '#00ff00',
        'limegreen' => '#32cd32',
        'linen' => '#faf0e6',
        'magenta' => '#ff00ff',
        'maroon' => '#800000',
        'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd',
        'mediumorchid' => '#ba55d3',
        'mediumpurple' => '#9370db',
        'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee',
        'mediumspringgreen' => '#00fa9a',
        'mediumturquoise' => '#48d1cc',
        'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970',
        'mintcream' => '#f5fffa',
        'mistyrose' => '#ffe4e1',
        'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead',
        'navy' => '#000080',
        'oldlace' => '#fdf5e6',
        'olive' => '#808000',
        'olivedrab' => '#6b8e23',
        'orange' => '#ffa500',
        'orangered' => '#ff4500',
        'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa',
        'palegreen' => '#98fb98',
        'paleturquoise' => '#afeeee',
        'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5',
        'peachpuff' => '#ffdab9',
        'peru' => '#cd853f',
        'pink' => '#ffc0cb',
        'plum' => '#dda0dd',
        'powderblue' => '#b0e0e6',
        'purple' => '#800080',
        'red' => '#ff0000',
        'rosybrown' => '#bc8f8f',
        'royalblue' => '#4169e1',
        'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072',
        'sandybrown' => '#f4a460',
        'seagreen' => '#2e8b57',
        'seashell' => '#fff5ee',
        'sienna' => '#a0522d',
        'silver' => '#c0c0c0',
        'skyblue' => '#87ceeb',
        'slateblue' => '#6a5acd',
        'slategray' => '#708090',
        'slategrey' => '#708090',
        'snow' => '#fffafa',
        'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4',
        'tan' => '#d2b48c',
        'teal' => '#008080',
        'thistle' => '#d8bfd8',
        'tomato' => '#ff6347',
        'turquoise' => '#40e0d0',
        'violet' => '#ee82ee',
        'wheat' => '#f5deb3',
        'white' => '#ffffff',
        'whitesmoke' => '#f5f5f5',
        'yellow' => '#ffff00',
        'yellowgreen' => '#9acd32',
        'rebeccapurple' => '#663399',
        'transparent' => '#00000000',
    ];

    /**
     * Parse a CSS color string to 8-bit RGB plus a 0..1 alpha.
     *
     * @return array{r: int, g: int, b: int, a: float}
     */
    public static function parseColor(string $color): array
    {
        [$r, $g, $b, $a] = self::parseNormalized($color);
        return [
            'r' => (int) \round($r * 255),
            'g' => (int) \round($g * 255),
            'b' => (int) \round($b * 255),
            'a' => $a,
        ];
    }

    /**
     * Parse a CSS color string to components normalised to 0..1.
     *
     * Supported: the CSS keyword list, #rgb, #rgba, #rrggbb, #rrggbbaa,
     * rgb()/rgba() and hsl()/hsla() in both comma and space syntax, hwb(),
     * percentages, `none` components and unit-bearing angles.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function parseNormalized(string $color): array
    {
        $c = \strtolower(\trim($color));
        if ($c === '') {
            throw new \InvalidArgumentException("Invalid color: '$color'");
        }

        if (isset(self::$namedColors[$c])) {
            $c = self::$namedColors[$c];
        }

        if ($c[0] === '#') {
            $rgb = self::parseHex($c);
            if ($rgb !== null) {
                return $rgb;
            }
            throw new \InvalidArgumentException("Invalid color: '$color'");
        }

        if (\preg_match('/^([a-z-]+)\s*\(\s*(.*)\s*\)$/', $c, $m) === 1) {
            $parsed = self::parseFunction($color, $m[1], $m[2]);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException("Invalid color: '$color'");
    }

    /** @return ?array{0: float, 1: float, 2: float, 3: float} */
    private static function parseHex(string $c): ?array
    {
        $hex = \substr($c, 1);
        $len = \strlen($hex);
        if ($len !== 3 && $len !== 4 && $len !== 6 && $len !== 8) {
            return null;
        }
        if (!\ctype_xdigit($hex)) {
            return null;
        }
        if ($len === 3 || $len === 4) {
            $out = [];
            for ($i = 0; $i < $len; $i++) {
                $out[] = \hexdec($hex[$i] . $hex[$i]) / 255;
            }
            $out[3] = $len === 4 ? $out[3] : 1.0;
            return $out;
        }
        return [
            \hexdec(\substr($hex, 0, 2)) / 255,
            \hexdec(\substr($hex, 2, 2)) / 255,
            \hexdec(\substr($hex, 4, 2)) / 255,
            $len === 8 ? \hexdec(\substr($hex, 6, 2)) / 255 : 1.0,
        ];
    }

    /** @return ?array{0: float, 1: float, 2: float, 3: float} */
    private static function parseFunction(string $original, string $fn, string $argString): ?array
    {
        if (!\in_array($fn, ['rgb', 'rgba', 'hsl', 'hsla', 'hwb'], true)) {
            return null;
        }

        $body = \trim($argString);
        $alphaPart = null;
        if (\str_contains($body, '/')) {
            [$body, $alphaPart] = \array_pad(\explode('/', $body, 2), 2, '');
            $body = \trim($body);
            $alphaPart = \trim((string) $alphaPart);
        }

        $parts = \preg_split('/\s*,\s*|\s+/', \trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 3) {
            return null;
        }

        // Legacy comma syntax puts the alpha in the fourth argument.
        if ($alphaPart === null && count($parts) >= 4) {
            $alphaPart = $parts[3];
        }

        $alpha = self::parseAlpha($alphaPart, null);
        if ($alpha === null && $alphaPart !== null && $alphaPart !== '') {
            return null;
        }
        $a = $alpha ?? 1.0;

        if ($fn === 'rgb' || $fn === 'rgba') {
            $r = self::parseByte($parts[0]);
            $g = self::parseByte($parts[1]);
            $b = self::parseByte($parts[2]);
            if ($r === null || $g === null || $b === null) {
                return null;
            }
            return [$r, $g, $b, $a];
        }

        $h = self::parseAngle($parts[0]);
        if ($h === null) {
            return null;
        }
        $h = \fmod(\fmod($h, 360.0) + 360.0, 360.0) / 360.0;

        if ($fn === 'hwb') {
            $w = self::parsePercent($parts[1]);
            $bl = self::parsePercent($parts[2]);
            if ($w === null || $bl === null) {
                return null;
            }
            return [...self::hwbToNormalized($h, $w, $bl), $a];
        }

        $s = self::parsePercent($parts[1]);
        $l = self::parsePercent($parts[2]);
        if ($s === null || $l === null) {
            return null;
        }
        return [...self::hslToNormalized($h, $s, $l), $a];
    }

    /** Component in 0..1 from a number, percentage or `none`. */
    private static function parseByte(string $v): ?float
    {
        $v = \trim($v);
        if ($v === 'none') {
            return 0.0;
        }
        if (\str_ends_with($v, '%')) {
            $n = \filter_var(\substr($v, 0, -1), FILTER_VALIDATE_FLOAT);
            return $n === false ? null : self::clamp01($n / 100);
        }
        $n = \filter_var($v, FILTER_VALIDATE_FLOAT);
        return $n === false ? null : self::clamp01((float) $n / 255);
    }

    /** Percentage (or `none`) in 0..1; a bare number is treated as a percentage value. */
    private static function parsePercent(string $v): ?float
    {
        $v = \trim($v);
        if ($v === 'none') {
            return 0.0;
        }
        if (\str_ends_with($v, '%')) {
            $n = \filter_var(\substr($v, 0, -1), FILTER_VALIDATE_FLOAT);
            return $n === false ? null : self::clamp01($n / 100);
        }
        $n = \filter_var($v, FILTER_VALIDATE_FLOAT);
        return $n === false ? null : self::clamp01($n / 100);
    }

    /** Angle in degrees; accepts deg/grad/rad/turn or a bare number. */
    private static function parseAngle(string $v): ?float
    {
        $v = \trim($v);
        if ($v === 'none') {
            return 0.0;
        }
        foreach (['turn' => 360.0, 'rad' => 180.0 / M_PI, 'grad' => 0.9, 'deg' => 1.0] as $unit => $factor) {
            if (\str_ends_with($v, $unit)) {
                $n = \filter_var(\substr($v, 0, -strlen($unit)), FILTER_VALIDATE_FLOAT);
                return $n === false ? null : (float) $n * $factor;
            }
        }
        $n = \filter_var($v, FILTER_VALIDATE_FLOAT);
        return $n === false ? null : (float) $n;
    }

    private static function parseAlpha(?string $v, ?float $default): ?float
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if ($v === 'none') {
            return 1.0;
        }
        if (\str_ends_with($v, '%')) {
            $n = \filter_var(\substr($v, 0, -1), FILTER_VALIDATE_FLOAT);
            return $n === false ? null : self::clamp01($n / 100);
        }
        $n = \filter_var($v, FILTER_VALIDATE_FLOAT);
        return $n === false ? null : self::clamp01($n);
    }

    private static function clamp01(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }

    /**
     * Convert HSL to linear-ish RGB components (0..1 each).
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hslToNormalized(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            return [$l, $l, $l];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        return [
            self::hueToChannel($p, $q, $h + 1 / 3),
            self::hueToChannel($p, $q, $h),
            self::hueToChannel($p, $q, $h - 1 / 3),
        ];
    }

    private static function hueToChannel(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }

    /**
     * Convert HWB to RGB components (0..1 each).
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hwbToNormalized(float $h, float $w, float $b): array
    {
        if ($w + $b >= 1.0) {
            $gray = $w / ($w + $b);
            return [$gray, $gray, $gray];
        }
        [$r, $g, $bl] = self::hslToNormalized($h, 1.0, 0.5);
        $r = $r * (1 - $w - $b) + $w;
        $g = $g * (1 - $w - $b) + $w;
        $bl = $bl * (1 - $w - $b) + $w;
        return [$r, $g, $bl];
    }

    /**
     * Validate a color string.
     */
    public static function validate(string $color): void
    {
        try {
            self::parseColor($color);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Invalid color: $color");
        }
    }
}
