<?php declare(strict_types=1);

namespace phasync\Canvas;

/**
 * Font specification parsing, family resolution and advance-width metrics.
 *
 * The canvas `font` attribute is a CSS font shorthand, so a size and a family
 * are pulled out of it; a family may also be a path to a font file, which is
 * how this library is usually pointed at an exact typeface.  Advances come from
 * the font's own horizontal metrics table so measureText() and text
 * alignment agree with what a browser reports, rather than with the ink
 * bounding box.
 */
final class Fonts
{
    /** @var array<string,string> */
    private static array $registered = [];

    /** @var array<string,array> */
    private static array $metricsCache = [];

    private static ?string $systemDefault = null;

    public static function register(string $family, string $path): void
    {
        self::$registered[\strtolower($family)] = $path;
    }

    public static function clearRegistry(): void
    {
        self::$registered = [];
        self::$metricsCache = [];
        self::$systemDefault = null;
    }

    /**
     * Split a CSS font shorthand into a pixel size and a file name.
     *
     * @return array{file: ?string, size: float}
     */
    public static function resolve(string $spec): array
    {
        $spec = \trim($spec);
        $size = self::sizeOf($spec);
        $family = self::familyOf($spec);
        return ['file' => self::fileFor($family), 'size' => $size];
    }

    public static function sizeOf(string $spec): float
    {
        // Ignore the leading style/variant/weight keywords, then read the size.
        if (\preg_match(
            '/(?<![\w-])([0-9]*\.?[0-9]+)\s*(px|pt|em|rem|%|cm|mm|in|pc)\s*(?![\w%])/',
            $spec,
            $m,
        ) === 1) {
            $value = (float) $m[1];
            return match ($m[2]) {
                'pt' => $value * 96 / 72,
                'em', 'rem' => $value * 16,
                '%' => $value / 100 * 16,
                'cm' => $value * 96 / 2.54,
                'mm' => $value * 96 / 25.4,
                'in' => $value * 96,
                'pc' => $value * 16,
                default => $value,
            };
        }
        return 16.0;
    }

    public static function familyOf(string $spec): string
    {
        // Everything after the size token is the family list.
        if (\preg_match('/([0-9]*\.?[0-9]+\s*(?:px|pt|em|rem|%|cm|mm|in|pc)\s*(?:\/\s*[0-9.]+\s*%)?)\s+(.*)$/i', $spec, $m) === 1) {
            return \trim($m[2]);
        }
        return $spec;
    }

    /** Resolve a family list (or a quoted path) to a readable font file. */
    public static function fileFor(string $familyList): ?string
    {
        $candidates = [];
        foreach (\explode(',', $familyList) as $family) {
            $family = \trim($family);
            if ($family === '') {
                continue;
            }
            if (\preg_match('/^(["\'])(.*)\1$/', $family, $m) === 1) {
                $family = $m[2];
            }
            if (\str_contains($family, '/')) {
                if (\is_file($family) && \is_readable($family)) {
                    return $family;
                }
                continue;
            }
            $key = \strtolower($family);
            if (isset(self::$registered[$key])) {
                return self::$registered[$key];
            }
            if (\in_array($key, ['sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-sans-serif'], true)) {
                $found = self::generic($key);
                if ($found !== null) {
                    return $found;
                }
                continue;
            }
            $candidates[] = $key;
        }

        foreach ($candidates as $key) {
            $found = self::searchSystem($key);
            if ($found !== null) {
                return $found;
            }
        }
        return self::generic('sans-serif');
    }

