<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Events\Interfaces\AutomaticEventTriggerInterface;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\Validation\MapEventSpeakerRule;
use InvalidArgumentException;

/**
 * An event as reachability sees it: authored cells, engagement, speakers and
 * conditions, and whether standing there takes the player off the map.
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
    /** @var list<string> NPC ids that speak for the event ({@see MapEventSpeakerRule}). */
    public readonly array $speakers = [],
    /** @var list<array<string, mixed>> The event's effective world conditions, its subject's included. */
    public readonly array $conditions = [],
    /** Whether it runs without the action key, as the player steps in. */
    public readonly bool $runsAutomatically = false,
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
      self::readSpeakers($definition),
      array_values(array_filter((array) ($definition['conditions'] ?? []), is_array(...))),
      is_a($class = strval($definition['class'] ?? ''), AutomaticEventTriggerInterface::class, true)
        && $class::determineRunsAutomatically((array) ($definition['data'] ?? [])),
    );
  }

  /** Malformed speakers are reported by {@see MapEventSpeakerRule}; reachability reads them as none. */
  private static function readSpeakers(array $definition): array
  {
    try {
      return MapEventSpeakerRule::readSpeakers($definition);
    } catch (InvalidArgumentException) {
      return [];
    }
  }

  /** Whether standing in the area takes the player off the map. */
  public bool $isExit {
    get => is_a($this->class, TransferPlayerTrigger::class, true);
  }

  /**
   * Whether the player walks up to the event to use it with the action key:
   * a faced object or an event someone speaks for. A transfer is a route and
   * an automatic event runs as the player steps in, so neither is one.
   */
  public bool $isInteraction {
    get => ! $this->isExit && ! $this->runsAutomatically
      && ($this->engagement === EventEngagement::FACING || $this->speakers !== []);
  }

  /**
   * Cells where the player stands at the event's subject, on it or beside it
   * from any side, whether or not the event answers there; speakers are read
   * by the map, which knows where they stand.
   */
  public function getContactArea(): CellArea
  {
    return $this->engagement === EventEngagement::INSIDE ? $this->area
      : new self($this->area, $this->class, $this->marker, EventEngagement::FACING)->getStandingArea();
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
