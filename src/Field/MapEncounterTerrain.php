<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\PackedGrid;

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
   * @param PackedGrid $grid Each cell's encounter terrain as its position in $terrains plus one; 0 for none.
   * @param list<string> $terrains Every encounter terrain the map's kinds meet.
   */
  public function __construct(private PackedGrid $grid, public array $terrains)
  {
  }

  /** The encounter terrain of a cell, or null when it has none. */
  public function getTerrainAt(int $x, int $y): ?string
  {
    $index = $this->grid->getValueAt($x, $y) ?? 0;
    return $index === 0 ? null : $this->terrains[$index - 1];
  }
}
