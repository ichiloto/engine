<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/**
 * Composes a tile identity from its sheet, exactly as RPG Maker MZ draws it:
 * plain tiles copy one tile, autotiles assemble four quarter tiles chosen
 * by the shape carried in the identity, and A1 water animates.
 */
final class TileComposer
{
  /** Water surface frames over one animation cycle. */
  public const array WATER_FRAMES = [0, 1, 2, 1];
  public const int WATERFALL_FRAMES = 3;

  /** Source quarter positions for each floor shape, in destination order: top left, top right, bottom left, bottom right. */
  private const array FLOOR = [
    [[2, 4], [1, 4], [2, 3], [1, 3]], [[2, 0], [1, 4], [2, 3], [1, 3]], [[2, 4], [3, 0], [2, 3], [1, 3]], [[2, 0], [3, 0], [2, 3], [1, 3]],
    [[2, 4], [1, 4], [2, 3], [3, 1]], [[2, 0], [1, 4], [2, 3], [3, 1]], [[2, 4], [3, 0], [2, 3], [3, 1]], [[2, 0], [3, 0], [2, 3], [3, 1]],
    [[2, 4], [1, 4], [2, 1], [1, 3]], [[2, 0], [1, 4], [2, 1], [1, 3]], [[2, 4], [3, 0], [2, 1], [1, 3]], [[2, 0], [3, 0], [2, 1], [1, 3]],
    [[2, 4], [1, 4], [2, 1], [3, 1]], [[2, 0], [1, 4], [2, 1], [3, 1]], [[2, 4], [3, 0], [2, 1], [3, 1]], [[2, 0], [3, 0], [2, 1], [3, 1]],
    [[0, 4], [1, 4], [0, 3], [1, 3]], [[0, 4], [3, 0], [0, 3], [1, 3]], [[0, 4], [1, 4], [0, 3], [3, 1]], [[0, 4], [3, 0], [0, 3], [3, 1]],
    [[2, 2], [1, 2], [2, 3], [1, 3]], [[2, 2], [1, 2], [2, 3], [3, 1]], [[2, 2], [1, 2], [2, 1], [1, 3]], [[2, 2], [1, 2], [2, 1], [3, 1]],
    [[2, 4], [3, 4], [2, 3], [3, 3]], [[2, 4], [3, 4], [2, 1], [3, 3]], [[2, 0], [3, 4], [2, 3], [3, 3]], [[2, 0], [3, 4], [2, 1], [3, 3]],
    [[2, 4], [1, 4], [2, 5], [1, 5]], [[2, 0], [1, 4], [2, 5], [1, 5]], [[2, 4], [3, 0], [2, 5], [1, 5]], [[2, 0], [3, 0], [2, 5], [1, 5]],
    [[0, 4], [3, 4], [0, 3], [3, 3]], [[2, 2], [1, 2], [2, 5], [1, 5]], [[0, 2], [1, 2], [0, 3], [1, 3]], [[0, 2], [1, 2], [0, 3], [3, 1]],
    [[2, 2], [3, 2], [2, 3], [3, 3]], [[2, 2], [3, 2], [2, 1], [3, 3]], [[2, 4], [3, 4], [2, 5], [3, 5]], [[2, 0], [3, 4], [2, 5], [3, 5]],
    [[0, 4], [1, 4], [0, 5], [1, 5]], [[0, 4], [3, 0], [0, 5], [1, 5]], [[0, 2], [3, 2], [0, 3], [3, 3]], [[0, 2], [1, 2], [0, 5], [1, 5]],
    [[0, 4], [3, 4], [0, 5], [3, 5]], [[2, 2], [3, 2], [2, 5], [3, 5]], [[0, 2], [3, 2], [0, 5], [3, 5]], [[0, 0], [1, 0], [0, 1], [1, 1]],
  ];

  private const array WALL = [
    [[2, 2], [1, 2], [2, 1], [1, 1]], [[0, 2], [1, 2], [0, 1], [1, 1]], [[2, 0], [1, 0], [2, 1], [1, 1]], [[0, 0], [1, 0], [0, 1], [1, 1]],
    [[2, 2], [3, 2], [2, 1], [3, 1]], [[0, 2], [3, 2], [0, 1], [3, 1]], [[2, 0], [3, 0], [2, 1], [3, 1]], [[0, 0], [3, 0], [0, 1], [3, 1]],
    [[2, 2], [1, 2], [2, 3], [1, 3]], [[0, 2], [1, 2], [0, 3], [1, 3]], [[2, 0], [1, 0], [2, 3], [1, 3]], [[0, 0], [1, 0], [0, 3], [1, 3]],
    [[2, 2], [3, 2], [2, 3], [3, 3]], [[0, 2], [3, 2], [0, 3], [3, 3]], [[2, 0], [3, 0], [2, 3], [3, 3]], [[0, 0], [3, 0], [0, 3], [3, 3]],
  ];

