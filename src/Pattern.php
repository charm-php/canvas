<?php declare(strict_types=1);

namespace Charm\Canvas;

/**
 * A repeating bitmap paint.
 */
final class Pattern
{
    public const REPEAT = 'repeat';
    public const REPEAT_X = 'repeat-x';
    public const REPEAT_Y = 'repeat-y';
    public const NO_REPEAT = 'no-repeat';

    private ?Matrix $toPattern = null;

    public function __construct(
        private readonly Surface $tile,
        private readonly string $repetition = self::REPEAT,
        ?Matrix $transform = null,
    ) {
        if ($transform !== null) {
            $this->toPattern = $transform->inverse();
        }
    }

    public function getRepetition(): string
    {
        return $this->repetition;
    }

    public function tile(): Surface
    {
        return $this->tile;
    }

    /**
     * @return array{0:float,1:float,2:float,3:float}
     */
    public function colorAt(float $userX, float $userY): array
    {
        $x = $userX;
        $y = $userY;
        if ($this->toPattern !== null) {
            $p = $this->toPattern->mapPoint($x, $y);
            $x = $p['x'];
            $y = $p['y'];
        }

        $w = $this->tile->width;
        $h = $this->tile->height;
        if ($w <= 0 || $h <= 0) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $repeatX = $this->repetition === self::REPEAT || $this->repetition === self::REPEAT_X;
        $repeatY = $this->repetition === self::REPEAT || $this->repetition === self::REPEAT_Y;

        if ($repeatX) {
            $x = fmod($x, (float) $w);
            if ($x < 0) {
                $x += $w;
            }
        } elseif ($x < 0 || $x >= $w) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        if ($repeatY) {
            $y = fmod($y, (float) $h);
            if ($y < 0) {
                $y += $h;
            }
        } elseif ($y < 0 || $y >= $h) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        return $this->tile->colorAt((int) floor($x), (int) floor($y));
    }
}
