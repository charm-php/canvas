<?php declare(strict_types=1);

namespace Charm\Canvas\Conformance\Cases;

/**
 * Curves: arc, ellipse, arcTo, Bézier and roundRect.
 */
trait Curves
{
    private static function curveCases(): array
    {
        $c = [];
        $add = function (string $id, array $ops, string $tag, string $tier = 'normal', int $w = 48, int $h = 40, ?string $known = null) use (&$c): void {
            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,
                      'ops' => $ops, 'known' => $known];
        };
        $T = M_PI;
        $T2 = M_PI * 2;

        $add('arc/circle-fill', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, 0, $T2], ['fill'],
        ], 'curve');
        $add('arc/circle-stroke', [
            ['setStroke', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, 0, $T2], ['stroke'],
        ], 'curve', 'thin');
        $add('arc/circle-small', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 1.5, 0, $T2], ['fill'],
        ], 'curve');
        $add('arc/half-cw', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, 0, $T], ['fill'],
        ], 'curve');
        $add('arc/half-ccw', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, 0, $T, true], ['fill'],
        ], 'curve');
        $add('arc/quarter', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, $T / 2, $T * 1.25], ['fill'],
        ], 'curve');
        $add('arc/inverted-args', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, $T, 0], ['fill'],
        ], 'curve');
        $add('arc/inverted-args-ccw', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, $T, 0, true], ['fill'],
        ], 'curve');
        $add('arc/sweep-over-2pi', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, 0, $T2 * 1.5], ['fill'],
        ], 'curve');
        $add('arc/negative-angles', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 14, -$T / 2, $T / 4], ['fill'],
        ], 'curve');
        $add('arc/zero-radius', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 24, 20, 0, 0, $T2], ['fill'],
        ], 'curve');
        $add('arc/offscreen', [
            ['setFill', '#ff0000'], ['beginPath'], ['arc', 200, 200, 10, 0, $T2], ['fill'],
        ], 'curve');
        $add('arc/connected-by-lineto', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 4], ['arc', 24, 20, 12, 0, $T], ['stroke'],
        ], 'curve', 'thin');
        $add('arc/pie-slice', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 24, 20], ['arc', 24, 20, 16, 0.3, 2.2], ['closePath'], ['fill'],
        ], 'curve');

        $add('ellipse/axis-aligned', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 18, 8, 0, 0, $T2], ['fill'],
        ], 'curve');
        $add('ellipse/rotated-45', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 18, 6, $T / 4, 0, $T2], ['fill'],
        ], 'curve');
        $add('ellipse/rotated-half', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 16, 9, 1, 0.5, 4], ['fill'],
        ], 'curve');
        $add('ellipse/degenerate-circle', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 10, 10, 0, 0, $T2], ['fill'],
        ], 'curve');
        $add('ellipse/zero-radius', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 0, 8, 0, 0, $T2], ['fill'],
        ], 'curve');
        $add('ellipse/ccw', [
            ['setFill', '#ff0000'], ['beginPath'], ['ellipse', 24, 20, 16, 8, 0, 0, $T, true], ['fill'],
        ], 'curve');

        $add('arcto/corner', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 4], ['arcTo', 40, 4, 40, 36, 10], ['stroke'],
        ], 'curve', 'thin');
        $add('arcto/corner-fill', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 4], ['arcTo', 40, 4, 40, 36, 10], ['lineTo', 40, 36], ['closePath'], ['fill'],
        ], 'curve');
        $add('arcto/small-radius', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 4], ['arcTo', 40, 4, 40, 36, 2], ['stroke'],
        ], 'curve');
        $add('arcto/collinear', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 20], ['arcTo', 22, 20, 44, 20, 8], ['stroke'],
        ], 'curve');
        $add('arcto/right-angle', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 10, 10], ['lineTo', 38, 10], ['arcTo', 38, 34, 10, 34, 8], ['lineTo', 10, 34], ['closePath'], ['fill'],
        ], 'curve');
        $add('arcto/no-starting-point', [
            ['setStroke', '#ff0000'], ['beginPath'], ['arcTo', 10, 10, 30, 30, 5], ['stroke'],
        ], 'curve');

        $add('bezier/cubic-fill', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 6, 20], ['bezierCurveTo', 6, 2, 42, 2, 42, 20], ['closePath'], ['fill'],
        ], 'curve');
        $add('bezier/cubic-stroke', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 6, 30], ['bezierCurveTo', 14, 2, 34, 38, 44, 8], ['stroke'],
        ], 'curve', 'thin');
        $add('bezier/cubic-s-curve', [
            ['setStroke', '#ff0000'], ['setLineWidth', 2], ['beginPath'],
            ['moveTo', 4, 20], ['bezierCurveTo', 24, 0, 24, 40, 44, 20], ['stroke'],
        ], 'curve', 'thin');
        $add('bezier/cubic-degenerate', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 10, 10], ['bezierCurveTo', 10, 10, 30, 30, 30, 30], ['closePath'], ['fill'],
        ], 'curve', known: 'Chrome leaves antialiasing residue along a cubic whose control points '
            . 'are its own endpoints; the curve encloses no area, so nothing is painted here');
        $add('bezier/cubic-no-start', [
            ['setStroke', '#ff0000'], ['beginPath'], ['bezierCurveTo', 6, 6, 40, 6, 40, 30], ['stroke'],
        ], 'curve', 'thin');
        $add('bezier/quadratic-fill', [
            ['setFill', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 30], ['quadraticCurveTo', 24, 0, 44, 30], ['closePath'], ['fill'],
        ], 'curve');
        $add('bezier/quadratic-stroke', [
            ['setStroke', '#ff0000'], ['setLineWidth', 2], ['beginPath'],
            ['moveTo', 6, 34], ['quadraticCurveTo', 24, 4, 42, 34], ['stroke'],
        ], 'curve');
        $add('bezier/quadratic-chain', [
            ['setStroke', '#ff0000'], ['beginPath'],
            ['moveTo', 4, 20], ['quadraticCurveTo', 14, 4, 24, 20], ['quadraticCurveTo', 34, 36, 44, 20], ['stroke'],
        ], 'curve', 'thin');

        $add('roundrect/uniform', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 4, 4, 40, 32, 8], ['fill'],
        ], 'curve');
        $add('roundrect/stroke', [
            ['setStroke', '#ff0000'], ['setLineWidth', 2], ['beginPath'], ['roundRect', 4, 4, 40, 32, 8], ['stroke'],
        ], 'curve');
        $add('roundrect/zero', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 4, 4, 40, 32, 0], ['fill'],
        ], 'curve');
        $add('roundrect/array-two', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 4, 4, 40, 32, [2, 12]], ['fill'],
        ], 'curve');
        $add('roundrect/array-three', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 4, 4, 40, 32, [2, 10, 4]], ['fill'],
        ], 'curve');
        $add('roundrect/array-four', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 4, 4, 40, 32, [12, 2, 8, 4]], ['fill'],
        ], 'curve');
        $add('roundrect/clamped', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 8, 12, 32, 16, 40], ['fill'],
        ], 'curve');
        $add('roundrect/fully-rounded', [
            ['setFill', '#ff0000'], ['beginPath'], ['roundRect', 8, 8, 32, 24, 12], ['fill'],
        ], 'curve');

        return $c;
    }
}
