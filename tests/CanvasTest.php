<?php declare(strict_types=1);

namespace Charm\Canvas\Tests;

use Charm\Canvas\Canvas;
use Charm\Canvas\Context;
use Charm\Canvas\Surface;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour that can be decided without a browser: pixels, state, compositing
 * arithmetic and the file formats.
 */
final class CanvasTest extends TestCase
{
    private function canvas(int $w = 16, int $h = 16): array
    {
        $c = new Canvas($w, $h);
        $ctx = $c->getContext('2d');
        $this->assertInstanceOf(Context::class, $ctx);
        $ctx->reset();
        return [$c, $ctx];
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function px(Canvas $c, int $x, int $y): array
    {
        $rgba = $c->toPixels()['rgba'];
        $o = ($y * $c->getWidth() + $x) * 4;
        return [\ord($rgba[$o]), \ord($rgba[$o + 1]), \ord($rgba[$o + 2]), \ord($rgba[$o + 3])];
    }

    public function testContextType(): void
    {
        $c = new Canvas(4, 4);
        $this->assertInstanceOf(Context::class, $c->getContext('2d'));
        $this->assertInstanceOf(Context::class, $c->getContext());
        $this->assertNull($c->getContext('webgl'));
        $this->assertSame($c->getContext('2d'), $c->getContext('2d'), 'one context per canvas');
    }

    public function testFillRectPaintsExactPixels(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ff0000';
        $x->fillRect(2, 3, 5, 4);
        $this->assertSame([255, 0, 0, 255], $this->px($c, 2, 3));
        $this->assertSame([255, 0, 0, 255], $this->px($c, 6, 6));
        $this->assertSame([0, 0, 0, 0], $this->px($c, 7, 3), 'the rectangle is half-open');
        $this->assertSame([0, 0, 0, 0], $this->px($c, 2, 7));
    }

    public function testFillRectBlendsUnderGlobalAlpha(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#0000ff';
        $x->fillRect(0, 0, 8, 8);
        $x->globalAlpha = 0.5;
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 8, 8);
        [$r, $g, $b, $a] = $this->px($c, 3, 3);
        $this->assertSame(255, $a);
        $this->assertEqualsWithDelta(128, $r, 2);
        $this->assertEqualsWithDelta(0, $g, 2);
        $this->assertEqualsWithDelta(127, $b, 2);
    }

    public function testFractionalRectangleIsPartiallyCovered(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->fillRect(0.0, 0.0, 0.5, 1.0);
        $this->assertEqualsWithDelta(128, $this->px($c, 0, 0)[3], 2);
        $x->reset();
        $x->fillStyle = '#ffffff';
        $x->fillRect(0.0, 0.0, 1.0, 1.0);
        $this->assertSame(255, $this->px($c, 0, 0)[3], 'a whole pixel is fully covered');
    }

