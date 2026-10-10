<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/**
 * How far and how long a field step travels, in logical field pixels.
 *
 * The graphical field draws a terminal cell as one square RPG Maker tile, so
 * a step covers one cell size on either axis and walking at one speed in
 * pixels gives every step the same duration. This is the one place that rule
 * lives; nothing else corrects for an axis.
 *
 * The metric is in logical field pixels, before field zoom, device scale or
 * window fit, so none of those change how fast anything walks.
 */
final readonly class FieldMetric
{
  /** RPG Maker MZ's default walk: one 48-pixel tile in 16 frames at 60 frames per second. */
  public const float WALK_PIXELS_PER_SECOND = FieldViewport::TILE_SIZE / (16 / 60);

  public function __construct(
    public int $cellSize = FieldViewport::TILE_SIZE,
    public float $walkPixelsPerSecond = self::WALK_PIXELS_PER_SECOND,
  )
  {
    if ($cellSize < 1 || !is_finite($walkPixelsPerSecond) || $walkPixelsPerSecond <= 0.0) {
      throw new InvalidArgumentException('A field metric needs a positive cell size and a positive, finite walking speed.');
    }
  }

  /** Logical pixels between two cells a whole number of cells apart. */
  public function getDistance(Vector2 $displacement): float
  {
    return hypot($displacement->x, $displacement->y) * $this->cellSize;
  }

  /** Seconds a walker takes to cover the displacement at walking speed. */
  public function getWalkSeconds(Vector2 $displacement): float
  {
    return $this->getDistance($displacement) / $this->walkPixelsPerSecond;
  }
}
