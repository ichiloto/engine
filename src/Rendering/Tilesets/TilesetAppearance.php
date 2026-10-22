<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

use InvalidArgumentException;

/**
 * What a tileset's tiles show at ground level ({@see TileAppearance}), read
 * from the tileset's `appearance` key: each class lists whole sheets and tile
 * identities, as shadow casters do,
 * `['barrier' => ['C', 1544, 7], 'ground' => ['A5']]`. An autotile is named
 * by any shape of its kind.
 *
 * Only validation reads it, to compare what a map shows with what its
 * collision lets the player do. The game ignores it and it never changes
 * passage, which always comes from the map's own collision.
 *
 * A tile's class is, most specific first: its own entry; overhead for a tile
 * drawn above characters and barrier for a table; its sheet's entry; then
 * RPG Maker's sheet kinds: A1 water, A2 ground, A3 barrier, A4 ground on its
 * tops and barrier on its wall faces. A5 and the upper sheets B to E hold
 * anything, so they have no default: until a tile or its sheet is authored,
 * its class is unknown and cells showing it are not judged.
 */
final readonly class TilesetAppearance
{
  public const string DATA_KEY = 'appearance';

  /**
   * @param array<string, TileAppearance> $sheets Classes authored for whole sheets, by sheet name.
   * @param array<int, TileAppearance> $tiles Classes authored for single tiles, by flag identity (one per autotile kind).
   */
  public function __construct(public array $sheets = [], public array $tiles = [])
  {
  }

  /** @param callable(mixed, string): list<int> $readIdentities Reads a list of flag identities. */
  public static function fromArray(mixed $data, string $context, callable $readIdentities): self
  {
    if (!is_array($data) || (array_is_list($data) && $data !== [])) {
      throw new InvalidArgumentException("{$context} appearance must list sheets and tiles by class: "
        . implode(', ', array_column(TileAppearance::cases(), 'value')) . '.');
    }
    $sheets = $tiles = [];
    foreach ($data as $class => $entries) {
      $appearance = TileAppearance::tryFrom((string)$class)
        ?? throw new InvalidArgumentException("{$context} appearance: '{$class}' is not a class; use "
          . implode(', ', array_column(TileAppearance::cases(), 'value')) . '.');
      if (!is_array($entries) || !array_is_list($entries)) {
        throw new InvalidArgumentException("{$context} appearance {$class} must list sheets and tile identities.");
      }
      foreach (array_filter($entries, is_string(...)) as $name) {
        $sheet = TilesetSheet::tryFrom($name)
          ?? throw new InvalidArgumentException("{$context} appearance {$class}: '{$name}' is not an RPG Maker sheet (A1 to A5, B to E).");
        if (isset($sheets[$sheet->value]) && $sheets[$sheet->value] !== $appearance) {
          throw new InvalidArgumentException("{$context} appearance names sheet {$sheet->value} as both {$sheets[$sheet->value]->value} and {$class}.");
        }
        $sheets[$sheet->value] = $appearance;
      }
      $ids = $readIdentities(array_values(array_filter($entries, static fn(mixed $entry): bool => !is_string($entry))),
        "{$context} appearance {$class}");
      foreach ($ids as $id) {
        if (isset($tiles[$id]) && $tiles[$id] !== $appearance) {
          throw new InvalidArgumentException("{$context} appearance names tile {$id} as both {$tiles[$id]->value} and {$class}.");
        }
        $tiles[$id] = $appearance;
      }
    }
    return new self($sheets, $tiles);
  }

  /**
   * The class a tile shows, or null when it is unknown: the empty tile, or a
   * tile of A5 or an upper sheet that neither it nor its sheet names.
   */
  public function classify(int $id, Tileset $tileset): ?TileAppearance
  {
    $sheet = $id === TileId::EMPTY ? null : TileId::getSheet($id);
    if ($sheet === null) {
      return null;
    }
    return $this->tiles[TileId::getFlagId($id)]
      ?? match (true) {
        $tileset->isAbove($id) => TileAppearance::OVERHEAD,
        $tileset->isTable($id) => TileAppearance::BARRIER,
        default => $this->sheets[$sheet->value] ?? self::getSheetDefault($id, $sheet),
      };
  }

  /** RPG Maker's sheet kinds, for a tile nothing else classifies. */
  public static function getSheetDefault(int $id, TilesetSheet $sheet): ?TileAppearance
  {
    return match ($sheet) {
      TilesetSheet::A1 => TileAppearance::WATER,
      TilesetSheet::A2 => TileAppearance::GROUND,
      TilesetSheet::A3 => TileAppearance::BARRIER,
      TilesetSheet::A4 => TileId::isWall($id) ? TileAppearance::BARRIER : TileAppearance::GROUND,
      default => null,
    };
  }
}
