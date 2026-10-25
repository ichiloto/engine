<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Closure;
use Ichiloto\Engine\Core\PackedGrid;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * The terrain kinds a map is painted in ({@see TerrainKind}), as the map's
 * gameplay reads them. A map opts in explicitly with `'terrainKinds' => true`
 * in its data file, which the Editor's conversion writes; a map without it is
 * never reinterpreted by the kinds its tileset declares, so authoring kinds
 * changes no existing map.
 *
 * Kinds are not another collision authority. On a map without declared
 * occupancy they feed the existing glyph dictionary: each kind's layer gets a
 * section holding its kinds' glyphs and the collision their passage and
 * encounters give ({@see composeDictionary()}), resolved by the same
 * topmost-wins walk as every other glyph. A section `collisions.php` authors
 * for that layer may repeat a kind's glyph only with the same collision. On a
 * map with declared occupancy, the occupancy stays the only passage source;
 * editors write a kind's collision into it when they paint the kind.
 * Encounter terrain and atlas roles come from the same kinds through the same
 * glyph walk ({@see resolveEncounterTerrain()}, {@see composeAtlasTerrain()}).
 */
final readonly class MapTerrainKinds
{
  /** The map data key that opts a map into its tileset's terrain kinds. */
  public const string DATA_KEY = 'terrainKinds';

  /** @var array<string, array<string, list<TerrainKind>>> Kinds by gameplay layer name, then plain glyph. */
  private array $bySymbol;

  public function __construct(public Tileset $tileset)
  {
    if ($tileset->kinds === []) {
      throw new InvalidArgumentException("Tileset {$tileset->id} has no terrain kinds to paint a map in.");
    }
    $bySymbol = [];
    foreach ($tileset->kinds as $kind) {
      $bySymbol[$kind->layer][$kind->symbol][] = $kind;
    }
    $this->bySymbol = $bySymbol;
  }

  /**
   * Whether a map's data opts it into its tileset's terrain kinds.
   *
   * @throws InvalidArgumentException When the declaration is not `true`, or the map names no tileset.
   */
  public static function isDeclared(array $data): bool
  {
    if (!array_key_exists(self::DATA_KEY, $data)) {
      return false;
    }
    if ($data[self::DATA_KEY] !== true) {
      throw new InvalidArgumentException("A map's terrainKinds is true, or absent when the map is not painted in terrain kinds.");
    }
    if (!is_string($data['tileset'] ?? null)) {
      throw new InvalidArgumentException('A map painted in terrain kinds names the tileset that declares them.');
    }
    return true;
  }

  /**
   * The kinds a map is painted in, read from its tileset; null when the map
   * does not opt in. A map that opts in and whose tileset cannot be read, or
   * declares no kinds, is refused: its passage would otherwise be unknown.
   *
   * @throws InvalidArgumentException
   */
  public static function loadForMap(string $assetRoot, array $data): ?self
  {
    if (!self::isDeclared($data)) {
      return null;
    }
    try {
      $tileset = Tileset::load($assetRoot, $data['tileset']);
    } catch (Throwable $error) {
      throw new InvalidArgumentException("The map is painted in terrain kinds, but tileset {$data['tileset']} cannot be read, so its passage is unknown: "
        . $error->getMessage(), previous: $error);
    }
    return new self($tileset);
  }

  /**
   * Refuses a resolution that would ignore a map's kinds: a map that opts in
   * is only ever resolved with the kinds of the tileset it names.
   */
  public static function assertSupplied(array $data, ?self $kinds, string $purpose): void
  {
    if (self::isDeclared($data) ? $kinds?->tileset->id !== $data['tileset'] : $kinds !== null) {
      throw new LogicException(self::isDeclared($data)
        ? "{$purpose} of a map painted in terrain kinds needs the kinds of tileset {$data['tileset']}."
        : "{$purpose} of a map not painted in terrain kinds must not apply kinds.");
    }
  }

  /** @return list<TerrainKind> The kinds a plain glyph names on a gameplay layer: none, one, or several alike in play. */
  public function getKindsOn(string $layer, string $symbol): array
  {
    return $this->bySymbol[$layer][$symbol] ?? [];
  }

  /** @return list<string> The gameplay layers kinds are painted on. */
  public function getLayers(): array
  {
    return array_map(strval(...), array_keys($this->bySymbol));
  }

  /** @return list<string> The tile layers kinds paint: their own autotiles' layers and their grounds'. */
  public function getTileLayers(): array
  {
    $layers = [];
    foreach ($this->tileset->kinds as $kind) {
      foreach (array_keys($kind->piece->tiles) as $layer) {
        $layers[$layer] = true;
      }
    }
    return array_map(strval(...), array_keys($layers));
  }

  /**
   * The kind a cell shows in both presentations: one its glyph names whose
   * tiles are in place, its autotile (in any shape) and its ground's, with
   * nothing on the other tile layers kinds paint except layers it keeps.
   * Null when the glyph names no kind, or no kind it names has its tiles there.
   *
   * @param Closure(string): int $tileAt The cell's tile on a tile layer; empty when the map has no such layer.
   */
  public function findKindAt(string $layer, string $glyph, Closure $tileAt): ?TerrainKind
  {
    foreach ($this->getKindsOn($layer, TerminalText::stripAnsi($glyph)) as $kind) {
      if ($this->hasTilesOf($kind, $tileAt)) {
        return $kind;
      }
    }
    return null;
  }

  /**
   * The kind whose own autotile a cell shows, whatever its glyph, or null.
   *
   * @param Closure(string): int $tileAt
   */
  public function findKindByTile(Closure $tileAt): ?TerrainKind
  {
    foreach ($this->tileset->kinds as $kind) {
      if ($kind->art !== null && $kind->isOwnTile($tileAt($kind->art['layer']))) {
        return $kind;
      }
    }
    return null;
  }

  /** @param Closure(string): int $tileAt */
  private function hasTilesOf(TerrainKind $kind, Closure $tileAt): bool
  {
    foreach ($this->getTileLayers() as $tileLayer) {
      if (in_array($tileLayer, $kind->keeps, true)) {
        continue;
      }
      $expected = (int)($kind->piece->tiles[$tileLayer][0][0] ?? TileId::EMPTY);
      $shown = $tileAt($tileLayer);
      $same = $expected === TileId::EMPTY
        ? $shown === TileId::EMPTY
        : $shown !== TileId::EMPTY && TileId::isAutotile($shown) && TileId::getFlagId($shown) === TileId::getFlagId($expected);
      if (!$same) {
        return false;
      }
    }
    return true;
  }

  /** @return list<string> Every encounter terrain a kind meets, in authored order. */
  public function getEncounterTerrains(): array
  {
    return array_values(array_unique(array_filter(array_map(static fn(TerrainKind $kind): ?string => $kind->encounters,
      array_values($this->tileset->kinds)))));
  }

  /**
   * The glyph dictionary with each kind layer's section holding its kinds'
   * collision. A named section already overrides the flat dictionary for its
   * layer, so a kind decides its glyph there whatever the flat entry says.
   *
   * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary
   * @return array<int|string, CollisionType|array<int|string, CollisionType>>
   * @throws InvalidArgumentException When the dictionary's own section for a kind layer gives a kind's glyph another collision.
   */
  public function composeDictionary(array $dictionary): array
  {
    foreach ($this->bySymbol as $layer => $symbols) {
      $section = $dictionary[$layer] ?? [];
      if (!is_array($section)) {
        throw new InvalidArgumentException("The collision dictionary names {$layer} as a glyph, but terrain kinds of tileset {$this->tileset->id} are painted on a layer of that name.");
      }
      foreach ($symbols as $symbol => $kinds) {
        $collision = $kinds[0]->collision;
        $authored = $section[$symbol] ?? null;
        if ($authored !== null && $authored !== $collision) {
          throw new InvalidArgumentException(sprintf("The collision dictionary's %s section gives %s %s, but terrain kind %s of tileset %s gives it %s. A kind owns its glyph's passage on its layer; remove the entry.",
            $layer, $symbol, $authored->name, $kinds[0]->id, $this->tileset->id, $collision->name));
        }
        $section[$symbol] = $collision;
      }
      $dictionary[$layer] = $section;
    }
    return $dictionary;
  }

  /**
   * Each cell's encounter terrain, by the glyph walk collision uses: from the
   * top gameplay layer down, skipping upper spaces and pass-through glyphs, the
   * first glyph decides. A kind's glyph gives the kind's encounter terrain;
   * any other glyph gives none.
   *
   * @param array<int|string, CollisionType|array<int|string, CollisionType>> $dictionary The composed dictionary.
   */
  public function resolveEncounterTerrain(MapLayerSet $layers, array $dictionary): MapEncounterTerrain
  {
    $flat = array_filter($dictionary, static fn(mixed $value): bool => $value instanceof CollisionType);
    $gameplay = [];
    foreach ($layers->layers as $layer) {
      if (!$layer->decoration) {
        $section = $dictionary[$layer->name] ?? [];
        $gameplay[] = [$layer, (is_array($section) ? $section : []) + $flat];
      }
    }
    $terrains = $this->getEncounterTerrains();
    $terrainIndices = array_flip($terrains);
    $base = $gameplay[0][0] ?? null;
    $rows = [];
    for ($y = 0; $y < ($base?->height ?? 0); $y++) {
      $row = [];
      for ($x = 0, $width = $base->getRowLength($y); $x < $width; $x++) {
        $row[$x] = 0;
        for ($index = count($gameplay) - 1; $index >= 0; $index--) {
          [$layer, $types] = $gameplay[$index];
          $glyph = $layer->getGlyphAt($x, $y) ?? ' ';
          if ($index !== 0 && $glyph === ' ') {
            continue;
          }
          $kinds = $this->getKindsOn($layer->name, $glyph);
          if ($kinds !== []) {
            if ($kinds[0]->encounters !== null) {
              $row[$x] = $terrainIndices[$kinds[0]->encounters] + 1;
            }
            break;
          }
          if (MapCollisionResolver::lookUp($types, $glyph) !== CollisionType::PASS_THROUGH) {
            break;
          }
        }
      }
      $rows[] = $row;
    }
    return new MapEncounterTerrain(PackedGrid::fromRows($rows), $terrains);
  }

  /**
   * An atlas terrain declaration with each kind layer's glyphs bound to their
   * kinds' atlas roles. A binding the map authors for that layer may repeat a
   * kind's glyph only with the same role. Kind layers the map does not have
   * bind nothing.
   *
   * @param array<string, array{symbols?: array<string, string>, default?: string}> $declaration Validated ({@see MapAtlasTerrain::validateDeclaration()}).
   * @param list<string> $layerNames The map's gameplay layer names.
   * @return array<string, array{symbols?: array<string, string>, default?: string}>
   * @throws InvalidArgumentException
   */
  public function composeAtlasTerrain(array $declaration, array $layerNames): array
  {
    foreach ($this->bySymbol as $layer => $symbols) {
      if (!in_array($layer, $layerNames, true)) {
        continue;
      }
      $binding = $declaration[$layer] ?? [];
      $bound = $binding['symbols'] ?? [];
      foreach ($symbols as $symbol => $kinds) {
        $role = $kinds[0]->atlas;
        if (isset($bound[$symbol]) && $bound[$symbol] !== $role) {
          throw new InvalidArgumentException(sprintf("The map's atlas binds %s on %s as %s, but terrain kind %s of tileset %s shows as %s. A kind owns its glyph's atlas role; remove the binding.",
            $symbol, $layer, $bound[$symbol], $kinds[0]->id, $this->tileset->id, $role));
        }
        $bound[(string)$symbol] = $role;
      }
      $binding['symbols'] = $bound;
      $declaration[$layer] = $binding;
    }
    return $declaration;
  }
}
