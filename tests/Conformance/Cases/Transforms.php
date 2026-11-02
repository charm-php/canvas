<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance\Cases;

/**
 * The CTM: composition order, save/restore of transform and clip.
 */
trait Transforms
{
    private static function transformCases(): array
    {
        $c = [];

        $add = function (string $id, array $ops, string $tag, string $tier = 'strict', int $w = 48, int $h = 40, ?string $known = null) use (&$c): void {

            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,

                      'ops' => $ops, 'known' => $known];

        };
        $box = ['setFill', '#ff0000'];
        $r = ['fillRect', 4, 4, 12, 8];

        $add('ctm/identity', [$box, $r], 'ctm');
        $add('ctm/translate-int', [$box, ['translate', 8, 6], $r], 'ctm');
        $add('ctm/translate-fractional', [$box, ['translate', 8.5, 6.25], $r], 'ctm');
        $add('ctm/translate-negative', [$box, ['translate', -4, -2], $r], 'ctm');
        $add('ctm/translate-twice', [$box, ['translate', 4, 4], ['translate', 10, 2], $r], 'ctm');
        $add('ctm/scale-2', [$box, ['scale', 2, 2], $r], 'ctm');
        $add('ctm/scale-xy', [$box, ['scale', 2, 0.5], $r], 'ctm');
        $add('ctm/scale-nonuniform-rect', [$box, ['scale', 3, 1.5], ['fillRect', 2, 2, 8, 6]], 'ctm');
        $add('ctm/scale-then-translate', [$box, ['scale', 2, 2], ['translate', 4, 4], ['fillRect', 0, 0, 6, 4]], 'ctm');
        $add('ctm/translate-then-scale', [$box, ['translate', 4, 4], ['scale', 2, 2], ['fillRect', 0, 0, 6, 4]], 'ctm');
        $add('ctm/rotate-90', [$box, ['translate', 24, 24], ['rotate', M_PI / 2], ['fillRect', 0, -4, 16, 8]], 'ctm');
        $add('ctm/rotate-45-rect', [$box, ['translate', 24, 20], ['rotate', M_PI / 4], ['fillRect', -10, -6, 20, 12]], 'ctm', 'normal');
        $add('ctm/rotate-45-nosave', [$box, ['rotate', M_PI / 4], ['fillRect', 2, 2, 20, 10]], 'ctm', 'normal');
        $add('ctm/rotate-then-translate', [$box, ['rotate', M_PI / 2], ['translate', 10, 0], ['fillRect', 0, 0, 8, 6]], 'ctm');
        $add('ctm/translate-then-rotate', [$box, ['translate', 10, 0], ['rotate', M_PI / 2], ['fillRect', 0, 0, 8, 6]], 'ctm');
        $add('ctm/skew', [$box, ['transform', 1, 0.5, 0, 1, 0, 0], ['fillRect', 4, 4, 12, 8]], 'ctm', 'normal');
        $add('ctm/skew-both', [$box, ['transform', 1, 0.3, 0.6, 1, 2, 2], ['fillRect', 4, 4, 12, 8]], 'ctm', 'normal');
        $add('ctm/matrix-combined', [$box, ['transform', 0.8, 0.4, -0.4, 0.8, 10, 6], ['fillRect', 2, 2, 14, 10]], 'ctm', 'normal');
        $add('ctm/setTransform-replaces', [
            $box, ['translate', 100, 100], ['setTransform', 1, 0, 0, 1, 4, 4], $r,
        ], 'ctm');
        $add('ctm/setTransform-after-scale', [
            $box, ['scale', 8, 8], ['setTransform', 2, 0, 0, 2, 2, 2], $r,
        ], 'ctm');
        $add('ctm/resetTransform', [
            $box, ['translate', 100, 100], ['resetTransform'], $r,
        ], 'ctm');
        $add('ctm/scale-negative', [$box, ['scale', -1, 1], ['translate', -44, 0], ['fillRect', 4, 4, 12, 8]], 'ctm');
        $add('ctm/scale-zero-x', [$box, ['scale', 0, 1], ['fillRect', 4, 4, 12, 8]], 'ctm');
        $add('ctm/scale-zero-both', [$box, ['scale', 0, 0], ['fillRect', 4, 4, 12, 8]], 'ctm');
        $add('ctm/save-restore-transform', [
            $box, ['save'], ['translate', 20, 0], ['fillRect', 4, 4, 8, 6], ['restore'], ['fillRect', 4, 4, 8, 6],
        ], 'ctm');
        $add('ctm/nested-save', [
            $box,
            ['save'], ['translate', 6, 0], ['fillRect', 0, 4, 6, 6],
            ['save'], ['translate', 6, 0], ['fillRect', 0, 12, 6, 6],
            ['restore'], ['fillRect', 0, 20, 6, 6],
            ['restore'], ['fillRect', 0, 28, 6, 6],
        ], 'ctm');
        $add('ctm/restore-without-save', [$box, ['restore'], ['restore'], $r], 'ctm');
        $add('ctm/transform-composition-chain', [
            $box,
            ['translate', 6, 6], ['scale', 2, 1], ['rotate', 0.5], ['translate', 4, 4],
            ['fillRect', 0, 0, 8, 8],
        ], 'ctm', 'normal');
        $add('ctm/huge-translate', [$box, ['translate', 10000, 4], $r], 'ctm');
        $add('ctm/fractional-rect-scaled', [$box, ['scale', 3, 3], ['fillRect', 1.5, 1.5, 3.5, 2.5]], 'ctm', 'normal');

