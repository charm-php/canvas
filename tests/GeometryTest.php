<?php declare(strict_types=1);

namespace Charm\Canvas\Tests;

use Charm\Canvas\Canvas;
use PHPUnit\Framework\TestCase;

/**
 * Geometry: paths, stroking, clipping, text and images measured in pixels.
 */
final class GeometryTest extends TestCase
{
    private function canvas(int $w = 24, int $h = 24): array
    {
        $c = new Canvas($w, $h);
        $ctx = $c->getContext('2d');
        $this->assertNotNull($ctx);
        $ctx->reset();
        return [$c, $ctx];
    }

    private function alpha(Canvas $c, int $x, int $y): int
    {
        $rgba = $c->toPixels()['rgba'];
        return \ord($rgba[($y * $c->getWidth() + $x) * 4 + 3]);
    }

    /** @return array{0:int,1:int,2:int,3:int} painted bounding box */
    private function bbox(Canvas $c): array
    {
        $rgba = $c->toPixels()['rgba'];
        $w = $c->getWidth();
        $h = $c->getHeight();
        $min = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (\ord($rgba[($y * $w + $x) * 4 + 3]) > 8) {
                    $min[0] = min($min[0], $x);
                    $min[1] = min($min[1], $y);
                    $min[2] = max($min[2], $x);
                    $min[3] = max($min[3], $y);
                }
            }
        }
        return $min;
    }

    public function testArcFillsACircle(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->arc(12, 12, 8, 0, M_PI * 2);
        $x->fill();
        $this->assertSame([4, 4, 19, 19], $this->bbox($c));
        $this->assertSame(255, $this->alpha($c, 12, 12));
        $this->assertSame(0, $this->alpha($c, 4, 4), 'the corner of the bounding box is outside');
        $this->assertGreaterThan(200, $this->alpha($c, 12, 5), 'the top edge is inside');
    }

    public function testArcSectorsAreDrawnAsSpecified(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->moveTo(12, 12);
        $x->arc(12, 12, 9, 0, M_PI / 2);
        $x->closePath();
        $x->fill();
        $this->assertSame(255, $this->alpha($c, 18, 15), 'the quarter towards +x,+y is painted');
        $this->assertSame(0, $this->alpha($c, 5, 5), 'the opposite quarter is not');
    }

    public function testEllipseRespectsRadii(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->ellipse(12, 12, 9, 4, 0, 0, M_PI * 2);
        $x->fill();
        [$x0, $y0, $x1, $y1] = $this->bbox($c);
        $this->assertEqualsWithDelta(3, $x0, 1);
        $this->assertEqualsWithDelta(20, $x1, 1);
        $this->assertEqualsWithDelta(8, $y0, 1);
        $this->assertEqualsWithDelta(15, $y1, 1);
    }

    public function testCurvesRemainCurvesWhenFilled(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->moveTo(2, 20);
        $x->bezierCurveTo(2, 2, 22, 2, 22, 20);
        $x->closePath();
        $x->fill();
        // A cubic bulges towards the middle: at x=12 the chord would be at y=11,
        // the curve is much higher.
        $this->assertLessThan(10, $this->topInk($c, 12));
        $this->assertGreaterThan(2, $this->topInk($c, 3), 'the ends stay near the baseline');
    }

    public function testQuadraticCurveBulges(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->moveTo(2, 22);
        $x->quadraticCurveTo(12, 2, 22, 22);
        $x->closePath();
        $x->fill();
        $this->assertEqualsWithDelta(12, $this->topInk($c, 12), 1);
        // At x=5 the curve is at y=16.9, so the ink there starts at row 16.
        $this->assertEqualsWithDelta(16, $this->topInk($c, 5), 1);
    }

    public function testSubpathsDoNotConnect(): void
    {
        [$c, $x] = $this->canvas();
        $x->fillStyle = '#ffffff';
        $x->beginPath();
        $x->rect(2, 2, 4, 4);
        $x->rect(14, 14, 4, 4);
        $x->fill();
        $this->assertSame(255, $this->alpha($c, 3, 3));
        $this->assertSame(255, $this->alpha($c, 15, 15));
        $this->assertSame(0, $this->alpha($c, 9, 9), 'no bridge is drawn between subpaths');
        $this->assertSame(0, $this->alpha($c, 3, 15));
    }

    public function testStrokeWidthAndCapGeometry(): void
    {
        $box = function (float $w, string $cap): array {
            [$c, $x] = $this->canvas();
            $x->strokeStyle = '#ffffff';
            $x->lineWidth = $w;
            $x->lineCap = $cap;
            $x->beginPath();
            $x->moveTo(6, 12);
            $x->lineTo(18, 12);
            $x->stroke();
            return $this->bbox($c);
        };
        $butt = $box(4, 'butt');
        $square = $box(4, 'square');
        $round = $box(4, 'round');
        $this->assertSame([6, 10, 17, 13], $butt, 'a butt cap stops at the endpoint');
        // A square and a round cap both reach half a line width (2) beyond the
        // endpoints; only the shape of the ends differs.
        $this->assertSame([4, 10, 19, 13], $square);
        $this->assertSame([4, 10, 19, 13], $round);
        $thin = $box(1, 'butt');
        // A 1px stroke centred on y=12 covers half of row 11 and half of row 12.
        $this->assertSame(11, $thin[1]);
        $this->assertSame(12, $thin[3]);
    }

    public function testDashPatternPaintsGaps(): void
    {
        [$c, $x] = $this->canvas();
        $x->strokeStyle = '#ffffff';
        $x->lineWidth = 2;
        $x->setLineDash([4, 4]);
        $this->assertSame([4.0, 4.0], $x->getLineDash());
        $x->beginPath();
        $x->moveTo(2, 12);
        $x->lineTo(22, 12);
        $x->stroke();
        $this->assertSame(255, $this->alpha($c, 3, 12));
        $this->assertSame(0, $this->alpha($c, 8, 12), 'the gap is empty');
        $this->assertSame(255, $this->alpha($c, 12, 12), 'the second dash returns');

        $x->reset();
        $x->strokeStyle = '#ffffff';
        $x->lineWidth = 2;
        $x->setLineDash([4, 4]);
        $x->lineDashOffset = 4.0;
        $x->beginPath();
        $x->moveTo(2, 12);
        $x->lineTo(22, 12);
        $x->stroke();
        $this->assertSame(0, $this->alpha($c, 3, 12), 'the offset moves the dash pattern');
    }

    public function testJoinsChangeTheCorner(): void
    {
        $corner = function (string $join): array {
            [$c, $x] = $this->canvas();
            $x->strokeStyle = '#ffffff';
            $x->lineWidth = 6;
            $x->lineJoin = $join;
            $x->beginPath();
            $x->moveTo(4, 4);
            $x->lineTo(20, 4);
            $x->lineTo(20, 20);
            $x->stroke();
            return [$this->alpha($c, 19, 5), $this->alpha($c, 22, 1)];
        };
        // (22, 1) is the tip of the outside corner: a miter reaches it, a round
        // join only grazes it, and a bevel cuts past it.
        $this->assertSame([255, 255], $corner('miter'), 'a miter fills the outside corner');
        $this->assertLessThan(40, $corner('round')[1], 'a round join rounds the tip away');
        $this->assertSame(255, $corner('bevel')[0]);
        $this->assertSame(0, $corner('bevel')[1], 'a bevel cuts the corner off');
    }

    public function testClipIsTransformedWithTheMatrix(): void
    {
        [$c, $x] = $this->canvas();
        $x->translate(6, 6);
        $x->beginPath();
        $x->rect(0, 0, 4, 4);
        $x->clip();
        $x->fillStyle = '#ffffff';
        $x->fillRect(0, 0, 24, 24);
        $this->assertSame(255, $this->alpha($c, 6, 6));
        $this->assertSame(255, $this->alpha($c, 9, 9));
        $this->assertSame(0, $this->alpha($c, 10, 10), 'the clip moved with the transform');
        $this->assertSame(0, $this->alpha($c, 5, 5));
    }

    public function testIsPointInPathAndStroke(): void
    {
        [, $x] = $this->canvas();
        $x->beginPath();
        $x->rect(10, 10, 10, 10);
        $this->assertTrue($x->isPointInPath(15, 15));
        $this->assertFalse($x->isPointInPath(25, 15));
        $this->assertTrue($x->isPointInPath(10, 10), 'the outline itself counts as inside');
        $this->assertTrue($x->isPointInStroke(10, 15), 'the left edge is stroked');
        $this->assertFalse($x->isPointInStroke(15, 15), 'the middle of a hollow path is not stroked');
        $x->lineWidth = 20;
        $this->assertTrue($x->isPointInStroke(15, 15), 'a wide stroke reaches the middle');
    }

    public function testTransformedPathsAndHitTests(): void
    {
        [$c, $x] = $this->canvas();
        $x->scale(2, 2);
        $x->beginPath();
        $x->rect(2, 2, 4, 4);
        $this->assertTrue($x->isPointInPath(5, 5), 'hit tests use user coordinates');
        $x->fillStyle = '#ffffff';
        $x->fill();
        $this->assertSame([4, 4, 11, 11], $this->bbox($c), 'the rectangle doubles in device space');
    }

    public function testTextIsPlacedAndMeasured(): void
    {
        [$c, $x] = $this->canvas(60, 24);
        $x->font = '16px sans-serif';
        $x->fillStyle = '#ffffff';
        $short = $x->measureText('i');
        $long = $x->measureText('iiii');
        $this->assertGreaterThan(0.0, $short['width']);
        $this->assertGreaterThan($short['width'] * 2, $long['width'], 'width grows with the text');
        $x->fillText('Hi', 4, 16);
        [$x0, $y0, $x1, $y1] = $this->bbox($c);
        $this->assertEqualsWithDelta(4, $x0, 2, 'text starts at the pen position');
        $this->assertLessThan(17, $y1, 'text sits on the alphabetic baseline');
        $this->assertGreaterThan(0, $y0);

        $x->reset();
        $x->font = '16px sans-serif';
        $x->textAlign = 'center';
        $x->fillStyle = '#ffffff';
        $x->fillText('Hi', 30, 16);
        $centred = $this->bbox($c);
        $this->assertEqualsWithDelta(30, ($centred[0] + $centred[2]) / 2, 3, 'centred text centres');
    }

    public function testStrokeTextIsHollow(): void
    {
        [$c, $x] = $this->canvas(60, 24);
        $x->font = '20px sans-serif';
        $x->lineWidth = 1.5;
        $x->strokeStyle = '#ffffff';
        [$before, $after] = [$this->alpha($c, 12, 12), 0];
        $x->strokeText('O', 6, 18);
        $this->assertGreaterThan(40, $this->alpha($c, 8, 11), 'the outline of the glyph is painted');
        $this->assertSame(0, $this->alpha($c, 14, 11), 'and the counter inside it stays empty');
    }

    public function testDrawImagePlacementAndScale(): void
    {
        $tile = new Canvas(4, 4);
        $t = $tile->getContext('2d');
        $t->fillStyle = '#ff0000';
        $t->fillRect(0, 0, 4, 4);

        [$c, $x] = $this->canvas(24, 24);
        $x->drawImage($tile, 2, 3);
        $this->assertSame([255, 0, 0, 255], [$this->alpha($c, 2, 3) ? 255 : 0, 0, 0, 255]);
        $this->assertSame(255, $this->alpha($c, 5, 6));
        $this->assertSame(0, $this->alpha($c, 6, 3), 'an unscaled draw copies pixel for pixel');

        $x->reset();
        $x->drawImage($tile, 0, 0, 12, 8);
        $this->assertSame(255, $this->alpha($c, 11, 7));
        $this->assertSame(0, $this->alpha($c, 12, 8), 'the destination size is respected');

        $x->reset();
        $x->drawImage($tile, 1, 1, 2, 2, 6, 6, 8, 8);
        $this->assertSame(255, $this->alpha($c, 13, 13));
        $this->assertSame(0, $this->alpha($c, 15, 15), 'the source rectangle crops the image');
    }

    public function testDrawImageHonoursAlphaAndMirrorsSize(): void
    {
        $tile = new Canvas(4, 4);
        $t = $tile->getContext('2d');
        $t->fillStyle = 'rgba(255, 0, 0, 0.5)';
        $t->fillRect(0, 0, 4, 4);

        [$c, $x] = $this->canvas(12, 12);
        $x->globalAlpha = 0.5;
        $x->drawImage($tile, 0, 0);
        $this->assertEqualsWithDelta(64, $this->alpha($c, 1, 1), 3, 'globalAlpha scales the image alpha');

        $x->reset();
        $x->drawImage($tile, 4, 0, -4, 4);
        $this->assertSame(0, $this->alpha($c, 4, 1), 'a negative width names the rectangle to the left');
        $this->assertGreaterThan(0, $this->alpha($c, 1, 1));
    }

    public function testImageDataRoundTrip(): void
    {
        [$c, $x] = $this->canvas(8, 8);
        $x->fillStyle = 'rgba(1, 2, 3, 1)';
        $x->fillRect(0, 0, 8, 8);
        $data = $x->getImageData(0, 0, 8, 8);
        $this->assertSame(8, $data['width']);
        $this->assertSame(8, $data['height']);
        $this->assertSame(64 * 4, strlen($data['data']));
        $this->assertSame([1, 2, 3, 255], [\ord($data['data'][0]), \ord($data['data'][1]), \ord($data['data'][2]), \ord($data['data'][3])]);

        $blank = $x->createImageData(4, 4);
        $this->assertSame(0, \ord($blank['data'][3]), 'createImageData is transparent');

        $x->clearRect(0, 0, 8, 8);
        $x->putImageData($data, 2, 2);
        $this->assertSame(0, $this->alpha($c, 1, 1), 'putImageData is not affected by the transform');
        $this->assertSame(255, $this->alpha($c, 2, 2));
        $this->assertSame(1, \ord($c->toPixels()['rgba'][((2 * 8) + 2) * 4]), 'the pixels are copied verbatim');
    }

    public function testCopyAndResize(): void
    {
        [$c, $x] = $this->canvas(8, 8);
        $x->fillStyle = '#ff0000';
        $x->fillRect(0, 0, 4, 8);
        $copy = $c->copy();
        $this->assertSame([255, 0, 0, 255], $this->rgbaAt($copy, 1, 1));
        $copy->getContext('2d')->fillStyle = '#00ff00';
        $copy->getContext('2d')->fillRect(0, 0, 1, 1);
        $this->assertSame([255, 0, 0, 255], $this->rgbaAt($c, 0, 0), 'a copy is independent');
        $this->assertSame(8, $c->getWidth());
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function rgbaAt(Canvas $c, int $x, int $y): array
    {
        $p = $c->toPixels()['rgba'];
        $o = ($y * $c->getWidth() + $x) * 4;
        return [\ord($p[$o]), \ord($p[$o + 1]), \ord($p[$o + 2]), \ord($p[$o + 3])];
    }

    private function topInk(Canvas $c, int $column): int
    {
        $rgba = $c->toPixels()['rgba'];
        for ($y = 0; $y < $c->getHeight(); $y++) {
            if (\ord($rgba[($y * $c->getWidth() + $column) * 4 + 3]) > 40) {
                return $y;
            }
        }
        return $c->getHeight();
    }
}
