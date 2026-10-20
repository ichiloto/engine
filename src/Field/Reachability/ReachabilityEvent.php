<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use InvalidArgumentException;

/**
 * An event as reachability sees it: authored cells and engagement, and
 * whether standing there takes the player off the map.
 *
 * Read from a resolved event definition without building the trigger, which
 * may need the running game (a shop needs its item store).
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final class ReachabilityEvent
{
  public function __construct(
    public readonly CellArea $area,
    public readonly string $class,
    public readonly ?string $marker = null,
    public readonly EventEngagement $engagement = EventEngagement::INSIDE,
    public readonly array $facing = [],
  )
  {
  }

  /**
   * Reads a resolved event definition, as MapSourceReader returns it.
   *
   * @param array<string, mixed> $definition
   */
  public static function fromDefinition(array $definition): self
  {
    if (! isset($definition['area'])) {
      throw new InvalidArgumentException('An event definition has no resolved area.');
    }

    return new self(
      CellArea::fromArray($definition['area'], 'Event area'),
      strval($definition['class'] ?? ''),
      isset($definition['marker']) ? strval($definition['marker']) : null,
      array_key_exists('engagement', $definition) ? EventEngagement::parse($definition['engagement']) : EventEngagement::INSIDE,
      EventTrigger::parseFacing(array_key_exists('facing', $definition) ? $definition['facing'] : []),
    );
  }

  /** Whether standing in the area takes the player off the map. */
  public bool $isExit {
    get => is_a($this->class, TransferPlayerTrigger::class, true);
  }

  /** Standing cells from which the event can be engaged, assuming story gates are open. */
  public function getStandingArea(): CellArea
  {
    if ($this->engagement === EventEngagement::INSIDE) { return $this->area; }
    // A walkable object cell is a standing cell too; a solid one is never reached.
    $cells = $this->area->cells;
    foreach ($this->area->cells as [$x, $y]) {
      foreach ($this->facing ?: [MovementHeading::NORTH, MovementHeading::EAST, MovementHeading::SOUTH, MovementHeading::WEST] as $heading) {
        $direction = $heading->getDirection();
        $position = new Vector2($x - $direction->x, $y - $direction->y);
        if ($this->engagement->canReach($this->area, $position, $heading)) {
          $cells[] = [(int) $position->x, (int) $position->y];
        }
      }
    }
    return CellArea::fromCells($cells);
  }
}
