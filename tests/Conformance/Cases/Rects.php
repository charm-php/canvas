<?php declare(strict_types=1);

namespace Charm\Canvas\Conformance\Cases;

/**
 * fillRect() / clearRect() — the rasterisation convention cases.
 *
 * These are the strictest cases in the suite: with axis-aligned integer
 * geometry a correct implementation has no antialiasing to hide behind, so
 * every pixel must agree with the browser.
 */
trait Rects
{
    private static function rectCases(): array
    {
        $c = [];

        $add = function (string $id, array $ops, string $tag, string $tier = 'strict', int $w = 32, int $h = 24, ?string $known = null) use (&$c): void {

            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,

                      'ops' => $ops, 'known' => $known];

        };

        $add('fillrect/unit', [['setFill', '#ff0000'], ['fillRect', 0, 0, 1, 1]], 'raster');
        $add('fillrect/integer', [['setFill', '#ff0000'], ['fillRect', 0, 0, 10, 10]], 'raster');
        $add('fillrect/integer-offset', [['setFill', '#00ff00'], ['fillRect', 5, 7, 11, 3]], 'raster');
        $add('fillrect/one-tall', [['setFill', '#0000ff'], ['fillRect', 4, 4, 17, 1]], 'raster');
        $add('fillrect/one-wide', [['setFill', '#0000ff'], ['fillRect', 4, 4, 1, 17]], 'raster');
        $add('fillrect/fractional', [['setFill', '#ff0000'], ['fillRect', 0.5, 0.5, 3.25, 2.5]], 'raster');
        $add('fillrect/fractional-2', [['setFill', '#ff8000'], ['fillRect', 7.25, 3.5, 12.5, 9.75]], 'raster-soft');
        $add('fillrect/negative-size', [['setFill', '#ff0000'], ['fillRect', 20, 20, -8, -6]], 'raster');
        $add('fillrect/negative-mixed', [['setFill', '#00ff00'], ['fillRect', 12, 6, -14, 9]], 'raster');
        $add('fillrect/zero-size', [['setFill', '#ff0000'], ['fillRect', 10, 10, 0, 5], ['fillRect', 10, 10, 5, 0]], 'raster');
        $add('fillrect/offscreen-left', [['setFill', '#ff0000'], ['fillRect', -4, -4, 6, 6]], 'raster');
        $add('fillrect/offscreen-all', [['setFill', '#ff0000'], ['fillRect', 100, 100, 10, 10]], 'raster');
        $add('fillrect/overflow-edges', [['setFill', '#ff0000'], ['fillRect', 28, 20, 10, 10]], 'raster');
        $add('fillrect/huge', [['setFill', '#ff0000'], ['fillRect', -50, -50, 200, 200]], 'raster');
        $add('fillrect/alpha-half', [['setFill', 'rgba(255,0,0,0.5)'], ['fillRect', 4, 4, 16, 12]], 'alpha');
        $add('fillrect/alpha-quarter', [['setFill', 'rgba(0,255,0,0.25)'], ['fillRect', 4, 4, 16, 12]], 'alpha');
        $add('fillrect/alpha-overlap', [
            ['setFill', 'rgba(255,0,0,0.5)'], ['fillRect', 2, 2, 12, 12],
            ['setFill', 'rgba(0,0,255,0.5)'], ['fillRect', 8, 8, 12, 12],
        ], 'compositing');
        $add('fillrect/alpha-stack', [
            ['setFill', 'rgba(0,0,0,0.1)'],
            ['fillRect', 2, 2, 20, 20], ['fillRect', 2, 2, 20, 20], ['fillRect', 2, 2, 20, 20],
        ], 'compositing');
        $add('fillrect/global-alpha', [
            ['setAlpha', 0.4], ['setFill', '#ff0000'], ['fillRect', 4, 4, 16, 12],
        ], 'alpha');
        $add('fillrect/global-alpha-stack', [
            ['setAlpha', 0.5], ['setFill', '#0000ff'],
            ['fillRect', 1, 1, 14, 14], ['fillRect', 8, 8, 14, 14],
        ], 'alpha');
        $add('fillrect/named-colors', [
            ['setFill', 'rebeccapurple'], ['fillRect', 0, 0, 8, 24],
            ['setFill', 'transparent'], ['fillRect', 8, 0, 8, 24],
            ['setFill', 'hsl(120,100%,50%)'], ['fillRect', 16, 0, 8, 24],
        ], 'color');
        $add('fillrect/hex-alpha', [
            ['setFill', '#ff000080'], ['fillRect', 0, 4, 32, 8],
        ], 'color');

        $add('clearrect/over-fill', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['clearRect', 8, 6, 12, 8],
        ], 'clear');
        $add('clearrect/fractional', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['clearRect', 8.25, 6.5, 12.25, 8.5],
        ], 'clear');
        $add('clearrect/negative', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['clearRect', 20, 14, -8, -6],
        ], 'clear');
        $add('clearrect/outside', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['clearRect', 40, 40, 10, 10],
        ], 'clear');
        $add('clearrect/edges', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['clearRect', -3, -3, 6, 6],
            ['clearRect', 30, 22, 6, 6],
        ], 'clear');
        $add('clearrect/transformed-rotate', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['rotate', 0.6], ['clearRect', 4, 4, 12, 8],
        ], 'clear', 'normal');
        $add('clearrect/transformed-scale', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['scale', 2, 1], ['clearRect', 2, 3, 6, 4],
        ], 'clear', 'normal');
        $add('clearrect/ignores-clip', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['beginPath'], ['rect', 20, 20, 4, 4], ['clip'],
            ['clearRect', 8, 8, 10, 6],
        ], 'clear');
        $add('clearrect/ignores-globalalpha', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['setAlpha', 0.3], ['clearRect', 8, 8, 10, 6],
        ], 'clear');
        $add('clearrect/ignores-composite', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 32, 24],
            ['setGCO', 'copy'], ['clearRect', 8, 8, 10, 6],
        ], 'clear');
        $add('clearrect/partial-pixel-coverage', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 20, 20],
            ['clearRect', 5, 5, 10.5, 10.5],
        ], 'clear');

        return $c;
    }
}
