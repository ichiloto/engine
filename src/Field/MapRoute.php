<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;

/**
 * One way the player can take from a map to another: a transfer event, a
 * legacy transfer trigger, or a reusable script event that transfers.
 */
final readonly class MapRoute
{
  /**
   * @param string $source The map the route starts on.
   * @param string $destination The map it leads to.
   * @param string $marker The source event's marker letter, or `@legacy:<id>` for a legacy trigger.
   * @param CellArea $area The cells the player uses on the source map.
   * @param list<array<string, mixed>> $conditions The source event's availability conditions.
   * @param bool $scripted True when a reusable event script performs the transfer.
   * @param string|null $landmarkId The source landmark that claims the event, if any.
   * @param bool $secret True when that landmark must be revealed before the route counts.
   */
  public function __construct(
    public string $source,
    public string $destination,
    public string $marker,
    public CellArea $area,
    public array $conditions = [],
    public bool $scripted = false,
    public ?string $landmarkId = null,
    public bool $secret = false,
  )
  {
  }
}
