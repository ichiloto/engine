<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

/**
 * One resolved waymark on the active map: a point the player should head
 * for, with the objective text that explains it.
 */
final readonly class FieldWaymark
{
  /**
   * @param string $id Stable for the session while the waymark stays current.
   * @param FieldWaymarkRole $role The objective itself or the way toward it.
   * @param FieldWaymarkAnchor $anchor Where it is now.
   * @param string $label The spoiler-safe objective text.
   * @param string|null $destination For routes, the map the exit leads to.
   */
  public function __construct(
    public string $id,
    public FieldWaymarkRole $role,
    public FieldWaymarkAnchor $anchor,
    public string $label,
    public ?string $destination = null,
  )
  {
  }
}
