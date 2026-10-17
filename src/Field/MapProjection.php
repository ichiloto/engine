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
        public float $sourceX = 0,
        public float $sourceY = 0,
        public ?float $sourceWidth = null,
        public ?float $sourceHeight = null,
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
        return new Vector2($this->left + ($x - $this->sourceX) * $this->scaleX,
            $this->top + ($y - $this->sourceY) * $this->scaleY);
    }

    /** Fixed-scale viewing window. Clamp presentation alone; never change a physical position. */
    public static function createViewport(int $mapWidth, int $mapHeight, Rect $bounds,
        float $centreX, float $centreY, float $scaleX, float $scaleY): self
    {
        if ($mapWidth < 1 || $mapHeight < 1 || min($bounds->getWidth(), $bounds->getHeight()) < 1
            || !array_all([$centreX,$centreY,$scaleX,$scaleY], static fn(float $value): bool=>is_finite($value)) || min($scaleX,$scaleY) <= 0) {
            throw new InvalidArgumentException('Map viewport geometry must be finite with positive extents and scale.');
        }
        $sourceWidth = min((float)$mapWidth, $bounds->getWidth() / $scaleX);
        $sourceHeight = min((float)$mapHeight, $bounds->getHeight() / $scaleY);
        $sourceX = max(0.0,min($mapWidth-$sourceWidth,$centreX-$sourceWidth/2));
        $sourceY = max(0.0,min($mapHeight-$sourceHeight,$centreY-$sourceHeight/2));
        return new self($mapWidth,$mapHeight,
            $bounds->getX()+($bounds->getWidth()-$sourceWidth*$scaleX)/2,
            $bounds->getY()+($bounds->getHeight()-$sourceHeight*$scaleY)/2,
            $sourceWidth*$scaleX,$sourceHeight*$scaleY,$scaleX,$scaleY,$sourceX,$sourceY,$sourceWidth,$sourceHeight);
    }

    public function containsCell(int $x, int $y): bool
    {
        return $x+0.5 >= $this->sourceX && $y+0.5 >= $this->sourceY
            && $x+0.5 < $this->sourceX+($this->sourceWidth ?? $this->mapWidth)
            && $y+0.5 < $this->sourceY+($this->sourceHeight ?? $this->mapHeight);
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
