<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/** One map-owned weighted choice, not a troop's permanent battle presentation. */
final readonly class EncounterEntry
{
  /** The entry key naming the map areas a troop can be met in. */
  public const string AREAS_KEY = 'areas';

  /**
   * Optional graphics are validated by the graphical path, never by encounter selection.
   *
   * @param list<string> $areas The map's named areas the troop is met in; empty means the whole map.
   */
  private function __construct(public int $weight, private array $presentationSettings, public array $areas)
  {
  }

  /**
   * Reads one authored entry: a weight, or a map with its weight and, optionally, the
   * map areas it is limited to. A scope that cannot be read drops the entry, because
   * reading it as the whole map would put the troop where its author kept it out.
   */
  public static function getFromValue(mixed $value): ?self
  {
    $weight = is_array($value) ? ($value['weight'] ?? null) : $value;
    if (!is_numeric($weight) || intval($weight) <= 0) {
      return null;
    }
    $areas = is_array($value) && array_key_exists(self::AREAS_KEY, $value) ? self::readAreas($value[self::AREAS_KEY]) : [];
    if ($areas === null) {
      return null;
    }
    return new self(intval($weight), is_array($value)
      ? array_intersect_key($value, ['battleArena' => true]) : [], $areas);
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
}
