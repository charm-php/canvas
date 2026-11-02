<?php
// A 416x320 line graph (stroked axes, filled area, stroked curve) reduced to
// 104x80 braille dots.  Library: run via `php benchmarks/run.php graph [--jit]`.
namespace phasync\Canvas\Bench\Graph;

use phasync\Canvas\Canvas;

function frame(int $k): Canvas
{
    $cv = new Canvas(416, 320);
    $c = $cv->getContext();
    $c->scale(8, 16);
    $c->strokeStyle = '#64748b';
    $c->lineWidth = 0.25;
    $c->beginPath();
    $c->moveTo(6, 0.5);
    $c->lineTo(6, 19);
    $c->lineTo(51, 19);
    $c->stroke();
    $c->fillStyle = 'rgba(56,189,248,0.3)';
    $c->beginPath();
    $c->moveTo(6, 19);
    for ($i = 0; $i < 90; $i++) {
        $c->lineTo(6 + $i * 0.5, 19 - 10 * (1 + sin($i / 7 + $k)));
    }
    $c->lineTo(50.5, 19);
    $c->closePath();
    $c->fill();
    $c->strokeStyle = '#38bdf8';
    $c->lineWidth = 0.4;
    $c->beginPath();
    for ($i = 0; $i < 90; $i++) {
        $f = $i ? 'lineTo' : 'moveTo';
        $c->$f(6 + $i * 0.5, 19 - 8 * (1 + cos($i / 5 + $k)));
    }
    $c->stroke();
    $cv->toDots(104, 80);
    return $cv;
}

/** Mean milliseconds per frame over $measure frames, after $warm unmeasured ones. */
function run(int $warm, int $measure): float
{
    for ($k = 0; $k < $warm; $k++) {
        frame($k);
    }
    $t = hrtime(true);
    for ($k = 0; $k < $measure; $k++) {
        frame($k);
    }
    return (hrtime(true) - $t) / 1e6 / $measure;
}
