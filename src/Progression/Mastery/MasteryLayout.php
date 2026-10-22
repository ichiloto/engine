<?php

namespace Ichiloto\Engine\Progression\Mastery;

/**
 * Where a graphical map places each node, as fractions (0 to 1) of the map's
 * width and height: the node's authored position, or else a place in its
 * region. Regions share the map as a grid in the project's order; a region's
 * unplaced nodes stand in the order the map lists them, in one column, or two once there are
 * three or more, spread down the region. Every renderer and editor that draws
 * a map reads these places, so the map an author arranges is the map the
 * player sees.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryLayout
{
  /** How many columns a region's unplaced nodes share once there are three or more. */
  public const int REGION_COLUMNS = 2;

  private function __construct()
  {
  }

  /**
   * @param list<string> $regionIds The project's regions, in map order.
   * @param list<string>|null $nodeIds The nodes drawn, when a view draws only some of the map; all of them otherwise.
   * @return array<string, array{x: float, y: float, placed: bool}> Each drawn node's place, by node id.
   */
  public static function place(MasteryMap $map, array $regionIds, ?array $nodeIds = null): array
  {
    $grid = self::getGrid(count($regionIds));
    $nodes = $nodeIds === null ? $map->nodes : array_intersect_key($map->nodes, array_flip($nodeIds));
    $byRegion = [];
    foreach ($nodes as $node) {
      if ($node->position === null) {
        $byRegion[$node->region][] = $node->id;
      }
    }
    $places = [];
    foreach ($nodes as $node) {
      if ($node->position !== null) {
        $places[$node->id] = [...$node->position, 'placed' => true];
        continue;
      }
      $region = max(0, (int)array_search($node->region, $regionIds, true));
      $column = $region % $grid['columns'];
      $row = intdiv($region, $grid['columns']);
      $siblings = $byRegion[$node->region];
      $slot = (int)array_search($node->id, $siblings, true);
      $innerColumns = count($siblings) > 2 ? self::REGION_COLUMNS : 1;
      $innerRows = (int)ceil(count($siblings) / $innerColumns);
      $places[$node->id] = [
        'x' => ($column + ($slot % $innerColumns + 0.5) / $innerColumns) / $grid['columns'],
        'y' => ($row + (intdiv($slot, $innerColumns) + 1) / ($innerRows + 1)) / $grid['rows'],
        'placed' => false,
      ];
    }

    return $places;
  }

  /**
   * The grid the regions share: as square as their count allows.
   *
   * @return array{columns: int, rows: int}
   */
  public static function getGrid(int $regions): array
  {
    $columns = max(1, (int)ceil(sqrt(max(1, $regions))));

    return ['columns' => $columns, 'rows' => max(1, (int)ceil(max(1, $regions) / $columns))];
  }
}
