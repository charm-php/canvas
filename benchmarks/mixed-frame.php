<?php
// A 640x480 frame: gradient fill, translucent ellipse, clip, a photo scaled
// and rotated, some text.  Library: run via `php benchmarks/run.php mixed [--jit]`.
namespace phasync\Canvas\Bench\Mixed;

use phasync\Canvas\Canvas;

/** A deterministic 200x150 "photo": smooth colour plus fixed-seed noise. */
function photo(): \GdImage
{
    static $photo = null;
    if ($photo === null) {
        $photo = imagecreatetruecolor(200, 150);
        mt_srand(7);
        for ($y = 0; $y < 150; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($photo, $x, $y, imagecolorallocate($photo,
                    (int) (127 + 100 * sin($x / 17)) & 255, (int) (127 + 100 * cos($y / 23)) & 255, ($x + $y + mt_rand(0, 30)) & 255));
            }
        }
    }
    return $photo;
}

function frame(int $k = 0): Canvas
{
    $photo = photo();
    $cv = new Canvas(640, 480);
    $c = $cv->getContext();
    $g = $c->createLinearGradient(0, 0, 640, 480);
    $g->addColorStop(0, '#1e3a8a');
    $g->addColorStop(0.5, '#f59e0b');
    $g->addColorStop(1, '#10b981');
    $c->fillStyle = $g;
    $c->fillRect(0, 0, 640, 480);
    $c->fillStyle = 'rgba(220,38,38,0.5)';
    $c->beginPath();
    $c->ellipse(160, 120, 120, 70, 0.3, 0, 2 * M_PI);
    $c->fill();
    $c->save();
    $c->beginPath();
    $c->arc(480, 300, 140, 0, 2 * M_PI);
    $c->clip();
    $c->drawImage($photo, 340, 160, 300, 225);
    $c->restore();
    $c->save();
    $c->translate(150, 340);
    $c->rotate(10 * M_PI / 180);
    $c->drawImage($photo, 0, 0, 200, 150);
    $c->restore();
    $c->fillStyle = '#ffffff';
    $c->font = '28px sans-serif';
    $c->fillText('Hello, canvas', 40, 60);
    $c->font = '16px sans-serif';
    $c->fillText('The quick brown fox jumps over the lazy dog', 40, 460);
    $cv->toPixels();
    return $cv;
}

/** Mean milliseconds per frame over $measure frames, after $warm unmeasured ones. */
function run(int $warm, int $measure): float
{
    photo();
    for ($k = 0; $k < $warm; $k++) {
        frame();
    }
    $t = hrtime(true);
    for ($k = 0; $k < $measure; $k++) {
        frame();
    }
    return (hrtime(true) - $t) / 1e6 / $measure;
}
