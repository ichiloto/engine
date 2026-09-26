<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * Chooses each autotile's shape from its neighbours of the same kind, as RPG
 * Maker's editor does when painting, so authors and tools place kinds and
 * never pick shapes by hand. Beyond the map edge counts as the same kind.
 */
final class AutotileShape
{
  /**
   * @param list<list<int>> $layer Tile identities by row and cell.
   * @return list<list<int>> The same layer with every autotile's shape resolved.
   */
  public static function resolveLayer(array $layer): array
  {
    $resolved = $layer;
    foreach ($layer as $y => $row) {
      foreach ($row as $x => $id) {
        if (!TileId::isAutotile($id)) {
          continue;
        }
        $kind = TileId::getKind($id);
        $same = static function (int $dx, int $dy) use ($layer, $x, $y, $kind): bool {
          $neighbour = $layer[$y + $dy][$x + $dx] ?? null;
          return $neighbour === null || (TileId::isAutotile($neighbour) && TileId::getKind($neighbour) === $kind);
        };
        $resolved[$y][$x] = TileId::getAutotileId($kind, match (true) {
          TileId::isWaterfall($id) => self::getWaterfallShape($same),
          TileId::isWall($id) => self::getWallShape($same),
          default => self::getFloorShape($same),
        });
      }
    }
    return $resolved;
  }

  /** @param callable(int, int): bool $same */
  private static function getFloorShape(callable $same): int
  {
    [$n, $e, $s, $w] = [$same(0, -1), $same(1, 0), $same(0, 1), $same(-1, 0)];
    [$ne, $se, $sw, $nw] = [!$same(1, -1), !$same(1, 1), !$same(-1, 1), !$same(-1, -1)];
    return match (true) {
      $n && $e && $s && $w => (int)$nw | (int)$ne << 1 | (int)$se << 2 | (int)$sw << 3,
      !$w && $n && $e && $s => 16 + (int)$ne + ((int)$se << 1),
      !$n && $e && $s && $w => 20 + (int)$se + ((int)$sw << 1),
      !$e && $n && $s && $w => 24 + (int)$sw + ((int)$nw << 1),
      !$s && $n && $e && $w => 28 + (int)$nw + ((int)$ne << 1),
      !$w && !$e && $n && $s => 32,
      !$n && !$s && $e && $w => 33,
      !$n && !$w && $e && $s => 34 + (int)$se,
      !$n && !$e && $w && $s => 36 + (int)$sw,
      !$s && !$e && $n && $w => 38 + (int)$nw,
      !$s && !$w && $n && $e => 40 + (int)$ne,
      $s && !$n && !$w && !$e => 42,
      $e && !$n && !$w && !$s => 43,
      $n && !$w && !$e && !$s => 44,
      $w && !$n && !$e && !$s => 45,
      default => 46,
    };
  }

  /** @param callable(int, int): bool $same */
  private static function getWallShape(callable $same): int
  {
    return (int)!$same(-1, 0) | (int)!$same(0, -1) << 1 | (int)!$same(1, 0) << 2 | (int)!$same(0, 1) << 3;
  }

  /** @param callable(int, int): bool $same */
  private static function getWaterfallShape(callable $same): int
  {
    return (int)!$same(-1, 0) | (int)!$same(1, 0) << 1;
  }
}