        // ---- clipping -----------------------------------------------------
        $add('clip/rect', [
            ['beginPath'], ['rect', 10, 8, 20, 16], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/rect-offset', [
            ['beginPath'], ['rect', 6, 6, 30, 20], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', -10, -10, 40, 30],
        ], 'clip');
        $add('clip/circle', [
            ['beginPath'], ['arc', 24, 20, 14, 0, M_PI * 2], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip', 'aa-edge');
        $add('clip/triangle', [
            ['beginPath'], ['moveTo', 24, 4], ['lineTo', 44, 36], ['lineTo', 4, 36], ['closePath'], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/intersection-two', [
            ['beginPath'], ['rect', 4, 4, 24, 32], ['clip'],
            ['beginPath'], ['rect', 16, 0, 28, 24], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/disjoint-intersection', [
            ['beginPath'], ['rect', 4, 4, 10, 10], ['clip'],
            ['beginPath'], ['rect', 30, 30, 10, 10], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/restored-by-restore', [
            ['save'], ['beginPath'], ['rect', 12, 12, 16, 16], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
            ['restore'],
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/empty-path-clips-all', [
            ['beginPath'], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/with-transform-inside', [
            ['beginPath'], ['rect', 8, 8, 16, 16], ['clip'],
            ['translate', 6, 6], ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/path-transformed-at-clip-time', [
            ['translate', 10, 6], ['beginPath'], ['rect', 0, 0, 16, 12], ['clip'],
            ['setTransform', 1, 0, 0, 1, 0, 0],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/then-scale', [
            ['beginPath'], ['rect', 0, 0, 20, 16], ['clip'],
            ['scale', 4, 4], ['setFill', '#ff0000'], ['fillRect', 0, 0, 20, 10],
        ], 'clip');
        $add('clip/stroke-clipped', [
            ['beginPath'], ['rect', 10, 0, 12, 40], ['clip'],
            ['setStroke', '#ff0000'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 4, 20], ['lineTo', 44, 20], ['stroke'],
        ], 'clip');
        $add('clip/evenodd-donut', [
            ['beginPath'], ['rect', 6, 6, 36, 28], ['rect', 16, 12, 16, 16], ['clip', 'evenodd'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');
        $add('clip/nested-inside-clip', [
            ['save'],
            ['beginPath'], ['rect', 4, 4, 30, 30], ['clip'],
            ['save'],
            ['beginPath'], ['rect', 16, 16, 30, 30], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
            ['restore'],
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 48, 40],
            ['restore'],
        ], 'clip');
        $add('clip/curve-clip', [
            ['beginPath'], ['moveTo', 4, 36], ['quadraticCurveTo', 24, 0, 44, 36], ['closePath'], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip', 'aa-edge');
        $add('clip/offscreen-clip', [
            ['beginPath'], ['rect', 100, 100, 20, 20], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 48, 40],
        ], 'clip');

        return $c;
    }
}
