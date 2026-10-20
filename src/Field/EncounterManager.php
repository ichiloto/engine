<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Drives random encounters from the player's steps.
 *
 * A map opts in by declaring an `encounters` block in its data file:
 *
 * ```php
 * 'encounters' => [
 *   'troops' => [
 *     'Bat x 2' => 5,
 *     'Rat + Bat' => ['weight' => 3, 'battleArena' => 'arena.cave'],
 *     'Roost Bats' => ['weight' => 2, 'areas' => ['east-roost']],
 *   ], // name => weight or encounter entry; entry arena outranks the map arena
 *      // and entry areas (the map's named `areas`) limit where a troop is met
 *   'rate' => 12,          // average steps between encounters
 *   'battleArena' => 'arena.forest', // optional graphical arena catalog key
 *   'tiles' => 'encounter' // 'encounter' (default): only danger tiles count; 'any': every step counts
 * ],
 * ```
 *
 * Every qualifying step burns down a randomized counter; when it empties, a
 * weighted troop is chosen and battle begins, with a small chance of a
 * preemptive strike or an ambush. A troop limited to named areas is met only
 * while the player stands in one of them, and a step where no troop can be
 * met does not count, so a map whose troops all live in pockets is quiet
 * between them. `encounterRateMultiplier` is the
 * repel/lure hook: below 1 stretches the counter (repel), above 1 burns it
 * faster (lure), 0 suppresses encounters entirely.
 *
 * @package Ichiloto\Engine\Field
 */
class EncounterManager
{
  /**
   * @var array<string, int> Troop weights, keyed by troop name.
   */
  protected array $troopWeights = [];
  /** @var array<string, EncounterEntry> Map-owned choices matching the derived weight table. */
  private array $entries = [];
  /** @var array<string, list<CellArea>> The cells each area-limited troop is met in, keyed by troop name. */
  private array $troopAreas = [];
  /** @var array<string, mixed> Optional presentation selection, validated only by the graphical renderer path. */
  private array $presentationSettings = [];
  /**
   * @var int Average steps between encounters; 0 disables the map.
   */
  protected int $averageStepsBetween = 0;
  /**
   * @var bool True when every walkable step counts, not just danger tiles.
   */
  protected bool $countsEveryTile = false;
  /**
   * @var float Steps remaining until the next encounter.
   */
  protected float $stepsUntilEncounter = 0;
  /**
   * @var float The repel/lure hook: scales how fast steps burn the counter.
   */
  public float $encounterRateMultiplier = 1.0;

  /**
   * EncounterManager constructor.
   *
   * @param GameScene $gameScene The owning game scene.
   */
  public function __construct(protected GameScene $gameScene)
  {
  }

  /**
   * Applies a map's encounter block (null disables encounters on the map).
   *
   * @param array<string, mixed>|null $encounters The map's `encounters` entry.
   * @param MapAreaCatalog|null $areas The map's named areas, which area-limited troops are met in.
   * @return void
   */
  public function configure(?array $encounters, ?MapAreaCatalog $areas = null): void
  {
    $this->troopWeights = [];
    $this->entries = [];
    $this->troopAreas = [];
    $this->presentationSettings = array_intersect_key($encounters ?? [], ['battleArena' => true]);
    $this->averageStepsBetween = 0;
    $this->countsEveryTile = strval($encounters['tiles'] ?? 'encounter') === 'any';

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
        Debug::warn(sprintf('Encounter "%s" names a map area the map does not declare; it is never met.', $troopName));
        continue;
      }
      $this->entries[$troopName] = $entry;
      $this->troopWeights[$troopName] = $entry->weight;
      if ($cells !== []) {
        $this->troopAreas[$troopName] = $cells;
      }
    }

    if (! empty($this->troopWeights)) {
      $this->averageStepsBetween = max(1, intval($encounters['rate'] ?? 15));
    } elseif (! empty($encounters)) {
      // A map that asks for encounters and gets none is a data mistake, not a
      // design choice, and silence is how it stays unnoticed.
      Debug::warn(
        'A map declares encounters but names no troops the engine can read. '
        . "Expected ['troops' => ['Troop Name' => weight or ['weight' => weight, ...], ...], 'rate' => steps]."
      );
    }

    $this->resetCounter();
  }

  /**
   * Registers one player step.
   *
   * @param CollisionType|null $tile The collision type of the tile stepped onto.
   * @param Vector2|null $position The cell stepped onto; without one, only troops met map-wide count.
   * @return void
   */
  public function registerStep(?CollisionType $tile, ?Vector2 $position = null): void
  {
    if ($this->averageStepsBetween < 1 || $this->encounterRateMultiplier <= 0.0) {
      return;
    }

    $isDangerTile = $tile === CollisionType::ENCOUNTER;

    if (! $isDangerTile && ! $this->countsEveryTile) {
      return;
    }

    $weights = $this->getTroopWeightsAt($position);

    if ($weights === []) {
      return;
    }

    // Danger tiles burn the counter twice as fast on maps that count
    // every tile, mirroring RPG Maker's bush/terrain danger.
    $stepWeight = ($this->countsEveryTile && $isDangerTile) ? 2.0 : 1.0;
    $this->stepsUntilEncounter -= $stepWeight * $this->encounterRateMultiplier;

    if ($this->stepsUntilEncounter <= 0) {
      $this->startEncounter($weights);
    }
  }

  /**
   * The troops that can be met on a cell, with their weights.
   *
   * @param Vector2|null $position The cell, or null when it is not known.
   * @return array<string, int> Troop weights, keyed by troop name.
   */
  public function getTroopWeightsAt(?Vector2 $position): array
  {
    return array_filter($this->troopWeights, fn(int $weight, string $troopName): bool => ! isset($this->troopAreas[$troopName])
      || ($position !== null && array_any($this->troopAreas[$troopName], static fn(CellArea $area): bool => $area->contains($position))),
      ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Starts a weighted-random encounter among the troops that can be met here.
   *
   * @param array<string, int> $weights Troop weights, keyed by troop name.
   * @return void
   */
  protected function startEncounter(array $weights): void
  {
    $this->resetCounter();
    $troopName = $this->pickTroopName($weights);

    if ($troopName === null || ! $this->gameScene->party) {
      return;
    }

    try {
      $troop = get_troop($troopName);
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Encounter skipped, unknown troop "%s": %s', $troopName, $exception->getMessage()));
      return;
    }

    $this->gameScene->sceneManager->loadBattleScene(
      $this->gameScene->party,
      $troop,
      extraSettings: $this->entries[$troopName]->getBattleSettings($this->presentationSettings)
    );
  }

  /**
   * Picks a troop name by weight.
   *
   * @param array<string, int> $weights Troop weights, keyed by troop name.
   * @return string|null The chosen troop name.
   */
  protected function pickTroopName(array $weights): ?string
  {
    if (empty($weights)) {
      return null;
    }

    $roll = rand(1, array_sum($weights));

    foreach ($weights as $troopName => $weight) {
      $roll -= $weight;

      if ($roll <= 0) {
        return $troopName;
      }
    }

    return array_key_last($weights);
  }

  /**
   * Randomizes the steps until the next encounter.
   *
   * @return void
   */
  protected function resetCounter(): void
  {
    $this->stepsUntilEncounter = $this->averageStepsBetween > 0
      ? rand(intval(ceil($this->averageStepsBetween * 0.5)), intval(ceil($this->averageStepsBetween * 1.5)))
      : 0;
  }
}
