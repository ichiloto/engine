<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * Chooses the corner tiles of a corner-edged terrain kind, the dual-grid
 * technique: its edges are drawn on a grid offset by half a cell, one tile
 * on each corner where four cells meet, chosen from which of those four
 * cells hold the kind (16 cases). A diagonal of cells therefore draws as one
 * continuous edge through the corners instead of a stair step, and two cells
 * that touch only at a corner stay joined.
 *
 * Every field cell still shows one whole tile: its four quarters are the
 * facing quarters of the corner tiles on its four corners. A cell beside the
 * kind, not only a cell of it, can show part of its edge. Beyond the map
 * edge each row and column continues its edge cell, so an edge never closes
 * along the map's border. Kinds that join, such as a river and the lake it
 * runs into, share one edge instead of each drawing a bank against the other.
 */
final class CornerShape
{
  /** A corner's cells, as bits of its case. */
  public const int TOP_LEFT = 1;
  public const int TOP_RIGHT = 2;
  public const int BOTTOM_LEFT = 4;
  public const int BOTTOM_RIGHT = 8;
  public const int CASES = 16;

  /**
   * Where each case's tile sits in a phase of a corner sheet, as [column,
   * row]. Every tile's corners agree with its neighbours' across the 4 x 4
   * block, its opposite edges included, so the block is one seamless picture
   * an artist paints whole: case 0 (none of the kind) at the top left, case
   * 15 (all of it) at column 2, row 2.
   */
  public const array LAYOUT = [
    0 => [0, 0], 1 => [0, 3], 2 => [1, 0], 3 => [3, 3], 4 => [3, 0], 5 => [3, 1], 6 => [0, 2], 7 => [2, 3],
    8 => [0, 1], 9 => [2, 0], 10 => [1, 3], 11 => [1, 2], 12 => [1, 1], 13 => [3, 2], 14 => [2, 1], 15 => [2, 2],
  ];

  /**
   * The corner cases of each cell a corner-edged kind reaches on one tile
   * layer: its own cells and those beside them. Each cell's four cases are
   * packed as top left | top right << 4 | bottom left << 8 | bottom right
   * << 12, the corners its four quarters come from.
   *
   * Kinds that join ({@see TerrainKind::JOINS}) share one edge: each counts
   * the others' cells as its own when it chooses a corner's case, so where a
   * river meets a lake no bank divides them. Each still draws only its own
   * cells and the cells beside them that hold none of the joined kinds, so
   * every water cell shows its own kind's water, and on the land beside a
   * junction the kinds draw the same edge, the later kind over the earlier.
   * A corner none of a kind's own cells touches is left to the kinds whose
   * cells it touches.
   *
   * @param list<list<int>> $layer Tile identities by row and cell.
   * @param list<int> $tiles The flag identities of the corner-edged kinds to draw, in draw order.
   * @param array<int, list<int>> $joins For each kind that joins others, by flag identity, the flag identities of every
   *   kind it joins, itself included ({@see Tileset::getCornerJoins()}); a kind absent here joins none.
   * @return array<int, array<int, array<int, int>>> By row, cell, then flag identity in draw order; cells no kind reaches are absent.
   */
  public static function resolveLayer(array $layer, array $tiles, array $joins): array
  {
    $height = count($layer);
    $width = max(array_map(count(...), $layer) ?: [0]);
    $flags = [];
    foreach ($layer as $y => $row) {
      foreach ($row as $x => $id) {
        if ($id !== TileId::EMPTY && TileId::isAutotile($id)) {
          $flags[$y][$x] = TileId::getFlagId($id);
        }
      }
    }
    $flagAt = static fn(int $x, int $y): ?int => $flags[min(max($y, 0), $height - 1)][min(max($x, 0), $width - 1)] ?? null;
    $resolved = [];
    foreach ($tiles as $tile) {
      $group = array_fill_keys($joins[$tile] ?? [$tile], true);
      $group[$tile] = true;
      $holds = static fn(int $x, int $y): int => (int)isset($group[$flagAt($x, $y) ?? -1]);
      $owns = static fn(int $x, int $y): bool => $flagAt($x, $y) === $tile;
      // A corner's case among the joined kinds, or none when none of this kind's own cells touch it.
      $case = static fn(int $x, int $y): int => $owns($x - 1, $y - 1) || $owns($x, $y - 1) || $owns($x - 1, $y) || $owns($x, $y)
        ? $holds($x - 1, $y - 1) | $holds($x, $y - 1) << 1 | $holds($x - 1, $y) << 2 | $holds($x, $y) << 3 : 0;
      $reached = [];
      foreach ($flags as $y => $row) {
        foreach ($row as $x => $flag) {
          if ($flag !== $tile) {
            continue;
          }
          for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
              if ($y + $dy >= 0 && $y + $dy < $height && $x + $dx >= 0 && $x + $dx < $width) {
                $reached[$y + $dy][$x + $dx] = true;
              }
            }
          }
        }
      }
      ksort($reached);
      foreach ($reached as $y => $row) {
        ksort($row);
        foreach ($row as $x => $_) {
          $flag = $flags[$y][$x] ?? null;
          if ($flag !== null && $flag !== $tile && isset($group[$flag])) {
            // A joined kind's own cell: that kind draws it.
            continue;
          }
          $cases = $case($x, $y) | $case($x + 1, $y) << 4 | $case($x, $y + 1) << 8 | $case($x + 1, $y + 1) << 12;
          if ($cases !== 0) {
            $resolved[$y][$x][$tile] = $cases;
          }
        }
      }
    }
    ksort($resolved);
    foreach ($resolved as &$row) {
      ksort($row);
    }
    return $resolved;
  }

  /**
   * Whether a tile is one of the corner-edged kinds, in any shape: its own
   * cell is drawn by its corner tiles, never by its autotile's quarters.
   *
   * @param list<int> $tiles The flag identities of the corner-edged kinds.
   */
  public static function isCornerTile(int $id, array $tiles): bool
  {
    return $id !== TileId::EMPTY && TileId::isAutotile($id) && in_array(TileId::getFlagId($id), $tiles, true);
  }
}
