<?php declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// CANVAS_RENDERER=php|imagick picks the renderer for the whole test run.
if (($renderer = getenv('CANVAS_RENDERER')) !== false && $renderer !== '') {
    \Charm\Canvas\Canvas::$renderer = $renderer;
}
