<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * Decides what each field cell of a tile layer shows. An autotile is
 * composed for its own cell, from its neighbouring cells, and the cell shows
 * the quarter column facing the edge it borders: the left half at a west
 * edge, the right half at an east edge, both outer halves when it borders
 * both, and otherwise the half of its column's parity (left on even
 * columns), so a texture keeps one phase across every row. Any
 * other tile shows what its entry names: the whole tile centred on the cell,
 * or its left or right half.
 */
final class CellTiles
{
  /**
   * @param list<list<int>> $tiles Tile identities by row and cell.
   * @param array<int, array<int, TileSlice>> $halves Halves named for plain tiles.
   * @return array<int, array<int, array{int, TileSlice}>> Each painted cell's tile identity and slice.
   */
  public static function resolveLayer(array $tiles, array $halves = []): array
  {
    $shaped = AutotileShape::resolveLayer($tiles);
    $cells = [];
    foreach ($tiles as $y => $row) {
      foreach ($row as $x => $id) {
        if ($id === TileId::EMPTY) {
          continue;
        }
        $cells[$y][$x] = [$shaped[$y][$x], TileId::isAutotile($id)
          ? self::getAutotileSlice($tiles, $x, $y)
          : ($halves[$y][$x] ?? TileSlice::WHOLE)];
      }
    }
    return $cells;
  }

  /** @param list<list<int>> $tiles */
  private static function getAutotileSlice(array $tiles, int $x, int $y): TileSlice
  {
    $id = $tiles[$y][$x];
    $kind = TileId::getKind($id);
    $same = static fn(int $dx, int $dy): bool => AutotileShape::isSameKind($tiles, $x + $dx, $y + $dy, $kind);
    // Floors also turn inner corners, where a diagonal differs but both sides
    // beside it match; walls only have sides.
    $corners = !TileId::isWall($id) && !TileId::isWaterfall($id);
    $inner = static fn(int $dx): bool => $corners
      && (($same(0, -1) && !$same($dx, -1)) || ($same(0, 1) && !$same($dx, 1)));
    $west = !$same(-1, 0) || $inner(-1);
    $east = !$same(1, 0) || $inner(1);
    if ($west || $east) {
      return $west && $east ? TileSlice::NARROW : ($west ? TileSlice::LEFT : TileSlice::RIGHT);
    }
    return $x % 2 === 0 ? TileSlice::LEFT : TileSlice::RIGHT;
  }
}
