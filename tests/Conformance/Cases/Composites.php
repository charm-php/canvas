<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance\Cases;

/**
 * globalCompositeOperation and globalAlpha across the paint operations.
 */
trait Composites
{
    private static function compositeCases(): array
    {
        $c = [];
        $add = function (string $id, array $ops, string $tag, string $tier = 'normal', int $w = 40, int $h = 32, ?string $known = null) use (&$c): void {
            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,
                      'ops' => $ops, 'known' => $known];
        };

        $ops = [
            'source-over', 'source-in', 'source-out', 'source-atop',
            'destination-over', 'destination-in', 'destination-out', 'destination-atop',
            'lighter', 'copy', 'xor', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
            'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion',
        ];
        foreach ($ops as $mode) {
            $add('gco/' . $mode, [
                ['setFill', '#ff0000'], ['fillRect', 4, 4, 20, 20],
                ['setGCO', $mode],
                ['setFill', '#0000ff'], ['fillRect', 14, 10, 20, 16],
            ], 'gco');
            $add('gco/' . $mode . '/alpha', [
                ['setAlpha', 0.6],
                ['setFill', '#ff0000'], ['fillRect', 4, 4, 20, 20],
                ['setGCO', $mode],
                ['setFill', '#0000ff'], ['fillRect', 14, 10, 20, 16],
            ], 'gco');
        }

        $add('alpha/fill-over-opaque', [
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 40, 32],
            ['setAlpha', 0.5], ['setFill', '#ff0000'], ['fillRect', 8, 8, 24, 16],
        ], 'alpha');
        $add('alpha/translucent-over-translucent', [
            ['setFill', 'rgba(255,0,0,0.5)'], ['fillRect', 4, 4, 20, 20],
            ['setAlpha', 0.5], ['setFill', 'rgba(0,0,255,0.5)'], ['fillRect', 12, 8, 20, 20],
        ], 'alpha');
        $add('alpha/zero-hides', [
            ['setAlpha', 0], ['setFill', '#ff0000'], ['fillRect', 4, 4, 20, 20],
        ], 'alpha');
        $add('alpha/stroke-alpha', [
            ['setAlpha', 0.4], ['setStroke', '#ffffff'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 4, 16], ['lineTo', 36, 16], ['stroke'],
        ], 'alpha');
        $add('alpha/stacked-strokes', [
            ['setAlpha', 0.3], ['setStroke', '#ff0000'], ['setLineWidth', 10],
            ['beginPath'], ['moveTo', 6, 12], ['lineTo', 34, 12], ['stroke'],
            ['beginPath'], ['moveTo', 6, 14], ['lineTo', 34, 14], ['stroke'],
            ['beginPath'], ['moveTo', 6, 16], ['lineTo', 34, 16], ['stroke'],
        ], 'alpha');
        $add('alpha/with-clip', [
            ['beginPath'], ['rect', 6, 6, 28, 20], ['clip'],
            ['setAlpha', 0.5], ['setFill', '#ff0000'], ['fillRect', 0, 0, 40, 32],
        ], 'alpha');
        $add('alpha/gradient-alpha', [
            ['gradFill', 'linear', [0, 0, 40, 0, [[0, 'rgba(255,0,0,1)'], [1, 'rgba(255,0,0,0)']]]],
            ['fillRect', 0, 6, 40, 20],
        ], 'alpha', 'normal');
        $add('alpha/global-alpha-on-gradient', [
            ['setAlpha', 0.5],
            ['gradFill', 'linear', [0, 0, 40, 0, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 6, 40, 20],
        ], 'alpha');
        $add('alpha/color-alpha-multiplies-global', [
            ['setAlpha', 0.5], ['setFill', 'rgba(255,0,0,0.5)'], ['fillRect', 6, 6, 24, 16],
        ], 'alpha');
        $add('alpha/clamped-below', [['setAlpha', -1], ['setFill', '#ff0000'], ['fillRect', 6, 6, 24, 16]], 'alpha');
        $add('alpha/clamped-above', [['setAlpha', 3], ['setFill', '#ff0000'], ['fillRect', 6, 6, 24, 16]], 'alpha');

        $add('gradient/linear-horizontal', [
            ['gradFill', 'linear', [0, 0, 40, 0, [[0, '#000000'], [1, '#ffffff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/linear-vertical', [
            ['gradFill', 'linear', [0, 0, 0, 32, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/linear-diagonal', [
            ['gradFill', 'linear', [0, 0, 40, 32, [[0, '#ff0000'], [0.5, '#00ff00'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/linear-pad-outside', [
            ['gradFill', 'linear', [10, 0, 30, 0, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/linear-zero-length', [
            ['gradFill', 'linear', [20, 16, 20, 16, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/linear-under-transform', [
            ['gradFill', 'linear', [0, 0, 40, 0, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['scale', 2, 1], ['fillRect', 0, 0, 20, 32],
        ], 'gradient', 'normal');
        $add('gradient/radial-basic', [
            ['gradFill', 'radial', [20, 16, 2, 20, 16, 16, [[0, '#ffffff'], [1, '#000000']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/radial-offset-circles', [
            ['gradFill', 'radial', [10, 16, 2, 30, 16, 10, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/radial-inside-circle', [
            ['gradFill', 'radial', [20, 16, 6, 20, 16, 12, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/unsorted-stops', [
            ['gradFill', 'linear', [0, 0, 40, 0, [[1, '#0000ff'], [0, '#ff0000'], [0.5, '#00ff00']]]],
            ['fillRect', 0, 0, 40, 32],
        ], 'gradient', 'normal');
        $add('gradient/fill-path-uses-gradient', [
            ['gradFill', 'linear', [0, 0, 40, 32, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['beginPath'], ['arc', 20, 16, 12, 0, M_PI * 2], ['fill'],
        ], 'gradient', 'normal');

        return $c;
    }
}
