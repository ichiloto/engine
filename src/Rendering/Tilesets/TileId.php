<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * RPG Maker MZ's tile identities. Upper sheets B to E hold 256 tiles each and
 * A5 holds 128 plain tiles; A1 to A4 hold autotile kinds of 48 shapes each,
 * the shape carried in the identity. Zero is the empty first tile of B.
 */
final class TileId
{
  public const int EMPTY = 0;
  public const int B = 0;
  public const int C = 256;
  public const int D = 512;
  public const int E = 768;
  public const int A5 = 1536;
  public const int A1 = 2048;
  public const int A2 = 2816;
  public const int A3 = 4352;
  public const int A4 = 5888;
  public const int MAX = 8192;
  public const int SHAPES = 48;

  public static function isValid(int $id): bool
  {
    return ($id >= self::B && $id < self::A5 + 128) || ($id >= self::A1 && $id < self::MAX);
  }

  public static function getSheet(int $id): ?TilesetSheet
  {
    return match (true) {
      !self::isValid($id) => null,
      $id >= self::A4 => TilesetSheet::A4,
      $id >= self::A3 => TilesetSheet::A3,
      $id >= self::A2 => TilesetSheet::A2,
      $id >= self::A1 => TilesetSheet::A1,
      $id >= self::A5 => TilesetSheet::A5,
      $id >= self::E => TilesetSheet::E,
      $id >= self::D => TilesetSheet::D,
      $id >= self::C => TilesetSheet::C,
      default => TilesetSheet::B,
    };
  }

  public static function isAutotile(int $id): bool
  {
    return $id >= self::A1 && $id < self::MAX;
  }

  /** The autotile kind, counted from the first A1 kind (A2 starts at 16, A3 at 48, A4 at 80). */
  public static function getKind(int $id): int
  {
    return intdiv($id - self::A1, self::SHAPES);
  }

  public static function getShape(int $id): int
  {
    return ($id - self::A1) % self::SHAPES;
  }

  public static function getAutotileId(int $kind, int $shape): int
  {
    return self::A1 + $kind * self::SHAPES + $shape;
  }

  /** The identity every shape of an autotile kind shares for flags such as `above`. */
  public static function getFlagId(int $id): int
  {
    return self::isAutotile($id) ? self::getAutotileId(self::getKind($id), 0) : $id;
  }

  public static function isWaterfall(int $id): bool
  {
    $kind = self::getKind($id);
    return self::getSheet($id) === TilesetSheet::A1 && $kind >= 4 && $kind % 2 === 1;
  }

  /** Building walls (A3) and wall sides (A4's odd kind rows) connect by edges only. */
  public static function isWall(int $id): bool
  {
    return match (self::getSheet($id)) {
      TilesetSheet::A3 => true,
      TilesetSheet::A4 => intdiv(self::getKind($id), 8) % 2 === 1,
      default => false,
    };
  }
}
