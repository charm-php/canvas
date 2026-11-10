<?php declare(strict_types=1);

namespace Charm\Canvas\Conformance\Cases;

/**
 * Stroke geometry: widths, caps, joins, miters and dashes.
 */
trait Strokes
{
    private static function strokeCases(): array
    {
        $c = [];
        $add = function (string $id, array $ops, string $tag, string $tier = 'normal', int $w = 48, int $h = 40, ?string $known = null) use (&$c): void {
            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,
                      'ops' => $ops, 'known' => $known];
        };

        foreach ([1, 1.5, 2, 2.5, 3, 4, 5.5, 8, 13] as $lw) {
            $add('width/w' . str_replace('.', '_', (string) $lw), [
                ['setStroke', '#ff0000'], ['setLineWidth', $lw],
                ['beginPath'], ['moveTo', 6, 20], ['lineTo', 42, 20], ['stroke'],
            ], 'width');
        }
        $add('width/zero', [
            ['setStroke', '#ff0000'], ['setLineWidth', 0],
            ['beginPath'], ['moveTo', 6, 20], ['lineTo', 42, 20], ['stroke'],
        ], 'width');
        $add('width/negative-ignored', [
            ['setStroke', '#ff0000'], ['setLineWidth', -5],
            ['beginPath'], ['moveTo', 6, 20], ['lineTo', 42, 20], ['stroke'],
        ], 'width');
        $add('width/vertical-thick', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6],
            ['beginPath'], ['moveTo', 24, 4], ['lineTo', 24, 36], ['stroke'],
        ], 'width');
        $add('width/diagonal-thick', [
            ['setStroke', '#ff0000'], ['setLineWidth', 7],
            ['beginPath'], ['moveTo', 6, 6], ['lineTo', 42, 34], ['stroke'],
        ], 'width');

        foreach (['butt', 'round', 'square'] as $cap) {
            $add('cap/' . $cap, [
                ['setStroke', '#ff0000'], ['setLineWidth', 10], ['setLineCap', $cap],
                ['beginPath'], ['moveTo', 12, 12], ['lineTo', 36, 12], ['stroke'],
                ['beginPath'], ['moveTo', 12, 28], ['lineTo', 36, 28], ['stroke'],
            ], 'cap');
            $add('cap/' . $cap . '-closed', [
                ['setStroke', '#ff0000'], ['setLineWidth', 10], ['setLineCap', $cap],
                ['beginPath'], ['moveTo', 12, 20], ['lineTo', 36, 20], ['closePath'], ['stroke'],
            ], 'cap');
        }

        foreach (['miter', 'round', 'bevel'] as $join) {
            $add('join/' . $join, [
                ['setStroke', '#ff0000'], ['setLineWidth', 10], ['setLineJoin', $join],
                ['beginPath'], ['moveTo', 8, 32], ['lineTo', 24, 8], ['lineTo', 40, 32], ['stroke'],
            ], 'join');
            $add('join/' . $join . '-right', [
                ['setStroke', '#ff0000'], ['setLineWidth', 12], ['setLineJoin', $join],
                ['beginPath'], ['moveTo', 8, 8], ['lineTo', 8, 32], ['lineTo', 40, 32], ['stroke'],
            ], 'join');
        }
        $add('join/miter-sharp-default', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 6, 34], ['lineTo', 24, 6], ['lineTo', 42, 34], ['stroke'],
        ], 'join');
        $add('join/miter-limit-1', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8], ['setLineJoin', 'miter'], ['setMiterLimit', 1],
            ['beginPath'], ['moveTo', 6, 34], ['lineTo', 24, 6], ['lineTo', 42, 34], ['stroke'],
        ], 'join');
        $add('join/miter-limit-5', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8], ['setLineJoin', 'miter'], ['setMiterLimit', 5],
            ['beginPath'], ['moveTo', 6, 34], ['lineTo', 24, 6], ['lineTo', 42, 34], ['stroke'],
        ], 'join');
        $add('join/miter-very-sharp', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setMiterLimit', 12],
            ['beginPath'], ['moveTo', 4, 34], ['lineTo', 24, 30], ['lineTo', 44, 34], ['stroke'],
        ], 'join');
        $add('join/closed-triangle', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setLineJoin', 'round'],
            ['beginPath'], ['moveTo', 24, 6], ['lineTo', 42, 34], ['lineTo', 6, 34], ['closePath'], ['stroke'],
        ], 'join');

        $add('dash/simple', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [6, 4]],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');
        $add('dash/offset', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [6, 4], 5],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');
        $add('dash/offset-float', [
            ['setStroke', '#ff0000'], ['setLineWidth', 4], ['setDash', [8, 3], 2.5],
            ['beginPath'], ['moveTo', 2, 12], ['lineTo', 46, 12], ['stroke'],
            ['beginPath'], ['moveTo', 2, 28], ['lineTo', 46, 28], ['stroke'],
        ], 'dash');
        $add('dash/round-caps', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8], ['setLineCap', 'round'], ['setDash', [1, 6]],
            ['beginPath'], ['moveTo', 4, 20], ['lineTo', 44, 20], ['stroke'],
        ], 'dash', 'thin');
        $add('dash/butt-caps', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8], ['setDash', [1, 6]],
            ['beginPath'], ['moveTo', 4, 20], ['lineTo', 44, 20], ['stroke'],
        ], 'dash');
        $add('dash/closed-rect', [
            ['setStroke', '#ff0000'], ['setLineWidth', 3], ['setDash', [4, 3]],
            ['beginPath'], ['rect', 6, 6, 36, 28], ['stroke'],
        ], 'dash');
        $add('dash/circle', [
            ['setStroke', '#ff0000'], ['setLineWidth', 4], ['setDash', [5, 5]],
            ['beginPath'], ['arc', 24, 20, 14, 0, M_PI * 2], ['stroke'],
        ], 'dash', 'thin');
        $add('dash/uneven', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [10, 2, 2, 2]],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');
        $add('dash/empty-clears', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [6, 4]], ['setDash', []],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');
        $add('dash/all-zero-clears', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [0, 0]],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');
        $add('dash/odd-length', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['setDash', [4, 4, 4]],
            ['beginPath'], ['moveTo', 2, 20], ['lineTo', 46, 20], ['stroke'],
        ], 'dash');

        $add('stroke/under-scale-x', [
            ['setStroke', '#ff0000'], ['setLineWidth', 2], ['scale', 4, 1],
            ['beginPath'], ['moveTo', 2, 6], ['lineTo', 10, 6], ['stroke'],
        ], 'stroke-ctm', 'normal', 48, 40);
        $add('stroke/under-scale-y', [
            ['setStroke', '#ff0000'], ['setLineWidth', 2], ['scale', 1, 4],
            ['beginPath'], ['moveTo', 6, 4], ['lineTo', 6, 8], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/under-scale-up', [
            ['setStroke', '#ff0000'], ['setLineWidth', 1], ['scale', 4, 4],
            ['beginPath'], ['moveTo', 2, 4], ['lineTo', 10, 4], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/under-scale-down', [
            ['setStroke', '#ff0000'], ['setLineWidth', 4], ['scale', 0.25, 0.25],
            ['beginPath'], ['moveTo', 8, 20], ['lineTo', 160, 20], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/under-rotate', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['translate', 24, 20], ['rotate', 0.7],
            ['beginPath'], ['moveTo', -14, 0], ['lineTo', 14, 0], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/under-shear', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['transform', 1, 0, 1, 1, 0, 0],
            ['beginPath'], ['moveTo', 10, 6], ['lineTo', 10, 30], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/under-negative-scale', [
            ['setStroke', '#ff0000'], ['setLineWidth', 6], ['scale', -1, 1], ['translate', -44, 0],
            ['beginPath'], ['moveTo', 4, 20], ['lineTo', 36, 20], ['stroke'],
        ], 'stroke-ctm');
        $add('stroke/gradient', [
            ['gradStroke', 'linear', [0, 0, 48, 0, [[0, '#ff0000'], [1, '#0000ff']]]],
            ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 4, 20], ['lineTo', 44, 20], ['stroke'],
        ], 'gradient');
        $add('stroke/gradient-vertical-thick', [
            ['gradStroke', 'linear', [0, 0, 0, 40, [[0, '#ffffff'], [1, '#000000']]]],
            ['setLineWidth', 12],
            ['beginPath'], ['moveTo', 6, 8], ['lineTo', 42, 8], ['stroke'],
        ], 'gradient');

        return $c;
    }
}
