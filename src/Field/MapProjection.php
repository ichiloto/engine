<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** North-up whole-map fit, independent of camera, player, knowledge rules and renderer artwork. */
final readonly class MapProjection
{
    private function __construct(
        public int $mapWidth,
        public int $mapHeight,
        public float $left,
        public float $top,
        public float $width,
        public float $height,
        public float $scaleX,
        public float $scaleY,
    ) {}

    /**
     * Fit the entire physical map with equal display scale on both axes.
     * Bounds use the consumer's units. Pixels use 1:1; terminal cells supply their display width/height.
     */
    public static function fit(int $mapWidth, int $mapHeight, Rect $bounds,
        float $displayUnitWidth = 1.0, float $displayUnitHeight = 1.0): self
    {
        if ($mapWidth < 1 || $mapHeight < 1 || $bounds->getWidth() < 1 || $bounds->getHeight() < 1
            || !is_finite($displayUnitWidth) || !is_finite($displayUnitHeight)
            || $displayUnitWidth <= 0 || $displayUnitHeight <= 0) {
            throw new InvalidArgumentException('A whole-map projection requires positive map, view and display-unit extents.');
        }
        $physicalWidth = $bounds->getWidth() * $displayUnitWidth;
        $physicalHeight = $bounds->getHeight() * $displayUnitHeight;
        if (!is_finite($physicalWidth) || !is_finite($physicalHeight)) {
            throw new InvalidArgumentException('Map projection display extents must be finite.');
        }
        $scale = min($physicalWidth / $mapWidth, $physicalHeight / $mapHeight);
        $scaleX = $scale / $displayUnitWidth;
        $scaleY = $scale / $displayUnitHeight;
        if (!is_finite($scaleX) || !is_finite($scaleY) || $scaleX <= 0 || $scaleY <= 0) {
            throw new InvalidArgumentException('Map projection scale must be finite and positive.');
        }
        $width = $mapWidth * $scaleX;
        $height = $mapHeight * $scaleY;

        return new self($mapWidth, $mapHeight,
            $bounds->getX() + ($bounds->getWidth() - $width) / 2,
            $bounds->getY() + ($bounds->getHeight() - $height) / 2,
            $width, $height, $scaleX, $scaleY);
    }

    /** Project a physical point, including outer map edges, without moving or clamping gameplay coordinates. */
    public function projectPoint(float $x, float $y): Vector2
    {
        if (!is_finite($x) || !is_finite($y) || $x < 0 || $y < 0 || $x > $this->mapWidth || $y > $this->mapHeight) {
            throw new InvalidArgumentException('A projected point must lie inside the physical map bounds.');
        }
        return new Vector2($this->left + $x * $this->scaleX, $this->top + $y * $this->scaleY);
    }

    /** Player cells and landmark cells share the same centre placement. */
    public function projectCell(int $x, int $y): Vector2
    {
        if ($x < 0 || $y < 0 || $x >= $this->mapWidth || $y >= $this->mapHeight) {
            throw new InvalidArgumentException('A projected cell must belong to the physical map.');
        }
        return $this->projectPoint($x + 0.5, $y + 0.5);
    }
}
