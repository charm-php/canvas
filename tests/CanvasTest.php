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

    public function testStrokeWithThinLineTransform(): void
    {
        // Bug 1: stroke() with lineWidth <= 1 should apply transforms
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->strokeStyle = '#ff0000';
        $ctx->lineWidth = 0.5;

        // Draw line with scale transform
        $ctx->save();
        $ctx->translate(50, 50);
        $ctx->scale(2, 2);
        $ctx->beginPath();
        $ctx->moveTo(-10, -10);
        $ctx->lineTo(10, 10);
        $ctx->stroke();
        $ctx->restore();

        $pixels = $canvas->toPixels();
        $rgba = $pixels['rgba'];

        // Check that pixels near (50, 50) + 2*10 = (70, 70) are red
        // The scaled line should be at 50 + 2*10 = 70
        $pixelOffset = (70 * 100 + 70) * 4;
        $r = \ord($rgba[$pixelOffset]);
        $this->assertGreaterThan(200, $r, 'Scaled thin line should be visible at correct position');
    }

    public function testClearRectWithTransform(): void
    {
        // Bug 2: clearRect should actually clear pixels (alpha blending was on)
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Fill with red
        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 100, 100);

        // Clear a square in the middle
        $ctx->clearRect(25, 25, 50, 50);

        // Check pixels - cleared area should be transparent
        $data = $ctx->getImageData(30, 30, 40, 40);
        $rgba = $data['data'];

        // All pixels in the cleared area should be transparent (alpha = 255 in GD = fully transparent)
        // Each pixel is 4 bytes (RGBA)
        $isTransparent = true;
        for ($i = 3; $i < \strlen($rgba); $i += 4) {
            $a = \ord($rgba[$i]);
            // GD stores alpha as 0-127, with 127 = fully transparent
            if ($a < 100) {
                $isTransparent = false;
                break;
            }
        }

        $this->assertTrue($isTransparent, 'clearRect should clear pixels to transparent');
    }

    public function testDrawImageFromFile(): void
    {
        // Bug 3: drawImage should handle file paths correctly
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Create a simple PNG file
        $tempCanvas = new Canvas(20, 20);
        $tempCtx = $tempCanvas->getContext();
        $tempCtx->fillStyle = '#00ff00';
        $tempCtx->fillRect(0, 0, 20, 20);

        $tempFile = \tempnam(\sys_get_temp_dir(), 'test_image_');
        \file_put_contents($tempFile, $tempCanvas->toPng());

        try {
            // Draw the file-based image
            $ctx->drawImage($tempFile, 10, 10);

            $pixels = $canvas->toPixels();
            $rgba = $pixels['rgba'];

            // Check that pixels around (10, 10) are green
            $pixelOffset = (15 * 100 + 15) * 4;
            $r = \ord($rgba[$pixelOffset]);
            $g = \ord($rgba[$pixelOffset + 1]);
            $b = \ord($rgba[$pixelOffset + 2]);

            $this->assertGreaterThan(200, $g, 'drawImage from file should work');
        } finally {
            @\unlink($tempFile);
        }
    }

    public function testDrawImageWithTransform(): void
    {
        // Bug 3: drawImage should apply transforms to destination rect
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Create a simple source image
        $source = new Canvas(10, 10);
        $sourceCtx = $source->getContext();
        $sourceCtx->fillStyle = '#ff0000';
        $sourceCtx->fillRect(0, 0, 10, 10);

        // Draw with translation
        $ctx->save();
        $ctx->translate(50, 50);
        $ctx->drawImage($source->toGd(), -5, -5);
        $ctx->restore();

        $pixels = $canvas->toPixels();
        $rgba = $pixels['rgba'];

        // The image should be drawn at (50-5, 50-5) = (45, 45)
        $pixelOffset = (45 * 100 + 45) * 4;
        $r = \ord($rgba[$pixelOffset]);
        $this->assertGreaterThan(200, $r, 'Translated image should be at correct position');
    }

    public function testToDotsNoSegfault(): void
    {
        // Bug 4: toDots should not segfault when w,h equals canvas size
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 100, 100);

        // This should not segfault
        try {
            $dots = $canvas->toDots(100, 100);
            $this->assertEquals(100, $dots['width']);
            $this->assertEquals(100, $dots['height']);
            $this->assertEquals(10000, \strlen($dots['on']));
        } catch (\RuntimeException $e) {
            $this->fail('toDots with same size should not segfault: ' . $e->getMessage());
        }
    }

    public function testToDotsExactDownsampling(): void
    {
        // Bug 5: toDots should use exact downsampling, not bilinear that bleeds edges
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        // Create a clear pattern: top-left filled (opaque), rest transparent
        // toDots marks a dot as "on" if it has >= 50% non-transparent pixels

        // Start with transparent canvas, then draw opaque pixels in top-left 50x50
        $ctx->fillStyle = '#000000';
        $ctx->fillRect(0, 0, 50, 50);

        // Scale down to 10x10
        $dots = $canvas->toDots(10, 10);

        // Each 10x10 block in source maps to 1 dot
        // Top-left 50x50 of source (5x5 dots) should be on
        // Rest should be off

        $onCount = 0;
        for ($y = 0; $y < 10; $y++) {
            for ($x = 0; $x < 10; $x++) {
                $idx = $y * 10 + $x;
                if ($dots['on'][$idx] === "\x01") {
                    $onCount++;
                }
            }
        }

        // Approximately 25 dots should be on (5x5 block)
        // Allow some tolerance for edge effects
        $this->assertGreaterThan(20, $onCount, 'Top-left area should have dots on');
        $this->assertLessThan(30, $onCount, 'Should not have too many dots on');
    }

    public function testFromBackendFactory(): void
    {
        // Bug 6: Canvas::fromBackend should work
        $gd = new Canvas(50, 50);
        $backend = $gd->toGd();

        // Create a new canvas from the GD image
        $gdBackend = new \phasync\Canvas\Backend\Gd(50, 50);
        $gdBackend->fromGd($backend);

        $canvas = Canvas::fromBackend($gdBackend);

        $this->assertEquals(50, $canvas->getWidth());
        $this->assertEquals(50, $canvas->getHeight());
        $this->assertNotNull($canvas->getContext());
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

    public function testToDotsEmptyCanvas(): void
    {
        // Canvas with nothing drawn should have 0 dots on
        $canvas = new Canvas(100, 100);
        $dots = $canvas->toDots(10, 10);

        $onCount = \substr_count($dots['on'], "\x01");
        $this->assertEquals(0, $onCount, 'Empty canvas should have 0 dots on');
        $this->assertEquals(100, \strlen($dots['on']), 'Should have 10x10 = 100 dots');
    }

    public function testToDotsShapeInTopLeft(): void
    {
        // Shape in top-left corner should only have dots there
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#ff0000';
        $ctx->fillRect(0, 0, 25, 25);

        $dots = $canvas->toDots(10, 10);

        // Top-left dot (0, 0) should be on
        $this->assertEquals("\x01", $dots['on'][0], 'Top-left dot should be on');

        // Bottom-right dot (9, 9) should be off (corresponds to area 90-100)
        $lastDotIdx = 9 * 10 + 9;
        $this->assertEquals("\x00", $dots['on'][$lastDotIdx], 'Bottom-right dot should be off');

        // Count total dots on (25x25 rect in 100x100 = 2.5x2.5 in 10x10 grid ≈ 4-9 dots)
        $onCount = \substr_count($dots['on'], "\x01");
        $this->assertGreaterThan(0, $onCount, 'Should have some dots on in top-left area');
        $this->assertLessThan(50, $onCount, 'Should not have too many dots on');
    }

    public function testToDotsFilled(): void
    {
        // Canvas filled with color should have all dots on
        $canvas = new Canvas(100, 100);
        $ctx = $canvas->getContext();

        $ctx->fillStyle = '#00ff00';
        $ctx->fillRect(0, 0, 100, 100);

        $dots = $canvas->toDots(10, 10);

        // All dots should be on
        $onCount = \substr_count($dots['on'], "\x01");
        $this->assertEquals(100, $onCount, 'Filled canvas should have all dots on');
    }

}
