<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Throwable;

/**
 * Which arena art covers every random battle a map can start: for each way
 * its encounter block decides the arena ({@see EncounterTable::measureArenaUses()}),
 * the arena named, the encounter terrain, and the arena the presentation
 * draws, with whether that arena lacks a variant for the terrain. The Editor
 * validates with it and a game's own tests can hold its maps to it; neither
 * restates the rules.
 */
final readonly class EncounterArenaCoverage
{
  /**
   * @param list<array{scope: string, terrain: ?string, arena: ?string, drawn: ?string, missingVariant: bool, problem: ?string, cells: int, troops: list<string>}> $rows
   */
  private function __construct(public array $rows)
  {
  }

  /**
   * Measures one map.
   *
   * @param int[][] $collisions The map's resolved collision grid.
   * @param BattlePresentationCatalog|null $catalog The project's battle presentation, or null when it draws no arenas.
   * @param (callable(int, int): bool)|null $isReachable Whether the player can stand on a cell; null counts every cell.
   */
  public static function measure(EncounterTable $table, array $collisions, ?BattlePresentationCatalog $catalog,
    ?callable $isReachable = null): self
  {
    $rows = [];
    foreach ($table->measureArenaUses($collisions, $isReachable) as ['use' => $use, 'cells' => $cells, 'troops' => $troops]) {
      $named = $use->settings[BattlePresentationCatalog::ARENA_SETTING] ?? null;
      $arena = is_string($named) ? $named : $catalog?->defaultArena;
      $terrain = $use->settings[BattlePresentationCatalog::TERRAIN_SETTING] ?? null;
      $drawn = null;
      $missing = false;
      $problem = null;
      if ($catalog !== null && $arena !== null) {
        try {
          $choice = $catalog->resolveArenaVariant($arena, $terrain);
          [$drawn, $missing] = [$choice->key, $choice->isMissingVariant];
        } catch (Throwable $error) {
          $problem = $error->getMessage();
        }
      }
      $rows[] = ['scope' => $use->scope, 'terrain' => $use->terrain, 'arena' => $arena, 'drawn' => $drawn,
        'missingVariant' => $missing, 'problem' => $problem, 'cells' => $cells, 'troops' => $troops];
    }
    return new self($rows);
  }

  /**
   * Measures one map as the game reads it from its files: its collision and
   * encounter terrain resolved as the field resolves them. Null for a map
   * that declares no encounters.
   *
   * @param string $assetRoot The project's `assets` directory.
   * @param MapReachabilityReport|null $reachability Where the player can walk on the map; null counts every cell.
   */
  public static function measureMapFiles(string $assetRoot, string $mapId, ?BattlePresentationCatalog $catalog,
    ?MapReachabilityReport $reachability = null): ?self
  {
    $mapsDirectory = $assetRoot . '/Maps';
    $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($mapsDirectory, $mapId));
    $data = $source['data'];
    if (!is_array($data['encounters'] ?? null)) {
      return null;
    }
    $dictionary = array_key_exists(MapPhysicalOccupancy::DATA_KEY, $data) ? []
      : MapSourceReader::loadCollisionDictionary($mapsDirectory . '/collisions.php');
    $kinds = MapTerrainKinds::loadForMap($assetRoot, $data);
    $collisions = MapCollisionResolver::resolveMap($source['layers'], $data, $dictionary, $kinds)->collisionGrid;
    $terrain = $kinds?->resolveEncounterTerrain($source['layers'], $kinds->composeDictionary($dictionary));
    $table = EncounterTable::readBlock($data['encounters'], MapAreaCatalog::readMap($data, $source['tiles']), $terrain);
    return self::measure($table, $collisions, $catalog, $reachability === null ? null : $reachability->isReachable(...));
  }

  /**
   * The arena and terrain pairs with no variant, each once, with the scopes they are met in.
   *
   * @return list<array{arena: string, terrain: string, scopes: list<string>, cells: int}>
   */
  public function findMissingVariants(): array
  {
    $missing = [];
    foreach ($this->rows as $row) {
      if (!$row['missingVariant']) {
        continue;
      }
      $key = $row['arena'] . '|' . $row['terrain'];
      $missing[$key] ??= ['arena' => $row['arena'], 'terrain' => $row['terrain'], 'scopes' => [], 'cells' => 0];
      $missing[$key]['scopes'][$row['scope']] = $row['scope'];
      $missing[$key]['cells'] += $row['cells'];
    }
    return array_values(array_map(static fn(array $entry): array => [...$entry, 'scopes' => array_values($entry['scopes'])], $missing));
  }
}