  private const array WATERFALL = [
    [[2, 0], [1, 0], [2, 1], [1, 1]], [[0, 0], [1, 0], [0, 1], [1, 1]],
    [[2, 0], [3, 0], [2, 1], [3, 1]], [[0, 0], [3, 0], [0, 1], [3, 1]],
  ];

  /**
   * The tile's animation frames, each the pieces copied into one tile.
   *
   * @param bool $table Whether an A2 autotile is drawn as a table (its tileset lists it in `tables`).
   * @return list<list<TilePiece>>
   */
  public static function compose(int $id, int $tileSize, bool $table = false): array
  {
    $sheet = TileId::getSheet($id);
    if ($sheet === null || $id === TileId::EMPTY) {
      throw new InvalidArgumentException("Tile {$id} is not a drawable RPG Maker tile identity.");
    }
    if ($tileSize < 2 || $tileSize % 2 !== 0) {
      throw new InvalidArgumentException('Tiles are composed from quarter tiles, so their size must be even.');
    }
    if (!TileId::isAutotile($id)) {
      // Upper sheets and A5: two halves of 8 columns, 128 tiles each.
      $x = (intdiv($id, 128) % 2 * 8 + $id % 8) * $tileSize;
      $y = intdiv($id % 256, 8) % 16 * $tileSize;
      return [[new TilePiece($sheet, $x, $y, $tileSize, $tileSize, 0, 0)]];
    }
    $kind = TileId::getKind($id);
    $shape = TileId::getShape($id);
    $column = $kind % 8;
    $row = intdiv($kind, 8);
    return match ($sheet) {
      TilesetSheet::A1 => self::composeWater($sheet, $kind, $column, $row, $shape, $tileSize),
      TilesetSheet::A2 => [self::composeQuarters($sheet, $column * 2, ($row - 2) * 3, self::FLOOR[$shape], $tileSize, $table)],
      TilesetSheet::A3 => [self::composeQuarters($sheet, $column * 2, ($row - 6) * 2, self::WALL[$shape % 16], $tileSize)],
      default => [self::composeQuarters($sheet, $column * 2, intdiv(($row - 10) * 5 + ($row % 2), 2),
        $row % 2 === 1 ? self::WALL[$shape % 16] : self::FLOOR[$shape], $tileSize)],
    };
  }

  /** @return list<list<TilePiece>> */
  private static function composeWater(TilesetSheet $sheet, int $kind, int $column, int $row, int $shape, int $tileSize): array
  {
    if ($kind === 2 || $kind === 3) {
      return [self::composeQuarters($sheet, 6, $kind === 2 ? 0 : 3, self::FLOOR[$shape], $tileSize)];
    }
    $frames = [];
    if ($kind === 0 || $kind === 1) {
      foreach (self::WATER_FRAMES as $surface) {
        $frames[] = self::composeQuarters($sheet, $surface * 2, $kind === 0 ? 0 : 3, self::FLOOR[$shape], $tileSize);
      }
      return $frames;
    }
    $blockX = intdiv($column, 4) * 8;
    $blockY = $row * 6 + intdiv($column, 2) % 2 * 3;
    if ($kind % 2 === 0) {
      foreach (self::WATER_FRAMES as $surface) {
        $frames[] = self::composeQuarters($sheet, $blockX + $surface * 2, $blockY, self::FLOOR[$shape], $tileSize);
      }
      return $frames;
    }
    for ($frame = 0; $frame < self::WATERFALL_FRAMES; $frame++) {
      $frames[] = self::composeQuarters($sheet, $blockX + 6, $blockY + $frame, self::WATERFALL[$shape % 4], $tileSize);
    }
    return $frames;
  }

  /**
   * Four quarter tiles from the autotile block at ($blockX, $blockY), in tiles.
   *
   * @param list<array{int, int}> $quarters
   * @return list<TilePiece>
   */
  private static function composeQuarters(TilesetSheet $sheet, int $blockX, int $blockY, array $quarters, int $tileSize, bool $table = false): array
  {
    $half = intdiv($tileSize, 2);
    $pieces = [];
    foreach ($quarters as $index => [$quarterX, $quarterY]) {
      $left = $index % 2 * $half;
      $top = intdiv($index, 2) * $half;
      $x = ($blockX * 2 + $quarterX) * $half;
      $y = ($blockY * 2 + $quarterY) * $half;
      if ($table && ($quarterY === 1 || $quarterY === 5)) {
        // A table's legs: the leg quarter beneath, then the top's upper half over it.
        $legX = $quarterY === 1 ? (4 - $quarterX) % 4 : $quarterX;
        $pieces[] = new TilePiece($sheet, ($blockX * 2 + $legX) * $half, ($blockY * 2 + 3) * $half, $half, $half, $left, $top);
        $pieces[] = new TilePiece($sheet, $x, $y, $half, intdiv($half, 2), $left, $top + intdiv($half, 2));
        continue;
      }
      $pieces[] = new TilePiece($sheet, $x, $y, $half, $half, $left, $top);
    }
    return $pieces;
  }
}