    private static function generic(string $kind): ?string
    {
        if (self::$systemDefault !== null && \is_file(self::$systemDefault)) {
            return self::$systemDefault;
        }
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/Library/Fonts/Arial.ttf',
            'C:/Windows/Fonts/arial.ttf',
        ] as $path) {
            if (\is_file($path)) {
                self::$systemDefault = $path;
                return $path;
            }
        }
        return null;
    }

    /**
     * Find a font by name in the usual directories, preferring a regular
     * weight over bold/italic variants.
     */
    private static function searchSystem(string $family): ?string
    {
        $needle = \str_replace(' ', '', $family);
        $dirs = ['/usr/share/fonts', '/usr/local/share/fonts', \getenv('HOME') . '/.fonts', \getenv('HOME') . '/.local/share/fonts'];
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($dirs as $dir) {
            if ($dir === '' || !\is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            $limit = 0;
            foreach ($it as $file) {
                if (++$limit > 20000) {
                    break;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                $ext = \strtolower($file->getExtension());
                if (!\in_array($ext, ['ttf', 'otf'], true)) {
                    continue;
                }
                $stem = \str_replace(['-', ' '], '', \strtolower($name));
                if (!\str_starts_with($stem, $needle)) {
                    continue;
                }
                $score = \strlen($stem);
                foreach (['bold', 'italic', 'black', 'light', 'medium', 'condensed', 'semi', 'extra'] as $bad) {
                    if (\str_contains($stem, $bad)) {
                        $score += 100;
                    }
                }
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $file->getPathname();
                }
            }
        }
        if ($best !== null) {
            self::$registered[$family] = $best;
        }
        return $best;
    }

    /**
     * Advance width plus ascent/descent for a string, in pixels.
     *
     * @return array{width: float, ascent: float, descent: float}
     */
    public static function metrics(string $file, float $size, string $text): array
    {
        $font = self::readFont($file);
        if ($font === null) {
            return ['width' => 0.0, 'ascent' => $size * 0.8, 'descent' => $size * 0.2];
        }
        $scale = $size / max(1, $font['unitsPerEm']);
        $advance = 0.0;
        $len = \mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $cp = \mb_ord(\mb_substr($text, $i, 1));
            $gid = $font['cmap'][$cp] ?? 0;
            $advance += $font['advance'][$gid] ?? $font['advance'][0] ?? 0;
        }
        return [
            'width' => $advance * $scale,
            'ascent' => $font['ascent'] * $scale,
            'descent' => -$font['descent'] * $scale,
        ];
    }

    /**
     * Minimal TrueType/OpenType metrics reader: head, hhea, maxp, cmap, hmtx.
     *
     * @return ?array{unitsPerEm: int, ascent: float, descent: float, cmap: array<int,int>, advance: array<int,int>}
     */
    public static function readFont(string $file): ?array
    {
        $key = $file;
        if (isset(self::$metricsCache[$key])) {
            return self::$metricsCache[$key];
        }
        $data = @\file_get_contents($file);
        if ($data === false || \strlen($data) < 12) {
            return null;
        }

        $tables = self::tableDirectory($data);
        if ($tables === []) {
            return null;
        }
        if (!isset($tables['head'], $tables['hhea'], $tables['maxp'], $tables['cmap'], $tables['hmtx'])) {
            return null;
        }

        $head = $tables['head'];
        $unitsPerEm = self::u16($data, $head + 18);
        if ($unitsPerEm === 0) {
            return null;
        }

        $hhea = $tables['hhea'];
        $ascent = self::i16($data, $hhea + 4) / $unitsPerEm;
        $descent = self::i16($data, $hhea + 6) / $unitsPerEm;
        $numHMetrics = self::u16($data, $hhea + 34);

        $numGlyphs = self::u16($data, $tables['maxp'] + 4);

        $advance = [];
        $hmtx = $tables['hmtx'];
        $last = 0;
        for ($g = 0; $g < $numGlyphs; $g++) {
            if ($g < $numHMetrics) {
                $last = self::u16($data, $hmtx + $g * 4);
            }
            $advance[$g] = $last / $unitsPerEm;
        }

        $cmap = self::readCmap($data, $tables['cmap']);

        $font = [
            'unitsPerEm' => $unitsPerEm,
            'ascent' => $ascent,
            'descent' => $descent,
            'cmap' => $cmap,
            'advance' => $advance,
        ];
        self::$metricsCache[$key] = $font;
        return $font;
    }

    /** @return array<string,int> */
    private static function tableDirectory(string $data): array
    {
        $sfnt = self::u32($data, 0);
        $offset = 0;
        if ($sfnt === 0x74746366) {
            // TTC collection: use the first font.
            $offset = self::u32($data, 8);
        }
        $numTables = self::u16($data, $offset + 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = $offset + 12 + $i * 16;
            if ($rec + 16 > \strlen($data)) {
                break;
            }
            $tag = \substr($data, $rec, 4);
            $tables[\strtolower($tag)] = self::u32($data, $rec + 8);
        }
        return $tables;
    }

    /** @return array<int,int> */
    private static function readCmap(string $data, int $cmap): array
    {
        $numTables = self::u16($data, $cmap + 2);
        $best = null;
        $bestFormat = -1;
        for ($i = 0; $i < $numTables; $i++) {
            $rec = $cmap + 4 + $i * 8;
            $platform = self::u16($data, $rec);
            $encoding = self::u16($data, $rec + 2);
            $sub = $cmap + self::u32($data, $rec + 4);
            $format = self::u16($data, $sub);
            $score = match (true) {
                $format === 12 => 4,
                $format === 4 && $platform === 3 && $encoding === 1 => 3,
                $format === 4 => 2,
                $format === 6 => 1,
                default => 0,
            };
            if ($score > $bestFormat) {
                $bestFormat = $score;
                $best = [$sub, $format];
            }
        }
        if ($best === null) {
            return [];
        }
        [$sub, $format] = $best;

        $map = [];
        if ($format === 4) {
            $segX2 = self::u16($data, $sub + 6);
            $seg = intdiv($segX2, 2);
            $endBase = $sub + 14;
            $startBase = $endBase + $segX2 + 2;
            $deltaBase = $startBase + $segX2;
            $rangeBase = $deltaBase + $segX2;
            for ($s = 0; $s < $seg; $s++) {
                $end = self::u16($data, $endBase + $s * 2);
                $start = self::u16($data, $startBase + $s * 2);
                $delta = self::i16($data, $deltaBase + $s * 2);
                $rangeOffset = self::u16($data, $rangeBase + $s * 2);
                if ($start === 0xFFFF) {
                    continue;
                }
                for ($cp = $start; $cp <= $end; $cp++) {
                    if ($rangeOffset === 0) {
                        $map[$cp] = ($cp + $delta) & 0xFFFF;
                        continue;
                    }
                    $glyphAddress = $rangeBase + $s * 2 + $rangeOffset + ($cp - $start) * 2;
                    if ($glyphAddress + 2 > \strlen($data)) {
                        continue;
                    }
                    $gid = self::u16($data, $glyphAddress);
                    if ($gid !== 0) {
                        $map[$cp] = ($gid + $delta) & 0xFFFF;
                    }
                }
            }
            return $map;
        }

        if ($format === 12) {
            $groups = self::u32($data, $sub + 12);
            for ($g = 0; $g < $groups; $g++) {
                $rec = $sub + 16 + $g * 12;
                $start = self::u32($data, $rec);
                $end = self::u32($data, $rec + 4);
                $startGid = self::u32($data, $rec + 8);
                if ($end - $start > 65535) {
                    $end = $start + 65535;
                }
                for ($cp = $start; $cp <= $end; $cp++) {
                    $map[$cp] = $startGid + ($cp - $start);
                }
            }
            return $map;
        }

        if ($format === 6) {
            $first = self::u16($data, $sub + 6);
            $count = self::u16($data, $sub + 8);
            for ($i = 0; $i < $count; $i++) {
                $map[$first + $i] = self::u16($data, $sub + 10 + $i * 2);
            }
        }
        return $map;
    }

    private static function u16(string $d, int $p): int
    {
        if ($p + 2 > \strlen($d)) {
            return 0;
        }
        return (unpack('n', \substr($d, $p, 2))[1]) ?? 0;
    }

    private static function i16(string $d, int $p): int
    {
        $v = self::u16($d, $p);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $d, int $p): int
    {
        if ($p + 4 > \strlen($d)) {
            return 0;
        }
        return (unpack('N', \substr($d, $p, 4))[1]) ?? 0;
    }
}
