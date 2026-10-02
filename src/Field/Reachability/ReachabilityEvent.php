<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use InvalidArgumentException;

/**
 * An event as reachability sees it: the area the player must stand in, and
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
    public readonly Rect $area,
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
    $area = $definition['area'] ?? null;

    if (! is_array($area) || ! isset($area['x'], $area['y'], $area['width'], $area['height'])) {
      throw new InvalidArgumentException('An event definition has no resolved area.');
    }

    return new self(
      new Rect(intval($area['x']), intval($area['y']), intval($area['width']), intval($area['height'])),
      strval($definition['class'] ?? ''),
      isset($definition['marker']) ? strval($definition['marker']) : null,
    );
  }

  /** Whether standing in the area takes the player off the map. */
  public bool $isExit {
    get => is_a($this->class, TransferPlayerTrigger::class, true);
  }
}