    public function testClearRectErasesAndIgnoresStyle(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 10, 10);
        $x->globalAlpha = 0.2;
        $x->clearRect(2, 2, 4, 4);
        $this->assertSame([0, 0, 0, 0], $this->px($c, 2, 2));
        $this->assertSame([0, 0, 0, 0], $this->px($c, 5, 5));
        $this->assertSame([255, 0, 0, 255], $this->px($c, 6, 2), 'clearRect covers [x, x+w)');
        $this->assertSame([255, 0, 0, 255], $this->px($c, 1, 1));
    }

    public function testClearRectIsRoundedToWholePixels(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 8, 8);
        $x->clearRect(2.25, 0.0, 4.0, 8.0);
        // 2.25 rounds to 2 and 6.25 to 6: pixels 2..5 are cleared, and no pixel
        // is left half erased.
        $this->assertSame(0, $this->px($c, 2, 4)[3]);
        $this->assertSame(0, $this->px($c, 5, 4)[3]);
        $this->assertSame(255, $this->px($c, 1, 4)[3]);
        $this->assertSame(255, $this->px($c, 6, 4)[3]);
    }

    public function testClipLimitsPainting(): void
    {
        [$c, $x] = $this->canvas();
        $x->beginPath();
        $x->rect(4, 4, 6, 6);
        $x->clip();
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 16, 16);
        $this->assertSame([255, 0, 0, 255], $this->px($c, 4, 4));
        $this->assertSame([255, 0, 0, 255], $this->px($c, 9, 9));
        $this->assertSame([0, 0, 0, 0], $this->px($c, 3, 3));
        $this->assertSame([0, 0, 0, 0], $this->px($c, 10, 10));
    }

    public function testSaveRestorePopsClipAndState(): void
    {
        [$c, $x] = $this->canvas();
        $x->save();
        $x->beginPath();
        $x->rect(0, 0, 4, 4);
        $x->clip();
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 16, 16);
        $x->restore();
        $this->assertSame('#000000', $x->fillStyle, 'restore returns the style');
        $x->fillStyle = '#00ff00';
        $x->fillRect(0, 0, 16, 16);
        $this->assertSame([0, 255, 0, 255], $this->px($c, 12, 12), 'the clip is gone');
        $this->assertSame([0, 255, 0, 255], $this->px($c, 1, 1));
    }

    public function testNestedClipsIntersect(): void
    {
        [$c, $x] = $this->canvas();
        $x->beginPath();
        $x->rect(0, 0, 10, 10);
        $x->clip();
        $x->save();
        $x->beginPath();
        $x->rect(5, 5, 10, 10);
        $x->clip();
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 16, 16);
        $this->assertSame(255, $this->px($c, 6, 6)[3]);
        $this->assertSame(0, $this->px($c, 1, 1)[3], 'the inner clip still limits the outer');
        $this->assertSame(0, $this->px($c, 12, 12)[3]);
    }

    public function testFillRuleDistinguishesWinding(): void
    {
        $draw = function (string $rule): array {
            [$c, $x] = $this->canvas();
            $x->fillStyle = '#ffffff';
            $x->fillRule = $rule;
            $x->beginPath();
            $x->rect(2, 2, 8, 8);
            $x->rect(4, 4, 8, 8);
            $x->fill();
            return [$this->px($c, 5, 5)[3], $this->px($c, 2, 2)[3]];
        };
        $this->assertSame([255, 255], $draw('nonzero'), 'same winding fills through');
        $this->assertSame([0, 255], $draw('evenodd'), 'opposite windings cancel');
    }

    public function testTransformsComposeInCanvasOrder(): void
    {
        [$c, $x] = $this->canvas();
        $x->translate(10, 0);
        $x->scale(2, 1);
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 2, 2);
        $this->assertSame(255, $this->px($c, 10, 0)[3]);
        $this->assertSame(255, $this->px($c, 13, 1)[3]);
        $this->assertSame(0, $this->px($c, 14, 0)[3], 'a scale of 2 over 2 user units is 4 device pixels');
        $t = $x->getTransform();
        $this->assertSame(2.0, $t['a']);
        $this->assertSame(10.0, $t['e']);
    }

    public function testRotatePlacesGeometry(): void
    {
        [$c, $x] = $this->canvas();
        $x->translate(8, 8);
        $x->rotate(M_PI / 2);
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 6, 2);
        // The +x axis now points down.
        // The rectangle now occupies device x in [6, 8) and y in [8, 14).
        $this->assertSame(255, $this->px($c, 7, 9)[3]);
        $this->assertSame(255, $this->px($c, 7, 13)[3]);
        $this->assertSame(0, $this->px($c, 8, 9)[3], 'the far edge stays half-open');
        $this->assertSame(0, $this->px($c, 10, 8)[3]);
        $x->resetTransform();
        $this->assertSame(['a' => 1.0, 'b' => 0.0, 'c' => 0.0, 'd' => 1.0, 'e' => 0.0, 'f' => 0.0], $x->getTransform());
    }

    public function testSetTransformReplacesRatherThanMultiplies(): void
    {
        [$c, $x] = $this->canvas();
        $x->translate(4, 4);
        $x->setTransform(1, 0, 0, 1, 1, 1);
        $t = $x->getTransform();
        $this->assertSame(1.0, $t['e']);
        $x->transform(2, 0, 0, 2, 0, 0);
        $t = $x->getTransform();
        $this->assertSame(2.0, $t['a']);
        $this->assertSame(1.0, $t['e'], 'transform() multiplies onto the current matrix');
    }

    public function testPorterDuffModes(): void
    {
        $run = function (string $mode): array {
            [$c, $x] = $this->canvas();
            $x->fillStyle = 'rgba(255, 0, 0, 0.5)';
            $x->fillRect(0, 0, 4, 4);
            $x->globalCompositeOperation = $mode;
            $x->fillStyle = 'rgba(0, 0, 255, 0.5)';
            $x->fillRect(0, 0, 4, 4);
            return $this->px($c, 1, 1);
        };
        // 0.5 over 0.5 gives 0.75 alpha, and the colour is the premultiplied
        // average of the two halves.
        $srcOver = $run('source-over');
        $this->assertEqualsWithDelta(192, $srcOver[3], 2);
        $this->assertEqualsWithDelta(85, $srcOver[0], 3);
        $this->assertEqualsWithDelta(170, $srcOver[2], 3);

        $copy = $run('copy');
        $this->assertSame([0, 0, 255], \array_slice($copy, 0, 3));
        $this->assertEqualsWithDelta(128, $copy[3], 2, 'alpha has 7 bits of precision');

        // source-in keeps the source colour at the intersection of alphas.
        $srcIn = $run('source-in');
        $this->assertSame(0, $srcIn[0]);
        $this->assertEqualsWithDelta(64, $srcIn[3], 2);

        $dstOut = $run('destination-out');
        $this->assertEqualsWithDelta(64, $dstOut[3], 2, 'the destination loses the source fraction');
        $this->assertSame(255, $dstOut[0]);

        $lighter = $run('lighter');
        $this->assertEqualsWithDelta(128, $lighter[0], 2);
        $this->assertEqualsWithDelta(128, $lighter[2], 2);
        $this->assertSame(255, $lighter[3], 'lighter saturates alpha to opaque');

        // multiply blends the colours but composites as source-over, so the
        // destination still contributes where the source is translucent.
        $multiply = $run('multiply');
        $this->assertEqualsWithDelta(85, $multiply[0], 2);
        $this->assertEqualsWithDelta(85, $multiply[2], 2);
        $this->assertEqualsWithDelta(191, $multiply[3], 2);
    }

    public function testLinearGradientInterpolates(): void
    {
        [$c, $x] = $this->canvas();
        $g = $x->createLinearGradient(0, 0, 10, 0);
        $g->addColorStop(0, '#000000');
        $g->addColorStop(1, '#ffffff');
        $x->fillStyle = $g;
        $x->fillRect(0, 4, 16, 1);
        // Sampling happens at pixel centres: x=0.5 is 5% along the gradient.
        $this->assertEqualsWithDelta(13, $this->px($c, 0, 4)[0], 4);
        $this->assertEqualsWithDelta(140, $this->px($c, 5, 4)[0], 6);
        $this->assertEqualsWithDelta(242, $this->px($c, 9, 4)[0], 8);
        $this->assertSame(255, $this->px($c, 15, 4)[0], 'the last stop extends past the gradient');
    }

    public function testRadialGradientConfinesColour(): void
    {
        [$c, $x] = $this->canvas();
        $g = $x->createRadialGradient(8, 8, 0, 8, 8, 5);
        $g->addColorStop(0, '#ff0000');
        $g->addColorStop(1, '#0000ff');
        $x->fillStyle = $g;
        $x->fillRect(0, 0, 16, 16);
        // The pixel centre at (8.5, 8.5) sits 0.71 of the way out.
        $this->assertEqualsWithDelta(219, $this->px($c, 8, 8)[0], 6);
        $this->assertEqualsWithDelta(36, $this->px($c, 8, 8)[2], 6);
        $this->assertSame(0, $this->px($c, 8, 8)[1]);
        $this->assertGreaterThan(200, $this->px($c, 15, 8)[2], 'outside the outer circle the end colour holds');
    }

    public function testPatternRepeats(): void
    {
        $tile = new Canvas(2, 2);
        $t = $tile->getContext('2d');
        $t->fillStyle = '#ff0000';
        $t->fillRect(0, 0, 1, 2);
        $t->fillStyle = '#0000ff';
        $t->fillRect(1, 0, 1, 2);

        [$c, $x] = $this->canvas();
        $pattern = $x->createPattern($tile, 'repeat');
        $this->assertNotNull($pattern);
        $x->fillStyle = $pattern;
        $x->fillRect(0, 0, 16, 16);
        $this->assertSame([255, 0, 0, 255], $this->px($c, 0, 0));
        $this->assertSame([0, 0, 255, 255], $this->px($c, 1, 0));
        $this->assertSame([255, 0, 0, 255], $this->px($c, 14, 7), 'the tile repeats in both axes');

        $this->assertNull($x->createPattern($tile, 'nope'), 'an invalid repetition is rejected');
    }

    public function testGdRoundTripIsPixelIdentical(): void
    {
        $gd = \imagecreatetruecolor(5, 4);
        \imagealphablending($gd, false);
        \imagesavealpha($gd, true);
        mt_srand(3);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 5; $x++) {
                \imagesetpixel($gd, $x, $y, (mt_rand(0, 127) << 24) | mt_rand(0, 0xFFFFFF));
            }
        }
        $back = Canvas::fromGd($gd)->toGd();
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $this->assertSame(\imagecolorat($gd, $x, $y), \imagecolorat($back, $x, $y));
            }
        }
    }

    public function testImageDataIsRgba8(): void
    {
        [$c, $x] = $this->canvas(4, 4);
        $x->fillStyle = '#336699';
        $x->fillRect(0, 0, 2, 4);
        $d = $x->getImageData(0, 0, 4, 4)['data'];
        $this->assertSame([0x33, 0x66, 0x99, 255], [\ord($d[0]), \ord($d[1]), \ord($d[2]), \ord($d[3])]);
        $this->assertSame(0, \ord($d[3 * 4 + 3]), 'untouched pixels read back as alpha 0');
        $x->clearRect(0, 0, 2, 4);
        $this->assertSame(0, \ord($x->getImageData(0, 0, 1, 1)['data'][3]));
    }

    public function testTranslucentFillBlendsWithinTolerance(): void
    {
        [$c, $x] = $this->canvas(4, 4);
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 4, 4);
        $x->fillStyle = 'rgba(0, 0, 0, 0.5)';
        $x->fillRect(0, 0, 4, 4);
        [$r, $g, $b, $a] = $this->px($c, 1, 1);
        $this->assertEqualsWithDelta(128, $r, 2);
        $this->assertEqualsWithDelta(128, $g, 2);
        $this->assertEqualsWithDelta(128, $b, 2);
        $this->assertSame(255, $a);
    }

    public function testPngRoundTrip(): void
    {
        [$c, $x] = $this->canvas(8, 8);
        $x->fillStyle = 'rgba(10, 20, 30, 0.5)';
        $x->fillRect(0, 0, 8, 8);
        $png = $c->toPng();
        $this->assertStringStartsWith("\x89PNG", $png);
        $back = Canvas::fromBytes($png);
        $this->assertSame(8, $back->getWidth());
        $p = $back->getContext('2d')->getImageData(0, 0, 8, 8)['data'];
        $this->assertSame(10, \ord($p[0]));
        $this->assertSame(20, \ord($p[1]));
        $this->assertSame(30, \ord($p[2]));
        $this->assertEqualsWithDelta(128, \ord($p[3]), 2, 'alpha survives the codec');
    }

    public function testDataUrlAndJpeg(): void
    {
        [$c, $x] = $this->canvas(8, 8);
        $x->fillStyle = '#123456';
        $x->fillRect(0, 0, 8, 8);
        $this->assertStringStartsWith('data:image/png;base64,', $c->toDataURL());
        $jpeg = $c->toJpeg(85);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $jpeg);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $c->toDataURL('image/jpeg'));
    }

    public function testResizeAndReset(): void
    {
        [$c, $x] = $this->canvas(8, 8);
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 8, 8);
        $c->width = 12;
        $this->assertSame(12, $c->getWidth());
        $this->assertSame(0, $this->px($c, 3, 3)[3], 'setting the width clears the canvas');
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 4, 4);
        $x->reset();
        $this->assertSame(0, $this->px($c, 1, 1)[3]);
        $this->assertSame('#000000', $x->fillStyle, 'reset returns the default style');
    }

    public function testInvalidValuesAreIgnored(): void
    {
        [, $x] = $this->canvas();
        $x->fillStyle = '#ff0000';
        $x->fillStyle = 'not-a-colour';
        $this->assertSame('#ff0000', $x->fillStyle);
        $x->lineWidth = 4;
        $x->lineWidth = -1;
        $x->lineWidth = 0;
        $x->lineWidth = NAN;
        $this->assertSame(4.0, (float) $x->lineWidth);
        $x->globalAlpha = 0.5;
        $x->globalAlpha = 2.0;
        $x->globalAlpha = -1.0;
        $this->assertSame(0.5, $x->globalAlpha);
        $x->lineCap = 'triangle';
        $this->assertSame('butt', $x->lineCap);
        $x->globalCompositeOperation = 'nope';
        $this->assertSame('source-over', $x->globalCompositeOperation);
    }

    private function opaqueCount(Canvas $c): int
    {
        $n = 0;
        $rgba = $c->toPixels()['rgba'];
        for ($i = 3, $len = \strlen($rgba); $i < $len; $i += 4) {
            if (\ord($rgba[$i]) === 255) {
                $n++;
            }
        }
        return $n;
    }

    public function testThinStrokeUnderNonUniformScaleKeepsItsWidth(): void
    {
        [$c, $x] = $this->canvas(80, 64);
        $x->scale(8, 16);
        $x->strokeStyle = '#000';
        $x->lineWidth = 0.25;
        $x->beginPath();
        $x->moveTo(1, 0.5);
        $x->lineTo(1, 3.5);
        $x->lineTo(9, 3.5);
        $x->stroke();
        $this->assertGreaterThan(250, $this->opaqueCount($c), 'the width is scaled with the transform');
        $dots = $c->toDots(20, 16);
        $this->assertGreaterThanOrEqual(28, \substr_count($dots['on'], "\1"));
    }

    public function testClearRectUnderScaleClearsDevicePixels(): void
    {
        [$c, $x] = $this->canvas(40, 40);
        $x->fillStyle = '#f00';
        $x->fillRect(0, 0, 40, 40);
        $x->scale(10, 10);
        $x->clearRect(1, 1, 2, 2);
        $this->assertSame(127, (\imagecolorat($c->toGd(), 20, 20) >> 24) & 0x7F, 'cleared to transparent');
        $this->assertSame(255, $this->px($c, 5, 5)[3]);
    }

    public function testDrawImageUnderScaleFromEverySource(): void
    {
        $gd = \imagecreatetruecolor(4, 4);
        \imagefill($gd, 0, 0, (int) \imagecolorallocate($gd, 255, 0, 0));
        \ob_start();
        \imagepng($gd);
        $png = (string) \ob_get_clean();
        $file = \tempnam(\sys_get_temp_dir(), 'cv') . '.png';
        \file_put_contents($file, $png);
        try {
            foreach ([$gd, $png, $file] as $source) {
                [$c, $x] = $this->canvas(40, 40);
                $x->scale(10, 10);
                $x->drawImage($source, 1, 1, 2, 2);
                $this->assertSame(400, $this->opaqueCount($c), 'a 2x2 unit image is 20x20 pixels');
            }
        } finally {
            @\unlink($file);
        }
    }

    public function testToDotsAtTheCanvasOwnSize(): void
    {
        [$c, $x] = $this->canvas(8, 4);
        $x->fillStyle = '#0f0';
        $x->fillRect(0, 0, 3, 4);
        $dots = $c->toDots(8, 4);
        $this->assertSame(8 * 4, \strlen($dots['on']));
        $this->assertSame(str_repeat("\1\1\1\0\0\0\0\0", 4), $dots['on']);
        $this->assertSame("\0\xFF\0", \substr($dots['rgb'], 0, 3));
    }

    public function testToDotsMapsFractionalRectToWholeDots(): void
    {
        [$c, $x] = $this->canvas(64, 16);
        $x->scale(8, 16);
        $x->fillStyle = '#fff';
        $x->fillRect(1.5, 0, 6, 1);
        $dots = $c->toDots(16, 4);
        $this->assertSame(\str_repeat("\0", 3) . \str_repeat("\1", 12) . "\0", \substr($dots['on'], 0, 16));
    }

    public function testToDotsAtOwnSizeMatchesBlockPath(): void
    {
        // One pixel per dot: a pixel at least half covered is on, in its own colour.
        $canvas = new Canvas(8, 4);
        $ctx = $canvas->getContext();
        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0.5, 0, 3, 4);          // x 0.5..3.5: pixel 0 half covered, 1-2 full, 3 half
        $ctx->fillStyle = 'rgba(0,0,255,0.25)';
        $ctx->fillRect(5, 0, 3, 4);            // 25% alpha: off
        $d = $canvas->toDots(8, 4);
        $this->assertSame("\1\1\1\1\0\0\0\0", substr($d['on'], 0, 8));
        $this->assertSame("\xff\x00\x00", substr($d['rgb'], 3, 3));
        // The same drawing at 2x, downsampled through the block path, gives the same dots.
        $big = new Canvas(16, 8);
        $c2 = $big->getContext();
        $c2->scale(2, 2);
        $c2->fillStyle = '#ff0000';
        $c2->fillRect(0.5, 0, 3, 4);
        $c2->fillStyle = 'rgba(0,0,255,0.25)';
        $c2->fillRect(5, 0, 3, 4);
        $this->assertSame($d['on'], $big->toDots(8, 4)['on']);
    }

    public function testReadRectAndWriteSurfaceCopyRowsAtArraySpeed(): void
    {
        // (both used array_splice() on the whole pixel array per row: a 400x440 screenful took a second)
        $c = new Canvas(400, 440);
        $s = $c->surface();
        $x = new Context($s);
        $x->fillStyle = '#4f86ff';
        $x->fillRect(10, 10, 300, 300);
        $t = microtime(true);
        $rect = $s->readRect(0, 0, 400, 440);
        $read = microtime(true) - $t;
        $this->assertSame($s->clonePixels(), $rect->clonePixels());
        $x->fillRect(0, 0, 400, 440);
        $t = microtime(true);
        $x->putImageData($rect, 0, 0);
        $write = microtime(true) - $t;
        $this->assertSame($rect->clonePixels(), $s->clonePixels());
        $this->assertLessThan(0.15, $read, "readRect took {$read}s");
        $this->assertLessThan(0.15, $write, "writeSurface took {$write}s");
        // a rectangle partly outside: what is outside is transparent, the rest in place
        $part = $s->readRect(-2, -1, 5, 3);
        $this->assertSame(5, $part->width);
        $this->assertSame($s->getPixel(0, 0), $part->getPixel(2, 1));
        $this->assertSame($s->getPixel(2, 1), $part->getPixel(4, 2));
        $blank = (new Surface(1, 1))->getPixel(0, 0);
        $this->assertSame($blank, $part->getPixel(0, 0));
        $this->assertSame($blank, $part->getPixel(4, 0));
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 400, 440);
        $s->writeSurface($part, 398, 439);
        $this->assertSame($part->getPixel(0, 0), $s->getPixel(398, 439));
        $this->assertSame($part->getPixel(1, 0), $s->getPixel(399, 439));
        $this->assertSame($s->getPixel(0, 0), $s->getPixel(0, 0));
    }
}
