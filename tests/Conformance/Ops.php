<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

/**
 * A single "op program" is the canonical description of one conformance case.
 *
 * The very same program is executed twice: once by PHP (this file) against the
 * library under test, and once by JavaScript (JS_INTERPRETER below) inside a
 * real Chrome canvas.  Keeping one program and two executors removes the
 * biggest risk in a differential test suite: the two sides quietly drawing
 * different pictures.
 *
 * Op shape:  [name, arg1, arg2, ...]
 */
final class Ops
{
    /**
     * Execute an op program against a phasync\Canvas\Canvas instance.
     *
     * @param array<int,array{0:string}> $ops
     * @return array{ok: bool, error: ?string}
     */
    public static function run(object $ctx, array $ops, Fixture $fx, float $measure = 0.0): array
    {
        // The context under test may not implement every property yet; a
        // missing capability is a conformance failure, never a crash.
        try {
            foreach ($ops as $op) {
                self::step($ctx, $op, $fx);
            }
        } catch (\Throwable $e) {
            if (\getenv('CONFORMANCE_TRACE')) {
                $trace = $e->getTraceAsString();
                fwrite(STDERR, $trace);
            }
            return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        return ['ok' => true, 'error' => null];
    }

    private static function step(object $ctx, array $op, Fixture $fx): void
    {
        $n = $op[0];
        $a = array_slice($op, 1);

        switch ($n) {
            // ---- styles ---------------------------------------------------
            case 'fill':
                if ($a === []) { $ctx->fill(); return; }
                $ctx->fill($a[0]);
                return;
            case 'stroke':
                $ctx->stroke();
                return;
            case 'setFill':
                $ctx->fillStyle = $a[0];
                return;
            case 'setStroke':
                $ctx->strokeStyle = $a[0];
                return;
            case 'setLineWidth':
                $ctx->lineWidth = $a[0];
                return;
            case 'setLineCap':
                $ctx->lineCap = $a[0];
                return;
            case 'setLineJoin':
                $ctx->lineJoin = $a[0];
                return;
            case 'setMiterLimit':
                $ctx->miterLimit = $a[0];
                return;
            case 'setAlpha':
                $ctx->globalAlpha = $a[0];
                return;
            case 'setGCO':
                $ctx->globalCompositeOperation = $a[0];
                return;
            case 'setFont':
                $ctx->font = $a[0];
                return;
            case 'setAlign':
                $ctx->textAlign = $a[0];
                return;
            case 'setBaseline':
                $ctx->textBaseline = $a[0];
                return;
            case 'setDash':
                $ctx->setLineDash($a[0]);
                if (isset($a[1])) { $ctx->lineDashOffset = $a[1]; }
                return;
            case 'setSmoothing':
                $ctx->imageSmoothingEnabled = (bool) $a[0];
                return;

            // ---- gradients ------------------------------------------------
            case 'gradFill':
            case 'gradStroke':
                $g = $n === 'gradFill'
                    ? self::gradient($ctx, $a[0], $a[1])
                    : self::gradient($ctx, $a[0], $a[1]);
                if ($n === 'gradFill') {
                    $ctx->fillStyle = $g;
                } else {
                    $ctx->strokeStyle = $g;
                }
                return;

            // ---- state / transforms --------------------------------------
            case 'save':
                $ctx->save();
                return;
            case 'restore':
                $ctx->restore();
                return;
            case 'translate':
                $ctx->translate($a[0], $a[1]);
                return;
            case 'rotate':
                $ctx->rotate($a[0]);
                return;
            case 'scale':
                $ctx->scale($a[0], $a[1]);
                return;
            case 'transform':
                $ctx->transform($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
                return;
            case 'setTransform':
                $ctx->setTransform($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
                return;
            case 'resetTransform':
                $ctx->resetTransform();
                return;

            // ---- paths ----------------------------------------------------
            case 'beginPath':
                $ctx->beginPath();
                return;
            case 'closePath':
                $ctx->closePath();
                return;
            case 'moveTo':
                $ctx->moveTo($a[0], $a[1]);
                return;
            case 'lineTo':
                $ctx->lineTo($a[0], $a[1]);
                return;
            case 'arc':
                $ctx->arc($a[0], $a[1], $a[2], $a[3], $a[4], (bool) ($a[5] ?? false));
                return;
            case 'arcTo':
                $ctx->arcTo($a[0], $a[1], $a[2], $a[3], $a[4]);
                return;
            case 'ellipse':
                $ctx->ellipse($a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], (bool) ($a[7] ?? false));
                return;
            case 'cubicCurveTo':
            case 'bezierCurveTo':
                $ctx->bezierCurveTo($a[0], $a[1], $a[2], $a[3], $a[4], $a[5]);
                return;
            case 'quadraticCurveTo':
            case 'quadCurveTo':
                $ctx->quadraticCurveTo($a[0], $a[1], $a[2], $a[3]);
                return;
            case 'rect':
                $ctx->rect($a[0], $a[1], $a[2], $a[3]);
                return;
            case 'roundRect':
                $ctx->roundRect($a[0], $a[1], $a[2], $a[3], $a[4]);
                return;

            // ---- painting -------------------------------------------------
            case 'fillRect':
                $ctx->fillRect($a[0], $a[1], $a[2], $a[3]);
                return;
            case 'strokeRect':
                $ctx->strokeRect($a[0], $a[1], $a[2], $a[3]);
                return;
            case 'clearRect':
                $ctx->clearRect($a[0], $a[1], $a[2], $a[3]);
                return;
            case 'clip':
                if ($a === []) { $ctx->clip(); return; }
                $ctx->clip($a[0]);
                return;

            // ---- text -----------------------------------------------------
            case 'fillText':
                $ctx->fillText($a[0], $a[1], $a[2], $a[3] ?? null);
                return;
            case 'strokeText':
                $ctx->strokeText($a[0], $a[1], $a[2], $a[3] ?? null);
                return;
            case 'measureRect':
                // Paints a 1px bar whose width is measureText()->width, which
                // turns text metrics into a comparable geometric measurement.
                $w = $ctx->measureText($a[0])['width'];
                $ctx->fillRect($a[1], $a[2], (float) round($w), 1.0);
                return;

            // ---- images ---------------------------------------------------
            case 'drawImage':
                $src = $a[0] === 'tile' ? $fx->tile() : $fx->tileAlpha();
                $ctx->drawImage($src, ...array_slice($a, 1));
                return;
            case 'copyRect':
                // getImageData + putImageData round trip through the API.
                $d = $ctx->getImageData($a[0], $a[1], $a[2], $a[3]);
                $ctx->putImageData($d, $a[4], $a[5]);
                return;
            case 'copyRectShift':
                // Round trip that must NOT be affected by the CTM.
                $d = $ctx->getImageData($a[0], $a[1], $a[2], $a[3]);
                $ctx->save();
                $ctx->setTransform(1, 0, 0, 1, 0, 0);
                $ctx->putImageData($d, $a[4], $a[5]);
                $ctx->restore();
                return;

            // ---- hit testing ----------------------------------------------
            case 'ifIn':
                if ($ctx->isPointInPath($a[0], $a[1], $a[3] ?? 'nonzero')) {
                    $ctx->fillRect($a[2][0], $a[2][1], 4, 4);
                }
                return;
            case 'ifNotIn':
                if (!$ctx->isPointInPath($a[0], $a[1], $a[3] ?? 'nonzero')) {
                    $ctx->fillRect($a[2][0], $a[2][1], 4, 4);
                }
                return;
            case 'ifInStroke':
                if ($ctx->isPointInStroke($a[0], $a[1])) {
                    $ctx->fillRect($a[2][0], $a[2][1], 4, 4);
                }
                return;

            default:
                throw new \RuntimeException("unknown op: $n");
        }
    }

    private static function gradient(object $ctx, string $type, array $spec): object
    {
        if ($type === 'linear') {
            $g = $ctx->createLinearGradient($spec[0], $spec[1], $spec[2], $spec[3]);
        } else {
            $g = $ctx->createRadialGradient($spec[0], $spec[1], $spec[2], $spec[3], $spec[4], $spec[5]);
        }
        foreach ($spec[count($spec) - 1] as [$offset, $color]) {
            $g->addColorStop($offset, $color);
        }
        return $g;
    }

    /**
     * JavaScript mirror of the interpreter above.  Injected into the harness page.
     *
     * `sources` maps 'tile' / 'tileAlpha' to loaded HTMLImageElement.
     */
    public const JS_INTERPRETER = <<<'JS'
function phStep(x, op, sources, fontMap) {
  const n = op[0], a = op.slice(1);
  switch (n) {
    case 'fill':       if (a.length === 0) x.fill(); else x.fill(a[0]); return;
    case 'stroke':     x.stroke(); return;
    case 'setFill':    x.fillStyle = a[0]; return;
    case 'setStroke':  x.strokeStyle = a[0]; return;
    case 'setLineWidth': x.lineWidth = a[0]; return;
    case 'setLineCap': x.lineCap = a[0]; return;
    case 'setLineJoin': x.lineJoin = a[0]; return;
    case 'setMiterLimit': x.miterLimit = a[0]; return;
    case 'setAlpha':   x.globalAlpha = a[0]; return;
    case 'setGCO':     x.globalCompositeOperation = a[0]; return;
    case 'setFont':    x.font = a[0]; return;
    case 'setAlign':   x.textAlign = a[0]; return;
    case 'setBaseline': x.textBaseline = a[0]; return;
    case 'setDash':    x.setLineDash(a[0]); if (a.length > 1) x.lineDashOffset = a[1]; return;
    case 'setSmoothing': x.imageSmoothingEnabled = !!a[0]; return;
    case 'gradFill':
    case 'gradStroke': {
      const t = a[0], s = a[1];
      let g;
      if (t === 'linear') g = x.createLinearGradient(s[0], s[1], s[2], s[3]);
      else g = x.createRadialGradient(s[0], s[1], s[2], s[3], s[4], s[5]);
      for (const st of s[s.length - 1]) g.addColorStop(st[0], st[1]);
      if (n === 'gradFill') x.fillStyle = g; else x.strokeStyle = g;
      return;
    }
    case 'save':       x.save(); return;
    case 'restore':    x.restore(); return;
    case 'translate':  x.translate(a[0], a[1]); return;
    case 'rotate':     x.rotate(a[0]); return;
    case 'scale':      x.scale(a[0], a[1]); return;
    case 'transform':  x.transform(a[0], a[1], a[2], a[3], a[4], a[5]); return;
    case 'setTransform': x.setTransform(a[0], a[1], a[2], a[3], a[4], a[5]); return;
    case 'resetTransform': x.resetTransform(); return;
    case 'beginPath':  x.beginPath(); return;
    case 'closePath':  x.closePath(); return;
    case 'moveTo':     x.moveTo(a[0], a[1]); return;
    case 'lineTo':     x.lineTo(a[0], a[1]); return;
    case 'arc':        x.arc(a[0], a[1], a[2], a[3], a[4], !!a[5]); return;
    case 'arcTo':      x.arcTo(a[0], a[1], a[2], a[3], a[4]); return;
    case 'ellipse':    x.ellipse(a[0], a[1], a[2], a[3], a[4], a[5], a[6], !!a[7]); return;
    case 'cubicCurveTo':
    case 'bezierCurveTo': x.bezierCurveTo(a[0], a[1], a[2], a[3], a[4], a[5]); return;
    case 'quadraticCurveTo':
    case 'quadCurveTo': x.quadraticCurveTo(a[0], a[1], a[2], a[3]); return;
    case 'rect':       x.rect(a[0], a[1], a[2], a[3]); return;
    case 'roundRect':  x.roundRect(a[0], a[1], a[2], a[3], a[4]); return;
    case 'fillRect':   x.fillRect(a[0], a[1], a[2], a[3]); return;
    case 'strokeRect': x.strokeRect(a[0], a[1], a[2], a[3]); return;
    case 'clearRect':  x.clearRect(a[0], a[1], a[2], a[3]); return;
    case 'clip':       if (a.length === 0) x.clip(); else x.clip(a[0]); return;
    case 'fillText':   x.fillText(a[0], a[1], a[2], a.length > 3 && a[3] !== null ? a[3] : undefined); return;
    case 'strokeText': x.strokeText(a[0], a[1], a[2], a.length > 3 && a[3] !== null ? a[3] : undefined); return;
    case 'measureRect': {
      const w = x.measureText(a[0]).width;
      x.fillRect(a[1], a[2], Math.round(w), 1);
      return;
    }
    case 'drawImage':  x.drawImage(sources[a[0]] || sources['tile'], ...a.slice(1)); return;
    case 'copyRect': {
      const d = x.getImageData(a[0], a[1], a[2], a[3]);
      x.putImageData(d, a[4], a[5]);
      return;
    }
    case 'copyRectShift': {
      const d = x.getImageData(a[0], a[1], a[2], a[3]);
      x.save(); x.setTransform(1, 0, 0, 1, 0, 0);
      x.putImageData(d, a[4], a[5]);
      x.restore();
      return;
    }
    case 'ifIn':
      if (x.isPointInPath(a[0], a[1], a[3] || 'nonzero')) x.fillRect(a[2][0], a[2][1], 4, 4);
      return;
    case 'ifNotIn':
      if (!x.isPointInPath(a[0], a[1], a[3] || 'nonzero')) x.fillRect(a[2][0], a[2][1], 4, 4);
      return;
    case 'ifInStroke':
      if (x.isPointInStroke(a[0], a[1])) x.fillRect(a[2][0], a[2][1], 4, 4);
      return;
    default: throw new Error('unknown op: ' + n);
  }
}
JS;
}
