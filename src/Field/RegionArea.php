<?php

namespace Ichiloto\Engine\Field;

/**
 * One place on the region map: a map, and what it leads to.
 *
 * @package Ichiloto\Engine\Field
 */
readonly class RegionArea
{
  /**
   * @param string $id The map's id (its path under assets/Maps).
   * @param string $name The name the map gives itself.
   * @param string $region The region it belongs to.
   * @param string[] $links The ids of the maps it leads to.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $region,
    public array $links = [],
  )
  {
  }
}
