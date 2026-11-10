<?php declare(strict_types=1);

namespace Charm\Canvas\Conformance\Cases;

/**
 * drawImage, getImageData/putImageData, text metrics/rendering, hit testing.
 */
trait Media
{
    private static function mediaCases(): array
    {
        $c = [];
        $add = function (string $id, array $ops, string $tag, string $tier = 'normal', int $w = 40, int $h = 32, ?string $known = null) use (&$c): void {
            $c[] = ['id' => $id, 'w' => $w, 'h' => $h, 'tier' => $tier, 'tag' => $tag,
                      'ops' => $ops, 'known' => $known];
        };

        // ---- drawImage ----------------------------------------------------
        $add('image/3-arg', [['drawImage', 'tile', 4, 4]], 'image');
        $add('image/3-arg-fractional', [['drawImage', 'tile', 4.5, 3.25]], 'image');
        $add('image/3-arg-negative', [['drawImage', 'tile', -3, -2]], 'image');
        $add('image/3-arg-offscreen', [['drawImage', 'tile', 60, 60]], 'image');
        $add('image/5-arg-up', [['drawImage', 'tile', 2, 2, 32, 24]], 'image');
        $add('image/5-arg-down', [['drawImage', 'tile', 2, 2, 4, 3]], 'image');
        $add('image/5-arg-fractional', [['drawImage', 'tile', 1.5, 1.5, 22.5, 18.25]], 'image-soft');
        $add('image/5-arg-neg-width', [['drawImage', 'tile', 20, 4, -16, 16]], 'image');
        $add('image/5-arg-neg-height', [['drawImage', 'tile', 4, 4, 16, -16]], 'image');
        $add('image/5-arg-zero', [['drawImage', 'tile', 4, 4, 0, 10]], 'image');
        $add('image/9-arg', [['drawImage', 'tile', 2, 2, 4, 4, 6, 4, 20, 20]], 'image');
        $add('image/9-arg-fractional-src', [['drawImage', 'tile', 1.5, 1.5, 3.5, 3.5, 4, 4, 24, 24]], 'image-soft');
        $add('image/9-arg-out-of-range', [['drawImage', 'tile', 6, 6, 8, 8, 4, 4, 24, 24]], 'image');
        $add('image/alpha-source', [['drawImage', 'tileAlpha', 4, 4, 32, 24]], 'image');
        $add('image/alpha-source-over-fill', [
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 40, 32],
            ['drawImage', 'tileAlpha', 4, 4, 32, 24],
        ], 'image');
        $add('image/with-global-alpha', [
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 40, 32],
            ['setAlpha', 0.4], ['drawImage', 'tile', 4, 4, 32, 24],
        ], 'alpha');
        $add('image/scaled-up-smooth-off', [
            ['setSmoothing', false], ['drawImage', 'tile', 0, 0, 40, 32],
        ], 'image');
        $add('image/scaled-up-smooth-on', [
            ['setSmoothing', true], ['drawImage', 'tile', 0, 0, 40, 32],
        ], 'image-soft');
        $add('image/scaled-down', [
            ['drawImage', 'tile', 0, 0, 13, 11],
        ], 'image-soft');
        $add('image/rotated', [
            ['translate', 20, 16], ['rotate', 0.6], ['drawImage', 'tile', -16, -12, 32, 24],
        ], 'image-soft');
        $add('image/scaled-and-rotated', [
            ['translate', 20, 16], ['rotate', 0.5], ['scale', 2, 1.5], ['drawImage', 'tile', -8, -6, 16, 12],
        ], 'image-soft');
        $add('image/mirrored', [
            ['scale', -1, 1], ['translate', -40, 0], ['drawImage', 'tile', 4, 4, 32, 24],
        ], 'image');
        $add('image/clipped', [
            ['beginPath'], ['rect', 10, 8, 16, 12], ['clip'],
            ['drawImage', 'tile', 0, 0, 40, 32],
        ], 'image');
        $add('image/two-draws', [
            ['drawImage', 'tile', 2, 2], ['drawImage', 'tile', 6, 6],
        ], 'image');
        $add('image/draw-over-gco', [
            ['setFill', '#00ff00'], ['fillRect', 0, 0, 40, 32],
            ['setGCO', 'copy'], ['drawImage', 'tileAlpha', 4, 4, 32, 24],
        ], 'image');

        // ---- getImageData / putImageData ----------------------------------
        $add('imagedata/copy-opaque', [
            ['setFill', '#ff0000'], ['fillRect', 2, 2, 8, 6],
            ['copyRect', 2, 2, 8, 6, 20, 20],
        ], 'imagedata');
        $add('imagedata/copy-overlap', [
            ['setFill', '#ff0000'], ['fillRect', 2, 2, 12, 8],
            ['copyRect', 2, 2, 12, 8, 8, 8],
        ], 'imagedata');
        $add('imagedata/copy-partial-transparency', [
            ['setFill', 'rgba(255,0,0,0.5)'], ['fillRect', 2, 2, 10, 8],
            ['copyRect', 2, 2, 10, 8, 20, 18],
        ], 'imagedata');
        $add('imagedata/copy-from-empty', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 8, 8],
            ['copyRect', 20, 20, 10, 8, 2, 20],
        ], 'imagedata');
        $add('imagedata/copy-out-of-bounds', [
            ['setFill', '#ff0000'], ['fillRect', 30, 24, 10, 8],
            ['copyRect', 34, 26, 12, 10, 4, 4],
        ], 'imagedata');
        $add('imagedata/copy-clipped-region', [
            ['beginPath'], ['rect', 0, 0, 8, 32], ['clip'],
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 40, 32],
            ['copyRect', 0, 0, 40, 32, 0, 0],
        ], 'imagedata');
        $add('imagedata/put-ignores-transform', [
            ['setFill', '#ff0000'], ['fillRect', 2, 2, 8, 6],
            ['copyRectShift', 2, 2, 8, 6, 20, 20],
        ], 'imagedata');
        $add('imagedata/put-under-transform', [
            ['setFill', '#ff0000'], ['fillRect', 2, 2, 8, 6],
            ['translate', 12, 12],
            ['copyRectShift', 2, 2, 8, 6, 4, 4],
        ], 'imagedata');
        $add('imagedata/put-ignores-globalalpha', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 8, 8],
            ['setAlpha', 0.2], ['copyRect', 0, 0, 8, 8, 20, 20],
        ], 'imagedata');
        $add('imagedata/put-ignores-clip', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 8, 8],
            ['beginPath'], ['rect', 18, 18, 4, 4], ['clip'],
            ['copyRect', 0, 0, 8, 8, 16, 16],
        ], 'imagedata');
        $add('imagedata/put-clipped-by-canvas', [
            ['setFill', '#ff0000'], ['fillRect', 0, 0, 10, 10],
            ['copyRect', 0, 0, 10, 10, 36, 28],
        ], 'imagedata');

        // ---- text (loose: rasterisers legitimately differ) ----------------
        $add('text/basic', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['fillText', 'Hi', 4, 20],
        ], 'text', 'text');
        $add('text/sizes', [
            ['setFill', '#ffffff'], ['setFont', '10px ConfSans'], ['fillText', 'Ag', 2, 12],
            ['setFont', '20px ConfSans'], ['fillText', 'Ag', 2, 30],
        ], 'text', 'text', 40, 40);
        $add('text/align-left', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['setAlign', 'left'],
            ['fillText', 'i', 20, 20],
        ], 'text', 'text');
        $add('text/align-center', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['setAlign', 'center'],
            ['fillText', 'i', 20, 20],
        ], 'text', 'text');
        $add('text/align-right', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['setAlign', 'right'],
            ['fillText', 'i', 20, 20],
        ], 'text', 'text');
        $add('text/align-start', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['setAlign', 'start'],
            ['fillText', 'i', 20, 20],
        ], 'text', 'text');
        $add('text/align-end', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['setAlign', 'end'],
            ['fillText', 'i', 20, 20],
        ], 'text', 'text');
        foreach (['top', 'hanging', 'middle', 'alphabetic', 'ideographic', 'bottom'] as $b) {
            $add('text/baseline-' . $b, [
                ['setFill', '#ff0000'], ['setFont', '16px ConfSans'], ['setBaseline', $b],
                ['fillText', 'Hg', 4, 16],
            ], 'text', 'text', 40, 32);
        }
        $add('text/rotated', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'],
            ['translate', 6, 24], ['rotate', -0.5], ['fillText', 'Ab', 0, 0],
        ], 'text', 'text');
        $add('text/scaled', [
            ['setFill', '#ffffff'], ['setFont', '12px ConfSans'],
            ['scale', 2, 2], ['fillText', 'Ab', 2, 12],
        ], 'text', 'text');
        $add('text/stroke', [
            ['setStroke', '#ffffff'], ['setLineWidth', 1], ['setFont', '16px ConfSans'],
            ['strokeText', 'Hi', 4, 20],
        ], 'text', 'text');
        $add('text/max-width', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'],
            ['fillText', 'WWWWWWWW', 2, 20, 20],
        ], 'text', 'text');
        $add('text/measure-short', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['measureRect', 'i', 0, 10],
        ], 'text', 'text');
        $add('text/measure-long', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['measureRect', 'Hello World', 0, 10],
        ], 'text', 'text');
        $add('text/measure-scaled', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'],
            ['scale', 2, 1], ['measureRect', 'Hello', 0, 10],
        ], 'text', 'text');
        $add('text/empty-string', [
            ['setFill', '#ffffff'], ['setFont', '16px ConfSans'], ['fillText', '', 4, 20],
        ], 'text', 'text');

        // ---- hit testing --------------------------------------------------
        $add('hit/inside-rect', [
            ['beginPath'], ['rect', 10, 10, 20, 12],
            ['setFill', '#00ff00'], ['ifIn', 15, 15, [30, 2]],
        ], 'hit');
        $add('hit/outside-rect', [
            ['beginPath'], ['rect', 10, 10, 20, 12],
            ['setFill', '#ff0000'], ['ifNotIn', 30, 26, [30, 2]],
        ], 'hit');
        $add('hit/edge-left', [
            ['beginPath'], ['rect', 10, 10, 20, 12],
            ['setFill', '#00ff00'], ['ifIn', 10, 15, [30, 2]],
        ], 'hit');
        $add('hit/edge-right-exclusive', [
            ['beginPath'], ['rect', 10, 10, 20, 12],
            ['setFill', '#ff0000'], ['ifNotIn', 30, 15, [30, 2]],
        ], 'hit');
        $add('hit/edge-bottom-exclusive', [
            ['beginPath'], ['rect', 10, 10, 20, 12],
            ['setFill', '#ff0000'], ['ifNotIn', 15, 22, [30, 2]],
        ], 'hit');
        $add('hit/donut-evenodd', [
            ['beginPath'], ['rect', 6, 6, 28, 20], ['rect', 14, 12, 12, 10],
            ['setFill', '#ff0000'], ['ifNotIn', 20, 16, [32, 2], 'evenodd'],
        ], 'hit');
        $add('hit/donut-nonzero', [
            ['beginPath'], ['rect', 6, 6, 28, 20], ['rect', 14, 12, 12, 10],
            ['setFill', '#00ff00'], ['ifIn', 20, 16, [32, 2]],
        ], 'hit');
        $add('hit/under-transform', [
            ['translate', 8, 6], ['beginPath'], ['rect', 0, 0, 12, 10],
            ['setFill', '#00ff00'], ['ifIn', 12, 10, [30, 2]],
        ], 'hit');
        $add('hit/in-stroke', [
            ['beginPath'], ['moveTo', 4, 16], ['lineTo', 36, 16],
            ['setStroke', '#00ff00'], ['setLineWidth', 6],
            ['setFill', '#00ff00'], ['ifInStroke', 20, 17, [30, 2]],
        ], 'hit');
        $add('hit/in-stroke-outside', [
            ['beginPath'], ['moveTo', 4, 16], ['lineTo', 36, 16],
            ['setStroke', '#00ff00'], ['setLineWidth', 4],
            ['setFill', '#ff0000'], ['ifIn', 20, 26, [30, 2]],
        ], 'hit');

        return $c;
    }
}
