<?php

namespace Ichiloto\Engine\Events\Enumerations;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\InteractionReach;
use InvalidArgumentException;

/** Selects the player's spatial relationship to an event's authored cells. */
enum EventEngagement: string
{
  case INSIDE = 'inside';
  case FACING = 'facing';

  public static function parse(mixed $value): self
  {
    return (is_string($value) ? self::tryFrom($value) : null)
      ?? throw new InvalidArgumentException('Event engagement must be inside or facing.');
  }

  /** Spatial reach shared by runtime engagement and map reachability. */
  public function canReach(CellArea $area, Vector2 $position, MovementHeading $heading): bool
  {
    if ($this === self::INSIDE) {
      return $area->contains($position);
    }
    $direction = $heading->getDirection();
    $cell = InteractionReach::findFacingCell((int) floor($position->x), (int) floor($position->y),
      (int) $direction->x, (int) $direction->y);
    return !$area->contains($position) && $cell !== null && $area->containsCell(...$cell);
  }
}
