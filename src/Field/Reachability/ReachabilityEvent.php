<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use InvalidArgumentException;

/**
 * An event as reachability sees it: the cells the player must stand on, and
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
    );
  }

  /** Whether standing in the area takes the player off the map. */
  public bool $isExit {
    get => is_a($this->class, TransferPlayerTrigger::class, true);
  }
}
