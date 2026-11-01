<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance\Cases;

/**
 * Path construction, fill rules and subpath semantics.
 */
trait Paths
{
    private static function pathCases(): array
    {
        $c = [];
        $add = function (string $id, array $ops, string $tag, string $tier = 'normal', int $w = 48, int $h = 40) use (&$c): void {
            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag, 'ops' => $ops];
        };

        $add('path/triangle-fill', [
            ['setFill', '#ff0000'], ['beginPath'], ['moveTo', 6, 6], ['lineTo', 38, 12], ['lineTo', 18, 34], ['fill'],
        ], 'path');
        $add('path/triangle-implicit-close', [
            ['setFill', '#ff0000'], ['beginPath'], ['moveTo', 6, 6], ['lineTo', 38, 12], ['lineTo', 18, 34], ['fill'],
        ], 'path');
        $add('path/triangle-closepath', [
            ['setFill', '#ff0000'], ['beginPath'], ['moveTo', 6, 6], ['lineTo', 38, 12], ['lineTo', 18, 34], ['closePath'], ['fill'],
        ], 'path');
        $add('path/triangle-stroke-1', [
            ['setStroke', '#ff0000'], ['beginPath'], ['moveTo', 6, 6], ['lineTo', 38, 12], ['lineTo', 18, 34], ['closePath'], ['stroke'],
        ], 'stroke');
        $add('path/open-shape-stroke', [
            ['setStroke', '#00ff00'], ['beginPath'], ['moveTo', 4, 6], ['lineTo', 42, 14], ['lineTo', 10, 32], ['stroke'],
        ], 'stroke');
        $add('path/rect-in-path', [
            ['setFill', '#ff0000'], ['beginPath'], ['rect', 6, 6, 30, 20], ['fill'],
        ], 'path');
        $add('path/rect-in-path-stroke', [
            ['setStroke', '#ff0000'], ['beginPath'], ['rect', 6, 6, 30, 20], ['stroke'],
        ], 'stroke');
        $add('path/two-subpaths', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['rect', 4, 4, 12, 12], ['rect', 28, 22, 12, 12], ['fill'],
        ], 'path');
        $add('path/two-subpaths-move', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 4], ['lineTo', 16, 4], ['lineTo', 16, 16], ['lineTo', 4, 16], ['closePath'],
            ['moveTo', 28, 22], ['lineTo', 40, 22], ['lineTo', 40, 34], ['lineTo', 28, 34], ['closePath'],
            ['fill'],
        ], 'path');
        $add('path/disjoint-gap', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 2, 2], ['lineTo', 10, 2], ['lineTo', 10, 10], ['closePath'],
            ['moveTo', 30, 30], ['lineTo', 40, 30], ['lineTo', 40, 38], ['closePath'],
            ['fill'],
        ], 'path');
        $add('path/overlapping-nonzero', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 6, 6], ['lineTo', 30, 6], ['lineTo', 30, 30], ['lineTo', 6, 30], ['closePath'],
            ['moveTo', 18, 18], ['lineTo', 42, 18], ['lineTo', 42, 38], ['lineTo', 18, 38], ['closePath'],
            ['fill'],
        ], 'fillrule');
        $add('path/overlapping-evenodd', [
            ['beginPath'],
            ['moveTo', 6, 6], ['lineTo', 30, 6], ['lineTo', 30, 30], ['lineTo', 6, 30], ['closePath'],
            ['moveTo', 18, 18], ['lineTo', 42, 18], ['lineTo', 42, 38], ['lineTo', 18, 38], ['closePath'],
            ['setFill', '#ff0000'], ['fill', 'evenodd'],
        ], 'fillrule');
        $add('path/reversed-winding-nonzero', [
            ['beginPath'],
            ['moveTo', 6, 6], ['lineTo', 30, 6], ['lineTo', 30, 30], ['lineTo', 6, 30], ['closePath'],
            ['moveTo', 18, 18], ['lineTo', 18, 38], ['lineTo', 42, 38], ['lineTo', 42, 18], ['closePath'],
            ['setFill', '#ff0000'], ['fill'],
        ], 'fillrule');
        $add('path/reversed-winding-evenodd', [
            ['beginPath'],
            ['moveTo', 6, 6], ['lineTo', 30, 6], ['lineTo', 30, 30], ['lineTo', 6, 30], ['closePath'],
            ['moveTo', 18, 18], ['lineTo', 18, 38], ['lineTo', 42, 38], ['lineTo', 42, 18], ['closePath'],
            ['setFill', '#ff0000'], ['fill', 'evenodd'],
        ], 'fillrule');
        $add('path/donut-evenodd', [
            ['beginPath'], ['rect', 8, 8, 32, 24],
            ['rect', 18, 14, 12, 12],
            ['setFill', '#ff0000'], ['fill', 'evenodd'],
        ], 'fillrule');
        $add('path/donut-nonzero', [
            ['beginPath'], ['rect', 8, 8, 32, 24], ['rect', 18, 14, 12, 12],
            ['setFill', '#ff0000'], ['fill'],
        ], 'fillrule');
        $add('path/star-selfintersect', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 24, 4], ['lineTo', 30, 34], ['lineTo', 6, 15], ['lineTo', 42, 15], ['lineTo', 18, 34], ['closePath'],
            ['fill'],
        ], 'fillrule');
        $add('path/star-selfintersect-evenodd', [
            ['beginPath'],
            ['moveTo', 24, 4], ['lineTo', 30, 34], ['lineTo', 6, 15], ['lineTo', 42, 15], ['lineTo', 18, 34], ['closePath'],
            ['setFill', '#ff0000'], ['fill', 'evenodd'],
        ], 'fillrule');
        $add('path/fill-empty', [['setFill', '#ff0000'], ['beginPath'], ['fill']], 'path');
        $add('path/stroke-empty', [['setStroke', '#ff0000'], ['beginPath'], ['stroke']], 'path');
        $add('path/stroke-only-move', [
            ['setStroke', '#ff0000'], ['beginPath'], ['moveTo', 24, 20], ['stroke'],
        ], 'stroke');
        $add('path/stroke-zero-length-round', [
            ['setStroke', '#ff0000'], ['setLineCap', 'round'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 24, 20], ['lineTo', 24, 20], ['stroke'],
        ], 'stroke');
        $add('path/stroke-zero-length-butt', [
            ['setStroke', '#ff0000'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 24, 20], ['lineTo', 24, 20], ['stroke'],
        ], 'stroke');
        $add('path/stroke-zero-length-square', [
            ['setStroke', '#ff0000'], ['setLineCap', 'square'], ['setLineWidth', 8],
            ['beginPath'], ['moveTo', 24, 20], ['lineTo', 24, 20], ['stroke'],
        ], 'stroke');
        $add('path/lineto-without-move', [
            ['setStroke', '#ff0000'], ['beginPath'], ['lineTo', 20, 30], ['stroke'],
        ], 'path');
        $add('path/fill-without-beginpath', [
            ['setFill', '#ff0000'], ['moveTo', 10, 10], ['lineTo', 40, 10], ['lineTo', 25, 35], ['fill'],
        ], 'path');
        $add('path/beginpath-resets', [
            ['setFill', '#ff0000'], ['beginPath'], ['rect', 4, 4, 10, 10], ['beginPath'], ['rect', 30, 26, 10, 10], ['fill'],
        ], 'path');
        $add('path/thin-diagonal', [
            ['setStroke', '#ffffff'], ['beginPath'], ['moveTo', 4, 4], ['lineTo', 44, 36], ['stroke'],
        ], 'stroke');
        $add('path/hairline-horizontal', [
            ['setStroke', '#ffffff'], ['beginPath'], ['moveTo', 4, 12.5], ['lineTo', 44, 12.5], ['stroke'],
        ], 'stroke');
        $add('path/hairline-vertical', [
            ['setStroke', '#ffffff'], ['beginPath'], ['moveTo', 24.5, 2], ['lineTo', 24.5, 38], ['stroke'],
        ], 'stroke');
        $add('path/two-strokes-overlap', [
            ['setStroke', 'rgba(255,0,0,0.6)'], ['setLineWidth', 6],
            ['beginPath'], ['moveTo', 4, 12], ['lineTo', 40, 12], ['stroke'],
            ['beginPath'], ['moveTo', 12, 20], ['lineTo', 44, 20], ['stroke'],
        ], 'stroke');
        $add('path/path-reuse-stroke-then-fill', [
            ['beginPath'], ['rect', 8, 8, 28, 20],
            ['setStroke', '#00ff00'], ['setLineWidth', 4], ['stroke'],
            ['setFill', '#ff0000'], ['fill'],
        ], 'path');

        return $c;
    }
}
