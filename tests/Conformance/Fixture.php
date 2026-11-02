<?php declare(strict_types=1);

namespace phasync\Canvas\Conformance;

/**
 * Deterministic image fixtures shared by both engines: PHP loads the PNG file,
 * Chrome loads the identical bytes as a data: URL.
 */
final class Fixture
{
    private string $dir;
    private ?\GdImage $tile = null;
    private ?\GdImage $tileAlpha = null;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/Fixtures';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->ensure();
    }

    private function ensure(): void
    {
        $this->buildTile();
        $this->buildTileAlpha();
    }

    public function tilePath(): string
    {
        return $this->dir . '/tile.png';
    }

    public function tileAlphaPath(): string
    {
        return $this->dir . '/tile_alpha.png';
    }

    public function tile(): \GdImage
    {
        return $this->tile ??= self::load($this->tilePath());
    }

    public function tileAlpha(): \GdImage
    {
        return $this->tileAlpha ??= self::load($this->tileAlphaPath());
    }

    public function tileDataUrl(): string
    {
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->tilePath()));
    }

    public function tileAlphaDataUrl(): string
    {
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($this->tileAlphaPath()));
    }

    /** A real TTF both engines can be pointed at, as a data: URL. */
    public function fontDataUrl(): ?string
    {
        $path = self::fontPath();
        if ($path === null) {
            return null;
        }
        return 'data:font/ttf;base64,' . base64_encode((string) file_get_contents($path));
    }

    public static function fontPath(): ?string
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private function buildTile(): void
    {
        $path = $this->tilePath();
        if (is_file($path)) {
            return;
        }
        $im = imagecreatetruecolor(8, 8);
        imagealphablending($im, false);
        $blocks = [[0, 0, '#e63946'], [4, 0, '#f1a208'], [0, 4, '#2a9d8f'], [4, 4, '#264653']];
        foreach ($blocks as [$bx, $by, $hex]) {
            [$r, $g, $b] = self::hex($hex);
            $c = imagecolorallocate($im, $r, $g, $b);
            imagefilledrectangle($im, $bx, $by, $bx + 3, $by + 3, $c);
        }
        imagesavealpha($im, false);
        imagepng($im, $path);
        imagedestroy($im);
    }

    private function buildTileAlpha(): void
    {
        $path = $this->tileAlphaPath();
        if (is_file($path)) {
            return;
        }
        $im = imagecreatetruecolor(8, 8);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        // Four cells: opaque red, 50% blue, 25% green, fully transparent.
        $cells = [
            [0, 0, [255, 0, 0, 127]],
            [4, 0, [0, 0, 255, 64]],
            [0, 4, [0, 255, 0, 32]],
            [4, 4, [0, 0, 0, 127]],
        ];
        foreach ($cells as [$bx, $by, [$r, $g, $b, $a]]) {
            $c = imagecolorallocatealpha($im, $r, $g, $b, $a);
            imagefilledrectangle($im, $bx, $by, $bx + 3, $by + 3, $c);
        }
        imagepng($im, $path);
        imagedestroy($im);
    }

    private static function load(string $path): \GdImage
    {
        $im = imagecreatefromstring((string) file_get_contents($path));
        if (!$im instanceof \GdImage) {
            throw new \RuntimeException("fixture unreadable: $path");
        }
        return $im;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
