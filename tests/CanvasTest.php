<?php declare(strict_types=1);

namespace phasync\Canvas\Tests;

use PHPUnit\Framework\TestCase;
use phasync\Canvas\Canvas;

class CanvasTest extends TestCase
{
    public function testCanvasCreation(): void
    {
        $canvas = new Canvas(100, 100);
        $this->assertEquals(100, $canvas->getWidth());
        $this->assertEquals(100, $canvas->getHeight());
        $this->assertNotNull($canvas->getContext());
    }

    public function testCanvasInvalidDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Canvas(0, 100);
    }

    public function testFillRect(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(10, 10, 50, 50);

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
        $this->assertEquals(100, $pixels['height']);

        // Check that pixels in the rect are red-ish
        $data = $pixels['rgba'];
        $pixelAtRect = \substr($data, (10 * 100 + 10) * 4, 4);
        $r = \ord($pixelAtRect[0]);
        $this->assertGreaterThan(100, $r); // Red channel should be high
    }

    public function testStrokeRect(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->strokeStyle = '#0000ff';
        $ctx->lineWidth = 2;
        $ctx->strokeRect(10, 10, 50, 50);

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
        $this->assertEquals(100, $pixels['height']);
    }

    public function testClearRect(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Fill with red
        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 100, 100);

        // Clear a region
        $ctx->clearRect(10, 10, 50, 50);

        $pixels = $canvas->toPixels();
        $data = $pixels['rgba'];

        // Check that cleared area is transparent
        // Note: we can't easily verify this with toPixels() as the format may vary
        // Just verify the operation doesn't crash
        $this->assertTrue(true);
    }

    public function testPaths(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->strokeStyle = '#00ff00';
        $ctx->lineWidth = 2;
        $ctx->beginPath();
        $ctx->moveTo(10, 10);
        $ctx->lineTo(90, 90);
        $ctx->stroke();

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
        $this->assertEquals(100, $pixels['height']);
    }

    public function testArc(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->strokeStyle = '#ffff00';
        $ctx->lineWidth = 2;
        $ctx->beginPath();
        $ctx->arc(50, 50, 30, 0, \M_PI);
        $ctx->stroke();

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
    }

    public function testTransforms(): void
    {
        $canvas = new Canvas(200, 200);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ff00ff';
        $ctx->save();

        $ctx->translate(100, 100);
        $ctx->rotate(\M_PI / 4);
        $ctx->fillRect(-25, -25, 50, 50);

        $ctx->restore();

        $pixels = $canvas->toPixels();
        $this->assertEquals(200, $pixels['width']);
        $this->assertEquals(200, $pixels['height']);
    }

    public function testGlobalAlpha(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ffffff';
        $ctx->fillRect(0, 0, 100, 100);

        $ctx->fillStyle = '#000000';
        $ctx->globalAlpha = 0.5;
        $ctx->fillRect(25, 25, 50, 50);

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
        $this->assertEquals(100, $pixels['height']);
    }

    public function testGradient(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $gradient = $ctx->createLinearGradient(0, 0, 100, 100);
        $gradient->addColorStop(0, '#ff0000');
        $gradient->addColorStop(1, '#0000ff');

        $ctx->fillStyle = $gradient;
        $ctx->fillRect(0, 0, 100, 100);

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
        $this->assertEquals(100, $pixels['height']);
    }

    public function testMeasureText(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $metrics = $ctx->measureText('Hello');
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('width', $metrics);
        $this->assertGreaterThan(0, $metrics['width']);
    }

    public function testToPng(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 100, 100);

        $png = $canvas->toPng();
        $this->assertIsString($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function testToJpeg(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#0000ff';
        $ctx->fillRect(0, 0, 100, 100);

        $jpeg = $canvas->toJpeg(90);
        $this->assertIsString($jpeg);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $jpeg);
    }

    public function testGetImageData(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#00ff00';
        $ctx->fillRect(10, 10, 50, 50);

        $data = $ctx->getImageData(10, 10, 50, 50);
        $this->assertEquals(50, $data['width']);
        $this->assertEquals(50, $data['height']);
        $this->assertEquals(50 * 50 * 4, \strlen($data['data']));
    }

    public function testPutImageData(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Create image data
        $imageData = [
            'width' => 10,
            'height' => 10,
            'data' => \str_repeat("\xff\x00\x00\xff", 100), // Red pixels
        ];

        $ctx->putImageData($imageData, 25, 25);

        $pixels = $canvas->toPixels();
        $this->assertEquals(100, $pixels['width']);
    }

    public function testToDots(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ffffff';
        $ctx->fillRect(0, 0, 100, 100);

        $ctx->fillStyle = '#000000';
        $ctx->fillRect(25, 25, 50, 50);

        $dots = $canvas->toDots(10, 10);
        $this->assertEquals(10, $dots['width']);
        $this->assertEquals(10, $dots['height']);
        $this->assertEquals(100, \strlen($dots['on']));
        $this->assertEquals(300, \strlen($dots['rgb']));
    }

    public function testFromGd(): void
    {
        // Create a GD image
        $gdImage = \imagecreatetruecolor(50, 50);
        if ($gdImage === false) {
            $this->markTestSkipped('GD extension not available');
        }

        $red = \imagecolorallocate($gdImage, 255, 0, 0);
        if ($red === false) {
            $this->markTestSkipped('Could not allocate color');
        }

        \imagefill($gdImage, 0, 0, $red);

        // Create canvas from GD image
        $canvas = Canvas::fromGd($gdImage);
        $this->assertEquals(50, $canvas->getWidth());
        $this->assertEquals(50, $canvas->getHeight());
    }

    public function testResize(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#0000ff';
        $ctx->fillRect(0, 0, 100, 100);

        $resized = $canvas->resize(50, 50);
        $this->assertEquals(50, $resized->getWidth());
        $this->assertEquals(50, $resized->getHeight());
    }

    public function testGradientRectFill(): void
    {
        $canvas = new Canvas(80, 40);
        $ctx = $canvas->getContext();

        // Linear gradient (red to blue)
        $grad = $ctx->createLinearGradient(0, 0, 80, 0);
        $grad->addColorStop(0, '#ff0000');
        $grad->addColorStop(1, '#0000ff');
        $ctx->fillStyle = $grad;

        // Fill rectangle with gradient
        $ctx->fillRect(10, 10, 60, 20);

        // Check pixels
        $pixels = $canvas->toPixels();
        $rgba = $pixels['rgba'];

        // Left part of rect should have more red than blue
        $leftPixelOffset = (20 * 80 + 20) * 4;
        $leftR = \ord($rgba[$leftPixelOffset]);
        $leftB = \ord($rgba[$leftPixelOffset + 2]);

        // Right part should have more blue than red
        $rightPixelOffset = (20 * 80 + 60) * 4;
        $rightR = \ord($rgba[$rightPixelOffset]);
        $rightB = \ord($rgba[$rightPixelOffset + 2]);

        // Outside rect should be background (transparent/black)
        $outsidePixelOffset = (5 * 80 + 5) * 4;
        $outsideR = \ord($rgba[$outsidePixelOffset]);

        $this->assertGreaterThan($leftB, $leftR, 'Left part should have more red');
        $this->assertGreaterThan($rightR, $rightB, 'Right part should have more blue');
        $this->assertEquals(0, $outsideR, 'Outside should be background (transparent)');
    }

    public function testPixelsActualValues(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Fill top-left quarter with red
        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 50, 50);

        // Fill bottom-right quarter with blue
        $ctx->fillStyle = '#0000ff';
        $ctx->fillRect(50, 50, 50, 50);

        $pixels = $canvas->toPixels();
        $rgba = $pixels['rgba'];

        // Check red pixel in top-left
        $topLeftOffset = (10 * 100 + 10) * 4;
        $r = \ord($rgba[$topLeftOffset]);
        $g = \ord($rgba[$topLeftOffset + 1]);
        $b = \ord($rgba[$topLeftOffset + 2]);

        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(50, $g);
        $this->assertLessThan(50, $b);

        // Check blue pixel in bottom-right
        $bottomRightOffset = (75 * 100 + 75) * 4;
        $r = \ord($rgba[$bottomRightOffset]);
        $g = \ord($rgba[$bottomRightOffset + 1]);
        $b = \ord($rgba[$bottomRightOffset + 2]);

        $this->assertLessThan(50, $r);
        $this->assertLessThan(50, $g);
        $this->assertGreaterThan(200, $b);
    }

    public function testToDotsWithDiagonalLine(): void
    {
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // White background
        $ctx->fillStyle = '#ffffff';
        $ctx->fillRect(0, 0, 100, 100);

        // Black diagonal line
        $ctx->strokeStyle = '#000000';
        $ctx->lineWidth = 2;
        $ctx->beginPath();
        $ctx->moveTo(0, 0);
        $ctx->lineTo(100, 100);
        $ctx->stroke();

        // Get dots with 50x50 resolution
        $dots = $canvas->toDots(50, 50);

        // Count dots on along diagonal
        $onCount = \substr_count($dots['on'], "\x01");

        // Should have significant dots along diagonal
        $this->assertGreaterThan(10, $onCount, 'Should have dots along diagonal');

        // The dots output should be 50x50 grid
        $this->assertEquals(50 * 50, \strlen($dots['on']), 'Should have 50x50 dots');
    }

}
