<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;

/** One map-owned weighted choice, not a troop's permanent battle presentation. */
final readonly class EncounterEntry
{
  /** The entry key naming the map areas a troop can be met in. */
  public const string AREAS_KEY = 'areas';
  /** The entry key naming the encounter terrains a troop can be met on. */
  public const string TERRAINS_KEY = 'terrains';

  /**
   * Optional graphics are validated by the graphical path, never by encounter selection.
   *
   * @param list<string> $areas The map's named areas the troop is met in; empty means the whole map.
   * @param list<string> $terrains The encounter terrains of the map's terrain kinds the troop is met on; empty means any.
   */
  private function __construct(public int $weight, private array $presentationSettings, public array $areas, public array $terrains = [])
  {
  }

  /**
   * Reads one authored entry: a weight, or a map with its weight and, optionally, the
   * map areas and encounter terrains it is limited to. A scope that cannot be read drops
   * the entry, because reading it as the whole map would put the troop where its author
   * kept it out.
   */
  public static function getFromValue(mixed $value): ?self
  {
    $weight = is_array($value) ? ($value['weight'] ?? null) : $value;
    if (!is_numeric($weight) || intval($weight) <= 0) {
      return null;
    }
    $areas = is_array($value) && array_key_exists(self::AREAS_KEY, $value) ? self::readAreas($value[self::AREAS_KEY]) : [];
    $terrains = is_array($value) && array_key_exists(self::TERRAINS_KEY, $value) ? self::readTerrains($value[self::TERRAINS_KEY]) : [];
    if ($areas === null || $terrains === null) {
      return null;
    }
    return new self(intval($weight), is_array($value)
      ? array_intersect_key($value, ['battleArena' => true]) : [], $areas, $terrains);
  }

  public function getBattleSettings(array $mapSettings): array
  {
    return array_replace($mapSettings, $this->presentationSettings);
  }

  /** @return list<string>|null The named areas, or null when the scope is not a non-empty list of unique area ids. */
  private static function readAreas(mixed $areas): ?array
  {
    if (!is_array($areas) || $areas === [] || !array_is_list($areas)) {
      return null;
    }
    foreach ($areas as $id) {
      if (!is_string($id) || preg_match(MapAreaCatalog::ID_PATTERN, $id) !== 1) {
        return null;
      }
    }
    return count(array_unique($areas)) === count($areas) ? $areas : null;
  }

  /** @return list<string>|null The encounter terrains, or null when the scope is not a non-empty list of unique terrain ids. */
  private static function readTerrains(mixed $terrains): ?array
  {
    if (!is_array($terrains) || $terrains === [] || !array_is_list($terrains)) {
      return null;
    }
    foreach ($terrains as $id) {
      if (!is_string($id) || preg_match(TerrainKind::ENCOUNTER_PATTERN, $id) !== 1) {
        return null;
      }
    }
    return count(array_unique($terrains)) === count($terrains) ? $terrains : null;
  }
}
