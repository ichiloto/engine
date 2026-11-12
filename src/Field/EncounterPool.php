<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Vector2;

/** A map's explicit weighted roster for a named area, encounter terrain, or both. */
final readonly class EncounterPool
{
  public const string KEY = 'pools';

  /** @param array<string, EncounterEntry> $entries */
  private function __construct(private ?CellArea $area, private ?string $terrain, public array $entries)
  {
  }

  /** @return self|string The parsed pool or the reason it cannot be used. */
  public static function getFromValue(mixed $value, ?MapAreaCatalog $areas, ?MapEncounterTerrain $terrain): self|string
  {
    if (!is_array($value) || array_diff(array_keys($value), ['area', 'terrain', 'troops']) !== []) {
      return 'expected an area or terrain and a map of troop weights';
    }

    $areaId = $value['area'] ?? null;
    $terrainId = $value['terrain'] ?? null;
    if ($areaId === null && $terrainId === null) {
      return 'no area or terrain is specified';
    }
    $area = is_string($areaId) ? ($areas?->areas[$areaId] ?? null) : null;
    if (array_key_exists('area', $value) && $area === null) {
      return 'area must name a declared map area';
    }
    if (array_key_exists('terrain', $value)
      && (!is_string($terrainId) || !in_array($terrainId, $terrain?->terrains ?? [], true))) {
      return 'terrain must name a terrain used by the map';
    }

    $troops = $value['troops'] ?? null;
    if (!is_array($troops) || $troops === []) {
      return 'the pool must name at least one troop';
    }

    $entries = [];
    foreach ($troops as $name => $authoredEntry) {
      $entry = EncounterEntry::getFromValue($authoredEntry);
      if (!is_string($name) || trim($name) === '' || $entry === null) {
        return 'each pool troop needs a name and positive weight';
      }
      if (is_array($authoredEntry)
        && array_diff(array_keys($authoredEntry), ['weight', BattlePresentationCatalog::ARENA_SETTING]) !== []) {
        return 'pool troops accept weight and battleArena; area and terrain belong to the pool';
      }
      if (is_array($authoredEntry) && array_key_exists(BattlePresentationCatalog::ARENA_SETTING, $authoredEntry)
        && (!is_string($authoredEntry[BattlePresentationCatalog::ARENA_SETTING])
          || trim($authoredEntry[BattlePresentationCatalog::ARENA_SETTING]) === '')) {
        return 'a pool troop battleArena must name an arena';
      }
      $entries[$name] = $entry;
    }
    return new self($area, $terrainId, $entries);
  }

  public function matches(Vector2 $position, ?string $terrain): bool
  {
    return ($this->terrain === null || $this->terrain === $terrain)
      && ($this->area === null || $this->area->contains($position));
  }

  /** @return array<string, int> */
  public function getTroopWeights(): array
  {
    return array_map(static fn(EncounterEntry $entry): int => $entry->weight, $this->entries);
  }
}
