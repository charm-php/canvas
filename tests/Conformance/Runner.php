<?php declare(strict_types=1);

namespace Charm\Canvas\Conformance;

use Charm\Canvas\Canvas;

/**
 * Runs the conformance suite: every op program is executed by the library and
 * by Chrome, then the two renderings are compared.
 */
final class Runner
{
    /**
     * Divergences that exist only with the Imagick renderer, each an engine
     * difference that supersampling and calibration do not remove.
     */
    private const IMAGICK_KNOWN = [
        'gco/color-dodge' => 'ImageMagick 6 color-dodge is not the W3C formula: where the backdrop channel is 0 and the source channel is 1 it returns 1, the specification and Chrome return 0',
        'gco/color-dodge/alpha' => 'as gco/color-dodge: ImageMagick 6 color-dodge differs from the W3C formula for a zero backdrop channel',
        'path/rect-in-path-stroke' => 'ImageMagick 6 polygon antialiasing is a distance ramp whose error depends on edge direction (a half-covered 1px row reads 109 or 135, Chrome 128); supersampling cancels only its mean bias',
        'roundrect/stroke' => 'as path/rect-in-path-stroke: direction-dependent ramp error on a 1px stroke',
        'clip/triangle' => 'as path/rect-in-path-stroke: the residual ramp error shows on a diagonal clip edge (max 12 of 255 per edge pixel)',
        'clip/evenodd-donut' => 'as path/rect-in-path-stroke: the residual ramp error shows on curved clip edges',
    ];

    /** @var array<int,array{w:int,h:int,ops:array}> */
    private array $phpPrograms = [];

    /**
     * @param list<string> $tags only run cases whose tag matches (substring)
     * @return array{results: array<string,array>, duration: float, chrome: string}
     */
    public static function run(?string $filter = null, ?string $tag = null): array
    {
        $started = microtime(true);
        $cases = self::filtered($filter, $tag);
        $fx = new Fixture();
        $fontPath = Fixture::fontPath() ?? '';

        // --- run every program through the library -------------------------
        $phpOut = [];
        foreach ($cases as $case) {
            $phpOut[$case['id']] = self::renderPhp($case, $fx, $fontPath);
        }

        // --- run every program through Chrome ------------------------------
        $chrome = new Chrome();
        $oracle = $chrome->render($cases);

        // --- compare --------------------------------------------------------
        $results = [];
        foreach ($cases as $case) {
            $id = $case['id'];
            $mine = $phpOut[$id];
            $theirs = $oracle[$id] ?? null;

            if (!$mine['ok']) {
                $results[$id] = self::fail($case, 'error', (string) $mine['error']);
                continue;
            }
            if ($theirs === null) {
                $results[$id] = self::fail($case, 'no-oracle', 'Chrome returned no pixels for this case');
                continue;
            }

            $m = Metrics::compare($mine['rgba'], $theirs['rgba'], $case['w'], $case['h'], $case['tier']);
            $note = $case['known'] ?? (Canvas::rendererName() === 'imagick' ? (self::IMAGICK_KNOWN[$id] ?? null) : null);
            $status = $m['pass'] ? 'pass' : 'fail';
            if ($note !== null) {
                if ($m['pass']) {
                    // A stale note is a report of its own: Chrome and the
                    // library agree now, so the divergence must stop being
                    // declared.
                    $m['pass'] = false;
                    $m['reason'] = 'documented divergence now matches Chrome, remove the note';
                    $status = 'stale-note';
                } else {
                    $status = 'known';
                }
            }
            $results[$id] = array_merge($m, [
                'id' => $id,
                'tag' => $case['tag'],
                'tier' => $case['tier'],
                'known' => $note,
                'status' => $status,
                'kind' => $m['pass'] ? 'ok' : ($status === 'known' ? 'known' : 'diff'),
                'map' => $m['pass'] ? '' : Metrics::asciiMap($mine['rgba'], $theirs['rgba'], $case['w'], $case['h']),
                'php' => $mine['rgba'],
                'chrome' => $theirs['rgba'],
            ]);
        }

        return [
            'results' => $results,
            'duration' => microtime(true) - $started,
            'chrome' => $chrome->version(),
        ];
    }

    /**
     * @return array<int,array{id:string,w:int,h:int,tier:string,tag:string,ops:array}>
     */
    public static function filtered(?string $filter, ?string $tag): array
    {
        $out = [];
        foreach (Cases::all() as $case) {
            if ($filter !== null && !fnmatch($filter, $case['id'])) {
                continue;
            }
            if ($tag !== null && $case['tag'] !== $tag) {
                continue;
            }
            $out[] = $case;
        }
        return $out;
    }

