<?php declare(strict_types=1);

namespace Charm\Canvas\Tests;

use Charm\Canvas\Canvas;
use Charm\Canvas\ImagickSurface;
use Charm\Canvas\Surface;
use PHPUnit\Framework\TestCase;

/** The PHP and Imagick renderers must agree, within antialiasing noise. */
final class RendererTest extends TestCase
{
    private ?string $saved;

    protected function setUp(): void
    {
        $this->saved = Canvas::$renderer;
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick is not loaded');
        }
    }

    protected function tearDown(): void
    {
        Canvas::$renderer = $this->saved;
    }

    public function testOverrideAndDetection(): void
    {
        Canvas::$renderer = 'php';
        $this->assertSame('php', Canvas::rendererName());
        $this->assertInstanceOf(Surface::class, (new Canvas(4, 4))->surface());
        Canvas::$renderer = 'imagick';
        $this->assertInstanceOf(ImagickSurface::class, (new Canvas(4, 4))->surface());
        Canvas::$renderer = null;
        $this->assertSame('php', Canvas::rendererName(), 'the PHP renderer is the default even with ext-imagick loaded');
        $this->expectException(\InvalidArgumentException::class);
        Canvas::$renderer = 'gd';
        Canvas::rendererName();
    }

    /** @return array<string,array{0:callable}> */
    public static function scenes(): array
    {
        return [
            'filled rect' => [function ($c): void {
                $c->fillStyle = '#e63946';
                $c->fillRect(3.25, 4.5, 20, 12.75);
            }],
            'translucent ellipse' => [function ($c): void {
                $c->fillStyle = 'rgba(30, 144, 255, 0.5)';
                $c->beginPath();
                $c->ellipse(20, 16, 14, 9, 0.4, 0, 2 * M_PI);
                $c->fill();
            }],
            'clipped fill' => [function ($c): void {
                $c->beginPath();
                $c->arc(20, 16, 11, 0, 2 * M_PI);
                $c->clip();
                $c->fillStyle = '#2a9d8f';
                $c->fillRect(0, 0, 40, 16);
            }],
            'thin scaled stroke' => [function ($c): void {
                $c->scale(4, 2);
                $c->lineWidth = 0.4;
                $c->strokeStyle = '#111';
                $c->beginPath();
                $c->moveTo(1, 2);
                $c->lineTo(9, 12);
                $c->lineTo(3, 14);
                $c->stroke();
            }],
            'drawImage' => [function ($c): void {
                $src = new Canvas(4, 4);
                $s = $src->getContext();
                $s->fillStyle = '#f4a261';
                $s->fillRect(0, 0, 4, 4);
                $s->fillStyle = '#264653';
                $s->fillRect(0, 0, 2, 2);
                $c->imageSmoothingEnabled = false;
                $c->drawImage($src, 3, 3, 24, 20);
            }],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenes')]
    public function testRenderersAgree(callable $draw): void
    {
        $out = [];
        foreach (['php', 'imagick'] as $r) {
            Canvas::$renderer = $r;
            $cv = new Canvas(40, 32);
            $draw($cv->getContext());
            $out[$r] = $cv->toPixels()['rgba'];
        }
        $differ = 0;
        $painted = 0;
        for ($i = 0, $n = 40 * 32; $i < $n; $i++) {
            $a = substr($out['php'], $i * 4, 4);
            $b = substr($out['imagick'], $i * 4, 4);
            $painted += $a !== "\0\0\0\0" ? 1 : 0;
            if ($a === $b) {
                continue;
            }
            // Transparent pixels carry no colour; otherwise 24 of 255 per channel.
            if ($a[3] === "\0" && $b[3] === "\0") {
                continue;
            }
            for ($j = 0; $j < 4; $j++) {
                if (abs(ord($a[$j]) - ord($b[$j])) > 24) {
                    $differ++;
                    break;
                }
            }
        }
        $this->assertGreaterThan(20, $painted, 'the scene paints something');
        $this->assertLessThanOrEqual(max(2, (int) ($painted * 0.04)), $differ, "$differ pixels differ by more than 24/255");
    }
}
