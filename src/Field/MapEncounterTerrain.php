<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/**
 * The encounter terrain of each cell of a map painted in terrain kinds, such
 * as `woodland` or `wetland`, resolved by {@see MapTerrainKinds::resolveEncounterTerrain()}.
 * Whether a step counts toward an encounter stays the cell's collision (an
 * encounter tile); the terrain chooses which troops can be met there, for
 * troops limited to terrains ({@see EncounterEntry::$terrains}).
 */
final readonly class MapEncounterTerrain
{
  /**
   * @param array<int, array<int, string>> $grid Encounter terrain ids by row and cell; a cell left out has none.
   * @param list<string> $terrains Every encounter terrain the map's kinds meet.
   */
  public function __construct(private array $grid, public array $terrains)
  {
  }

  /** The encounter terrain of a cell, or null when it has none. */
  public function getTerrainAt(int $x, int $y): ?string
  {
    return $this->grid[$y][$x] ?? null;
  }
}
