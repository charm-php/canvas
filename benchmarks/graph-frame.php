<?php
require $argv[1] . '/vendor/autoload.php';
use phasync\Canvas\Canvas;
$t = hrtime(true); $n = 20;
for ($k = 0; $k < $n; $k++) {
  $cv = new Canvas(416, 320); $c = $cv->getContext(); $c->scale(8, 16);
  $c->strokeStyle = '#64748b'; $c->lineWidth = 0.25;
  $c->beginPath(); $c->moveTo(6, 0.5); $c->lineTo(6, 19); $c->lineTo(51, 19); $c->stroke();
  $c->fillStyle = 'rgba(56,189,248,0.3)'; $c->beginPath(); $c->moveTo(6, 19);
  for ($i = 0; $i < 90; $i++) $c->lineTo(6 + $i * 0.5, 19 - 10 * (1 + sin($i / 7 + $k)) );
  $c->lineTo(50.5, 19); $c->closePath(); $c->fill();
  $c->strokeStyle = '#38bdf8'; $c->lineWidth = 0.4; $c->beginPath();
  for ($i = 0; $i < 90; $i++) { $f = $i ? 'lineTo' : 'moveTo'; $c->$f(6 + $i * 0.5, 19 - 8 * (1 + cos($i / 5 + $k))); }
  $c->stroke();
  $t1 = hrtime(true); $d = $cv->toDots(104, 80); $td = ($td ?? 0) + hrtime(true) - $t1;
}
printf("%.1f ms/frame total, toDots %.1f ms\n", (hrtime(true) - $t) / 1e6 / $n, $td / 1e6 / $n);