    /**
     * @return array{ok:bool,error:?string,rgba:string}
     */
    private static function renderPhp(array $case, Fixture $fx, string $fontPath): array
    {
        try {
            $canvas = new Canvas($case['w'], $case['h']);
            $rm = new \ReflectionMethod($canvas, 'getContext');
            $ctx = $rm->getNumberOfParameters() > 0 ? $canvas->getContext('2d') : $canvas->getContext();

            $ops = $case['ops'];
            if ($fontPath !== '') {
                // Both engines must use the same glyphs: point the library at
                // the very TTF the browser was given via @font-face.
                $ops = array_map(function ($op) use ($fontPath) {
                    if ($op[0] === 'setFont' && isset($op[1]) && str_contains($op[1], 'ConfSans')) {
                        $op[1] = str_replace('ConfSans', '"' . $fontPath . '"', $op[1]);
                    }
                    return $op;
                }, $ops);
            }

            $r = Ops::run($ctx, $ops, $fx);
            if (!$r['ok']) {
                return ['ok' => false, 'error' => $r['error'], 'rgba' => ''];
            }
            $pixels = $canvas->toPixels();

            return ['ok' => true, 'error' => null, 'rgba' => $pixels['rgba']];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage(),
                'rgba' => '',
            ];
        }
    }

    /**
     * Print PHP and Chrome side by side for one case, as alpha grids and as
     * a list of the pixels that disagree.
     */
    public static function dump(string $caseId): string
    {
        $cases = self::filtered($caseId, null);
        if ($cases === []) {
            return "no such case: $caseId\n";
        }
        $case = $cases[0];
        $fx = new Fixture();
        $mine = self::renderPhp($case, $fx, Fixture::fontPath() ?? '');
        $chrome = new Chrome();
        $oracle = $chrome->render([$case]);
        $theirs = $oracle[$caseId] ?? null;
        if (!$mine['ok']) {
            return 'PHP error: ' . $mine['error'] . "\n";
        }
        if ($theirs === null) {
            return "no oracle pixels\n";
        }

        $w = $case['w'];
        $h = $case['h'];
        $a = $mine['rgba'];
        $b = $theirs['rgba'];
        $out = "case $caseId  ({$case['tier']})  {$w}x{$h}\n\n";
        foreach ([['php', $a], ['chrome', $b]] as [$label, $px]) {
            $out .= "$label:\n";
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $o = ($y * $w + $x) * 4;
                    $al = \ord($px[$o + 3]);
                    $out .= $al === 0 ? '.' : ($al > 250 ? '#' : ($al > 120 ? '+' : ($al > 30 ? ':' : ',')));
                }
                $out .= "\n";
            }
            $out .= "\n";
        }

        $m = Metrics::compare($a, $b, $w, $h, $case['tier']);
        $out .= sprintf(
            "agree %.1f%%  cov %.1f%% vs %.1f%%  bbox php [%s] chrome [%s]  maxDiff %d  mad %.2f\n",
            $m['agreement'] * 100,
            $m['phpCoverage'] * 100,
            $m['chromeCoverage'] * 100,
            implode(',', $m['phpBbox']),
            implode(',', $m['chromeBbox']),
            $m['maxDiff'],
            $m['mad'],
        );

        $out .= "\ndifferences (x, y: php rgba | chrome rgba):\n";
        $shown = 0;
        for ($y = 0; $y < $h && $shown < 60; $y++) {
            $line = '';
            for ($x = 0; $x < $w; $x++) {
                $o = ($y * $w + $x) * 4;
                $d = abs(\ord($a[$o]) - \ord($b[$o])) + abs(\ord($a[$o + 1]) - \ord($b[$o + 1]))
                    + abs(\ord($a[$o + 2]) - \ord($b[$o + 2])) + abs(\ord($a[$o + 3]) - \ord($b[$o + 3]));
                if ($d <= 3) {
                    continue;
                }
                $line .= sprintf(
                    "  (%d,%d) %3d,%3d,%3d,%3d | %3d,%3d,%3d,%3d\n",
                    $x,
                    $y,
                    \ord($a[$o]),
                    \ord($a[$o + 1]),
                    \ord($a[$o + 2]),
                    \ord($a[$o + 3]),
                    \ord($b[$o]),
                    \ord($b[$o + 1]),
                    \ord($b[$o + 2]),
                    \ord($b[$o + 3]),
                );
                $shown++;
                if ($shown >= 60) {
                    break;
                }
            }
            $out .= $line;
        }
        return $out;
    }

    private static function fail(array $case, string $kind, string $reason): array
    {
        return [
            'pass' => false,
            'reason' => $reason,
            'agreement' => 0.0,
            'coverageDelta' => 1.0,
            'bboxDelta' => 999,
            'mad' => 255.0,
            'maxDiff' => 255,
            'phpBbox' => [0, 0, 0, 0],
            'chromeBbox' => [0, 0, 0, 0],
            'phpCoverage' => 0.0,
            'chromeCoverage' => 0.0,
            'id' => $case['id'],
            'tag' => $case['tag'],
            'tier' => $case['tier'],
            'known' => $case['known'] ?? null,
            'status' => 'fail',
            'kind' => $kind,
            'map' => '',
            'php' => '',
            'chrome' => '',
        ];
    }
}
