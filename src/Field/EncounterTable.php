<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
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
 * It also says where each battle is fought ({@see resolveBattleArenaAt()}):
 * a troop's own arena, as authored; else the map's arena rules for the cell's area and
 * encounter terrain ({@see EncounterArenaRule}); else the map's arena; else
 * the presentation's default. Unless a rule picked the arena for the
 * terrain outright, the battle carries the cell's terrain so the arena's
 * terrain variants draw it.
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
   * @param list<EncounterArenaRule> $arenaRules The map's arena rules, in authored order.
   * @param array<string, CellArea> $arenaAreas The cells of each area an arena rule names.
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
    public readonly array $arenaRules = [],
    private readonly array $arenaAreas = [],
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

    [$rules, $ruleAreas] = self::readArenaRules($encounters[EncounterArenaRule::KEY] ?? null, $areas, $terrain, $ignored);

    return new self($entries, $weights, $troopAreas, $troopTerrains, $terrain,
      array_intersect_key($encounters ?? [], [BattlePresentationCatalog::ARENA_SETTING => true]), $rate,
      strval($encounters['tiles'] ?? 'encounter') === 'any', $ignored, $rules, $ruleAreas);
  }

  /**
   * Reads the block's arena rules. A rule the table cannot place is left out
   * and named: one naming an area the map does not declare, or a terrain no
   * terrain kind of the map meets, would never hold.
   *
   * @param list<string> $ignored Why each part of the block was left out, added to.
   * @return array{0: list<EncounterArenaRule>, 1: array<string, CellArea>}
   */
  private static function readArenaRules(mixed $authored, ?MapAreaCatalog $areas, ?MapEncounterTerrain $terrain, array &$ignored): array
  {
    if ($authored === null) {
      return [[], []];
    }
    if (!is_array($authored) || !array_is_list($authored)) {
      $ignored[] = 'The encounters\' battleArenas are not a list of arena rules; battles use the map\'s arena.';
      return [[], []];
    }
    $rules = [];
    $cells = [];
    $seen = [];
    foreach ($authored as $index => $value) {
      $rule = EncounterArenaRule::getFromValue($value);
      $where = sprintf('Battle arena rule %d', $index + 1);
      if (is_string($rule)) {
        $ignored[] = "{$where} is left out: {$rule}.";
        continue;
      }
      if ($rule->area !== null && !isset($areas?->areas[$rule->area])) {
        $ignored[] = sprintf('%s names map area "%s", which the map does not declare; it never holds.', $where, $rule->area);
        continue;
      }
      if ($rule->terrain !== null && !in_array($rule->terrain, $terrain?->terrains ?? [], true)) {
        $ignored[] = sprintf('%s names encounter terrain "%s", which no terrain kind of the map meets; it never holds.', $where, $rule->terrain);
        continue;
      }
      $key = ($rule->area ?? '') . '|' . ($rule->terrain ?? '');
      if (isset($seen[$key])) {
        $ignored[] = sprintf('%s repeats rule %d (%s); the first holds.', $where, $seen[$key] + 1, $rule->scope);
        continue;
      }
      $seen[$key] = $index;
      $rules[] = $rule;
      if ($rule->area !== null) {
        $cells[$rule->area] = $areas->areas[$rule->area];
      }
    }
    return [$rules, $cells];
  }

  /**
   * Where a battle against a troop met on a cell is fought: the troop's own
   * arena, as authored; else the first arena rule for the cell's area and terrain, then
   * for its area, then for its terrain; else the map's arena; else the
   * presentation's default. A rule for the terrain picks its arena outright;
   * every other choice carries the cell's terrain for the arena's variants.
   *
   * @param Vector2|null $position The cell the battle starts on; without one, only the troop's and the map's arena count.
   */
  public function resolveBattleArenaAt(string $troopName, ?Vector2 $position): EncounterArenaUse
  {
    $terrain = $position === null ? null : $this->terrain?->getTerrainAt((int) floor($position->x), (int) floor($position->y));
    $refined = static fn(array $settings): array => $terrain === null ? $settings
      : [...$settings, BattlePresentationCatalog::TERRAIN_SETTING => $terrain];
    $entry = $this->entries[$troopName] ?? null;
    $own = $entry?->getBattleSettings([]) ?? [];
    if (array_key_exists(BattlePresentationCatalog::ARENA_SETTING, $own)) {
      // A troop met on a scene of its own is fought there as authored, as an event battle is.
      return new EncounterArenaUse("troop {$troopName}", $own, $terrain);
    }
    $inArea = $position === null ? [] : array_values(array_filter($this->arenaRules,
      fn(EncounterArenaRule $rule): bool => $rule->area !== null && $this->arenaAreas[$rule->area]->contains($position)));
    foreach ([
      static fn(EncounterArenaRule $rule): bool => $rule->terrain !== null && $rule->terrain === $terrain,
      static fn(EncounterArenaRule $rule): bool => $rule->terrain === null,
    ] as $holds) {
      foreach ($inArea as $rule) {
        if ($holds($rule)) {
          $settings = [BattlePresentationCatalog::ARENA_SETTING => $rule->arena];
          return new EncounterArenaUse($rule->scope, $rule->terrain === null ? $refined($settings) : $settings, $terrain);
        }
      }
    }
    foreach ($this->arenaRules as $rule) {
      if ($rule->area === null && $terrain !== null && $rule->terrain === $terrain) {
        return new EncounterArenaUse($rule->scope, [BattlePresentationCatalog::ARENA_SETTING => $rule->arena], $terrain);
      }
    }
    return $this->presentationSettings === []
      ? new EncounterArenaUse('default', $refined([]), $terrain)
      : new EncounterArenaUse('map', $refined($this->presentationSettings), $terrain);
  }

  /**
   * Where every battle the map can start is fought: each way the arena is
   * decided, with the cells and troops it holds for, over the cells a step
   * on can start a battle.
   *
   * @param int[][] $collisions The map's resolved collision grid, rows of {@see CollisionType} values.
   * @param (callable(int, int): bool)|null $isReachable Whether the player can stand on a cell; null counts every cell.
   * @return list<array{use: EncounterArenaUse, cells: int, troops: list<string>}>
   */
  public function measureArenaUses(array $collisions, ?callable $isReachable = null): array
  {
    $uses = [];
    foreach ($collisions as $y => $row) {
      foreach ($row as $x => $value) {
        if ($isReachable !== null && !$isReachable($x, $y)) {
          continue;
        }
        $position = new Vector2($x, $y);
        $tile = CollisionType::tryFrom($value);
        if ($tile === null || $tile->blocksMovement() || $this->getStepWeight($tile, $position) <= 0.0) {
          continue;
        }
        foreach (array_map(strval(...), array_keys($this->getTroopWeightsAt($position))) as $troop) {
          $use = $this->resolveBattleArenaAt($troop, $position);
          $key = $use->scope . '|' . json_encode($use->settings);
          $uses[$key] ??= ['use' => $use, 'cells' => [], 'troops' => []];
          $uses[$key]['cells']["{$x},{$y}"] = true;
          $uses[$key]['troops'][$troop] = true;
        }
      }
    }
    return array_values(array_map(static fn(array $use): array => ['use' => $use['use'], 'cells' => count($use['cells']),
      'troops' => array_keys($use['troops'])], $uses));
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
