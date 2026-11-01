<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

use phasync\Canvas\Canvas;

/**
 * Runs the conformance suite: every op program is executed by the library and
 * by Chrome, then the two renderings are compared.
 */
final class Runner
{
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
            $results[$id] = array_merge($m, [
                'id' => $id,
                'tag' => $case['tag'],
                'tier' => $case['tier'],
                'status' => $m['pass'] ? 'pass' : 'fail',
                'kind' => $m['pass'] ? 'ok' : 'diff',
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
            'status' => 'fail',
            'kind' => $kind,
            'map' => '',
            'php' => '',
            'chrome' => '',
        ];
    }
}
