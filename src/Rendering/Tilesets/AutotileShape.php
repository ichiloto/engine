<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * Chooses each autotile's shape from its neighbours of the same kind, as RPG
 * Maker's editor does when painting, so authors and tools place kinds and
 * never pick shapes by hand. Beyond the map edge counts as the same kind.
 * Each field cell holds its own autotile, so neighbours are adjacent cells.
 */
final class AutotileShape
{
  /** How an autotile connects to its neighbours: floors by sides and corners, walls by sides, waterfalls across. */
  private const int FLOOR = 0;
  private const int WALL = 1;
  private const int WATERFALL = 2;
  /** The kind of a cell without an autotile, which matches no autotile's kind. */
  private const int NO_KIND = -1;

  /**
   * @param list<list<int>> $layer Tile identities by row and cell.
   * @return list<list<int>> The same layer with every autotile's shape resolved.
   */
  public static function resolveLayer(array $layer): array
  {
    return iterator_to_array(self::yieldRows($layer));
  }

  /**
   * The same shapes as {@see resolveLayer()}, one row at a time: rows are
   * read as they are needed, and only a row and the two beside it are held,
   * so a world map's layer is never held whole to be shaped. Each row's
   * kinds are read once as it arrives, so shaping a cell compares its
   * neighbours' kinds directly.
   *
   * @param iterable<int, list<int>> $rows Tile identities by row and cell, rows in order from 0.
   * @return \Generator<int, list<int>> Each row with every autotile's shape resolved, keyed by row.
   */
  public static function yieldRows(iterable $rows): \Generator
  {
    $window = $kinds = $kindOf = $connections = $floors = [];
    $emit = static function (int $y) use (&$window, &$kinds, &$connections, &$floors): array {
      $resolved = $window[$y];
      $row = $kinds[$y];
      $above = $kinds[$y - 1] ?? null;
      $below = $kinds[$y + 1] ?? null;
      foreach ($row as $x => $kind) {
        if ($kind === self::NO_KIND) {
          continue;
        }
        $id = $window[$y][$x];
        // Beyond the map edge counts as the same kind.
        $w = ($row[$x - 1] ?? $kind) === $kind;
        $e = ($row[$x + 1] ?? $kind) === $kind;
        $connection = $connections[$id] ??= match (true) {
          TileId::isWaterfall($id) => self::WATERFALL,
          TileId::isWall($id) => self::WALL,
          default => self::FLOOR,
        };
        if ($connection === self::WATERFALL) {
          $resolved[$x] = TileId::getAutotileId($kind, (int)!$w | (int)!$e << 1);
          continue;
        }
        $n = ($above[$x] ?? $kind) === $kind;
        $s = ($below[$x] ?? $kind) === $kind;
        if ($connection === self::WALL) {
          $resolved[$x] = TileId::getAutotileId($kind, (int)!$w | (int)!$n << 1 | (int)!$e << 2 | (int)!$s << 3);
          continue;
        }
        $ne = ($above[$x + 1] ?? $kind) !== $kind;
        $se = ($below[$x + 1] ?? $kind) !== $kind;
        $sw = ($below[$x - 1] ?? $kind) !== $kind;
        $nw = ($above[$x - 1] ?? $kind) !== $kind;
        // A floor's shape depends only on its eight neighbours, so each arrangement is chosen once.
        $neighbours = (int)$n | (int)$e << 1 | (int)$s << 2 | (int)$w << 3 | (int)$ne << 4 | (int)$se << 5 | (int)$sw << 6 | (int)$nw << 7;
        $resolved[$x] = TileId::getAutotileId($kind, $floors[$neighbours] ??= self::getFloorShape($n, $e, $s, $w, $ne, $se, $sw, $nw));
      }
      return $resolved;
    };
    $last = null;
    foreach ($rows as $y => $row) {
      $window[$y] = $row;
      $kinds[$y] = [];
      foreach ($row as $x => $id) {
        $kinds[$y][$x] = $kindOf[$id] ??= TileId::isAutotile($id) ? TileId::getKind($id) : self::NO_KIND;
      }
      if ($last !== null) {
        yield $last => $emit($last);
        unset($window[$last - 1], $kinds[$last - 1]);
      }
      $last = $y;
    }
    if ($last !== null) {
      yield $last => $emit($last);
    }
  }

  /**
   * Whether a cell holds an autotile of this kind; beyond the map edge does.
   *
   * @param list<list<int>> $layer
   */
  public static function isSameKind(array $layer, int $x, int $y, int $kind): bool
  {
    $neighbour = $layer[$y][$x] ?? null;
    return $neighbour === null || (TileId::isAutotile($neighbour) && TileId::getKind($neighbour) === $kind);
  }

  /** A floor's shape from whether each side continues it and whether each corner breaks it. */
  private static function getFloorShape(bool $n, bool $e, bool $s, bool $w, bool $ne, bool $se, bool $sw, bool $nw): int
  {
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
}
