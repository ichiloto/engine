<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Field\Enumerations\CompassDirection;

/**
 * One place on the region map: a map, and which way its doors lead.
 *
 * @package Ichiloto\Engine\Field
 */
readonly class RegionArea
{
  /**
   * @param string $id The map's id (its path under assets/Maps).
   * @param string $name The name the map gives itself.
   * @param string $region The region it belongs to.
   * @param array<string, CompassDirection|null> $links The maps its doors lead
   * to, and which way each lies. A door in the middle of a map says nothing
   * about direction, so its value is null.
   * @param array{0: int, 1: int}|null $station Where the project wants this
   * place drawn on the region map, if it says.
   * @param string $description A line about the place, for the map screen.
   */
  public function __construct(
    public string $id,
    public string $name,
    public string $region,
    public array $links = [],
    public ?array $station = null,
    public string $description = '',
  )
  {
  }

  /**
   * Returns the ids this place's doors lead to.
   *
   * @return string[] The destination map ids.
   */
  public function destinations(): array
  {
    return array_keys($this->links);
  }
}
