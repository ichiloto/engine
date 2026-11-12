<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Ichiloto\Engine\Core\Vector2;

/**
 * Where a waymark subject currently is on the active map.
 *
 * Moving subjects (NPCs, world objects) carry their sprite identity so
 * graphical markers follow them; events carry their marker so their own
 * cue can stand in for the waymark while both are visible.
 */
final readonly class FieldWaymarkAnchor
{
  public function __construct(
    public Vector2 $cell,
    public ?string $objectId = null,
    public ?string $eventMarker = null,
  )
  {
  }
}
