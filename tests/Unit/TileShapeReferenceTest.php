<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TileId;

/**
 * The shapes as the rules state them, cell by cell, without any of the
 * shared readers' bookkeeping: what the row-at-a-time shapers must equal
 * on any layer.
 */
final class TileShapeReference
{
  /** @param list<list<int>> $layer */
  public static function resolveAutotiles(array $layer): array
  {
    $resolved = $layer;
    foreach ($layer as $y => $row) {
      foreach ($row as $x => $id) {
        if (!TileId::isAutotile($id)) { continue; }
        $kind = TileId::getKind($id);
        $same = static function (int $dx, int $dy) use ($layer, $x, $y, $kind): bool {
          $neighbour = $layer[$y + $dy][$x + $dx] ?? null;
          return $neighbour === null || (TileId::isAutotile($neighbour) && TileId::getKind($neighbour) === $kind);
        };
        [$n, $e, $s, $w] = [$same(0, -1), $same(1, 0), $same(0, 1), $same(-1, 0)];
        [$ne, $se, $sw, $nw] = [!$same(1, -1), !$same(1, 1), !$same(-1, 1), !$same(-1, -1)];
        $resolved[$y][$x] = TileId::getAutotileId($kind, match (true) {
          TileId::isWaterfall($id) => (int)!$w | (int)!$e << 1,
          TileId::isWall($id) => (int)!$w | (int)!$n << 1 | (int)!$e << 2 | (int)!$s << 3,
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
        });
      }
    }
    return $resolved;
  }

  /**
   * @param list<list<int>> $layer
   * @param list<int> $tiles
   * @param array<int, list<int>> $joins
   */
  public static function resolveCorners(array $layer, array $tiles, array $joins): array
  {
    $height = count($layer);
    $width = max(array_map(count(...), $layer) ?: [0]);
    // Beyond the map edge each row and column continues its edge cell.
    $flagAt = static function (int $x, int $y) use ($layer, $height, $width): ?int {
      $id = $layer[min(max($y, 0), $height - 1)][min(max($x, 0), $width - 1)] ?? TileId::EMPTY;
      return $id !== TileId::EMPTY && TileId::isAutotile($id) ? TileId::getFlagId($id) : null;
    };
    $resolved = [];
    foreach (array_values(array_unique($tiles)) as $tile) {
      $group = array_fill_keys([...($joins[$tile] ?? []), $tile], true);
      $holds = static fn(int $x, int $y): int => (int)isset($group[$flagAt($x, $y) ?? -1]);
      $owns = static fn(int $x, int $y): bool => $flagAt($x, $y) === $tile;
      $case = static fn(int $x, int $y): int => $owns($x - 1, $y - 1) || $owns($x, $y - 1) || $owns($x - 1, $y) || $owns($x, $y)
        ? $holds($x - 1, $y - 1) | $holds($x, $y - 1) << 1 | $holds($x - 1, $y) << 2 | $holds($x, $y) << 3 : 0;
      for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
          $reached = false;
          for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
              $reached = $reached || (isset($layer[$y + $dy][$x + $dx]) && $flagAt($x + $dx, $y + $dy) === $tile);
            }
          }
          $flag = isset($layer[$y][$x]) ? $flagAt($x, $y) : null;
          if (!$reached || ($flag !== null && $flag !== $tile && isset($group[$flag]))) { continue; }
          $cases = $case($x, $y) | $case($x + 1, $y) << 4 | $case($x, $y + 1) << 8 | $case($x + 1, $y + 1) << 12;
          if ($cases !== 0) { $resolved[$y][$x][$tile] = $cases; }
        }
      }
    }
    ksort($resolved);
    foreach ($resolved as $y => $row) { ksort($resolved[$y]); }
    return $resolved;
  }

  /** A seeded layer mixing floors, walls, waterfalls, plain tiles and gaps, with ragged rows. */
  public static function createLayer(int $seed, int $width, int $height, array $ids): array
  {
    mt_srand($seed);
    $layer = [];
    for ($y = 0; $y < $height; $y++) {
      $row = [];
      for ($x = 0, $length = $width - ($y % 5 === 4 ? mt_rand(0, 2) : 0); $x < $length; $x++) {
        // Runs of one identity, so kinds form regions with edges, corners and single cells.
        $row[] = $x > 0 && mt_rand(0, 2) > 0 ? $row[$x - 1] : $ids[mt_rand(0, count($ids) - 1)];
      }
      $layer[] = $row;
    }
    return $layer;
  }
}

it('shapes every autotile as its neighbours rule, row by row', function (int $seed) {
  // A2 floors of three kinds, an A1 waterfall, A3 and A4 walls, an A5 and a B tile, and gaps.
  $ids = [0, 0, 2816, 2816 + 48, 2816 + 96 + 5, 2048 + 5 * 48, 4352 + 3, 5888 + 8 * 48, 5888, 1536 + 4, 7];
  $layer = TileShapeReference::createLayer($seed, 37, 23, $ids);
  expect(AutotileShape::resolveLayer($layer))->toBe(TileShapeReference::resolveAutotiles($layer));
})->with([1, 2, 3, 4]);

it('chooses every corner-edged cell its cases as the corner rule states, joined kinds included', function (int $seed) {
  $sea = 2096;
  $river = 2048 + 2 * 48;
  $sand = 2864;
  $ids = [0, 2816, $sea, $sea, $river, $sand, $sand, 4352 + 3];
  $layer = TileShapeReference::createLayer($seed, 31, 19, $ids);
  $tiles = [$sand, $sea, $river];
  $joins = [$sea => [$sea, $river], $river => [$sea, $river]];
  $flag = static fn(int $id): int => TileId::getFlagId($id);
  $tiles = array_map($flag, $tiles);
  $joins = [$flag($sea) => [$flag($sea), $flag($river)], $flag($river) => [$flag($sea), $flag($river)]];
  $resolved = CornerShape::resolveLayer($layer, $tiles, $joins);
  ksort($resolved);
  foreach ($resolved as $y => $row) { ksort($resolved[$y]); }
  expect($resolved)->toBe(TileShapeReference::resolveCorners($layer, $tiles, $joins));
})->with([1, 2, 3, 4]);
