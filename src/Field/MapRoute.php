<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;

/**
 * One way the player can take from a map to another: a transfer event, a
 * legacy transfer trigger, a reusable script event that transfers, or a
 * reusable NPC page whose script transfers (used by talking to the NPC).
 */
final readonly class MapRoute
{
  /**
   * @param string $source The map the route starts on.
   * @param string $destination The map it leads to.
   * @param string $marker The source event's marker letter, `@legacy:<id>` for a legacy trigger or `@npc:<id>` for an NPC page.
   * @param CellArea $area The cells the player uses on the source map: an NPC's authored cell for an NPC page.
   * @param list<array<string, mixed>> $conditions The source event's availability conditions, or the NPC's presence and page conditions.
   * @param bool $scripted True when a reusable event script performs the transfer.
   * @param string|null $landmarkId The source landmark that claims the event, if any.
   * @param bool $secret True when that landmark must be revealed before the route counts.
   * @param string|null $npcId The NPC whose page performs the transfer, if an NPC page does.
   * @param int|null $npcPage That page's index: the route counts only while it is the page talking runs.
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
    public ?string $npcId = null,
    public ?int $npcPage = null,
  )
  {
  }
}
