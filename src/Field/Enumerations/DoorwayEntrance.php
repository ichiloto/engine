<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Enumerations;

/**
 * Where a painted doorway's entrance lies relative to the door the art paints.
 *
 * @package Ichiloto\Engine\Field\Enumerations
 */
enum DoorwayEntrance: string
{
  /** The player walks onto the painted opening itself, such as a cave mouth or an open archway. */
  case ON = 'on';
  /** The door is painted on a solid face and entered from the cell directly in front of it, below it on the map. */
  case FRONT = 'front';

  /** The cell holding the entrance for a door painted at the given cell. @return array{int, int} */
  public function getEntranceCell(int $x, int $y): array
  {
    return match ($this) {
      self::ON => [$x, $y],
      self::FRONT => [$x, $y + 1],
    };
  }
}
