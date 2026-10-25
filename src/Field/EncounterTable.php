<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

/**
 * What a map's `encounters` block means: which troops are met where, how
 * many steps lie between encounters on average, and how much each step
 * counts. The field's {@see EncounterManager} counts the player's steps with
 * it; anything else that needs the same answers, such as the Editor measuring
 * a walk, reads them here rather than restating the rules.
 *
 * The block is read loosely, as the field always has: a missing `rate` means
 * fifteen steps, a missing `tiles` means danger tiles only, and an entry the
 * table cannot place is left out and named in {@see self::$ignored}.
 *
 * @package Ichiloto\Engine\Field
 */
final class EncounterTable
{
  /** Average steps between encounters when a block names troops but no rate. */
  public const int DEFAULT_RATE = 15;

  /**
   * @param array<string, EncounterEntry> $entries The troops met, keyed by troop name.
   * @param array<string, int> $troopWeights The troops' weights, keyed by troop name.
   * @param array<string, list<CellArea>> $troopAreas The cells each area-limited troop is met in.
   * @param array<string, list<string>> $troopTerrains The encounter terrains each terrain-limited troop is met on.
   * @param MapEncounterTerrain|null $terrain The encounter terrain of each cell, on a map painted in terrain kinds.
   * @param array<string, mixed> $presentationSettings The block's graphical battle settings.
   * @param int $averageStepsBetween Average steps between encounters; 0 when the map has none.
   * @param bool $countsEveryTile Whether every walkable step counts, not only danger tiles.
   * @param list<string> $ignored Why each part of the block the table left out was left out.
   */
  private function __construct(
    public readonly array $entries,
    public readonly array $troopWeights,
    public readonly array $troopAreas,
    public readonly array $troopTerrains,
    public readonly ?MapEncounterTerrain $terrain,
    public readonly array $presentationSettings,
    public readonly int $averageStepsBetween,
    public readonly bool $countsEveryTile,
    public readonly array $ignored,
  )
  {
  }

  /** A map without encounters. */
  public static function createEmpty(): self
  {
    return new self([], [], [], [], null, [], 0, false, []);
  }

  /**
   * Reads a map's encounter block (null when the map declares none).
   *
   * @param array<string, mixed>|null $encounters The map's `encounters` entry.
   * @param MapAreaCatalog|null $areas The map's named areas, which area-limited troops are met in.
   * @param MapEncounterTerrain|null $terrain The encounter terrain of each cell, which terrain-limited troops are met on.
   */
  public static function readBlock(?array $encounters, ?MapAreaCatalog $areas = null, ?MapEncounterTerrain $terrain = null): self
  {
    $entries = [];
    $weights = [];
    $troopAreas = [];
    $troopTerrains = [];
    $ignored = [];

    foreach ((array) ($encounters['troops'] ?? []) as $troopName => $value) {
      $entry = EncounterEntry::getFromValue($value);
      if (! is_string($troopName) || $entry === null) {
        continue;
      }
      $cells = [];
      foreach ($entry->areas as $areaId) {
        $area = $areas?->areas[$areaId] ?? null;
        if ($area !== null) {
          $cells[] = $area;
        }
      }
      if (count($cells) !== count($entry->areas)) {
        // Meeting the troop map-wide would put it where its author kept it out.
        $ignored[] = sprintf('Encounter "%s" names a map area the map does not declare; it is never met.', $troopName);
        continue;
      }
      $unknown = array_diff($entry->terrains, $terrain?->terrains ?? []);
      if ($unknown !== []) {
        // Meeting the troop on any terrain would put it where its author kept it out.
        $ignored[] = sprintf('Encounter "%s" names encounter terrain %s, which no terrain kind of the map meets; it is never met.',
          $troopName, implode(', ', $unknown));
        continue;
      }
      $entries[$troopName] = $entry;
      $weights[$troopName] = $entry->weight;
      if ($cells !== []) {
        $troopAreas[$troopName] = $cells;
      }
      if ($entry->terrains !== []) {
        $troopTerrains[$troopName] = $entry->terrains;
      }
    }

    $rate = 0;
    if ($weights !== []) {
      $rate = max(1, intval($encounters['rate'] ?? self::DEFAULT_RATE));
    } elseif (! empty($encounters)) {
      // A map that asks for encounters and gets none is a data mistake, not a
      // design choice, and silence is how it stays unnoticed.
      $ignored[] = 'A map declares encounters but names no troops the engine can read. '
        . "Expected ['troops' => ['Troop Name' => weight or ['weight' => weight, ...], ...], 'rate' => steps].";
    }

    return new self($entries, $weights, $troopAreas, $troopTerrains, $terrain,
      array_intersect_key($encounters ?? [], ['battleArena' => true]), $rate,
      strval($encounters['tiles'] ?? 'encounter') === 'any', $ignored);
  }

  /** Whether any step on the map can lead to an encounter. */
  public bool $hasEncounters {
    get => $this->averageStepsBetween > 0;
  }

  /**
   * The troops that can be met on a cell, with their weights.
   *
   * @param Vector2|null $position The cell, or null when it is not known.
   * @return array<string, int> Troop weights, keyed by troop name.
   */
  public function getTroopWeightsAt(?Vector2 $position): array
  {
    $terrain = $position === null ? null : $this->terrain?->getTerrainAt((int) floor($position->x), (int) floor($position->y));
    return array_filter($this->troopWeights, fn(int $weight, string $troopName): bool => (! isset($this->troopAreas[$troopName])
      || ($position !== null && array_any($this->troopAreas[$troopName], static fn(CellArea $area): bool => $area->contains($position))))
      && (! isset($this->troopTerrains[$troopName]) || in_array($terrain, $this->troopTerrains[$troopName], true)),
      ARRAY_FILTER_USE_BOTH);
  }

  /**
   * How much stepping onto a cell burns the encounter counter, before any
   * repel or lure: none where the step does not count or no troop can be met,
   * one for a counted step, and two for a danger tile on a map that counts
   * every tile, mirroring RPG Maker's bush/terrain danger.
   *
   * @param CollisionType|null $tile The collision type of the cell stepped onto.
   * @param Vector2|null $position The cell stepped onto; without one, only troops met map-wide count.
   */
  public function getStepWeight(?CollisionType $tile, ?Vector2 $position): float
  {
    if (! $this->hasEncounters) {
      return 0.0;
    }
    $isDangerTile = $tile === CollisionType::ENCOUNTER;
    if (! $isDangerTile && ! $this->countsEveryTile) {
      return 0.0;
    }
    if ($this->getTroopWeightsAt($position) === []) {
      return 0.0;
    }

    return ($this->countsEveryTile && $isDangerTile) ? 2.0 : 1.0;
  }
}
