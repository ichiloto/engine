<?php

namespace Ichiloto\Engine\Field;

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
 *     'Marsh Toads' => ['weight' => 3, 'terrains' => ['wetland']],
 *   ], // name => weight or encounter entry; entry arena outranks the map arena,
 *      // entry areas (the map's named `areas`) and entry terrains (the encounter
 *      // terrains of the map's terrain kinds) limit where a troop is met
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
 * between them. A troop limited to encounter terrains is met only on cells
 * whose terrain kind meets one of them ({@see MapEncounterTerrain}). `encounterRateMultiplier` is the
 * repel/lure hook: below 1 stretches the counter (repel), above 1 burns it
 * faster (lure), 0 suppresses encounters entirely.
 *
 * @package Ichiloto\Engine\Field
 */
class EncounterManager
{
  /** What the current map's encounter block means ({@see EncounterTable}). */
  public private(set) EncounterTable $table;
  /**
   * @var array<string, int> Troop weights, keyed by troop name.
   */
  protected array $troopWeights {
    get => $this->table->troopWeights;
  }
  /**
   * @var int Average steps between encounters; 0 disables the map.
   */
  protected int $averageStepsBetween {
    get => $this->table->averageStepsBetween;
  }
  /**
   * @var bool True when every walkable step counts, not just danger tiles.
   */
  protected bool $countsEveryTile {
    get => $this->table->countsEveryTile;
  }
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
    $this->table = EncounterTable::createEmpty();
  }

  /**
   * Applies a map's encounter block (null disables encounters on the map).
   *
   * @param array<string, mixed>|null $encounters The map's `encounters` entry.
   * @param MapAreaCatalog|null $areas The map's named areas, which area-limited troops are met in.
   * @param MapEncounterTerrain|null $terrain The encounter terrain of each cell, which terrain-limited troops are met on.
   * @return void
   */
  public function configure(?array $encounters, ?MapAreaCatalog $areas = null, ?MapEncounterTerrain $terrain = null): void
  {
    $this->table = EncounterTable::readBlock($encounters, $areas, $terrain);
    foreach ($this->table->ignored as $warning) {
      Debug::warn($warning);
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
    if ($this->encounterRateMultiplier <= 0.0) {
      return;
    }

    $stepWeight = $this->table->getStepWeight($tile, $position);

    if ($stepWeight <= 0.0) {
      return;
    }

    $this->stepsUntilEncounter -= $stepWeight * $this->encounterRateMultiplier;

    if ($this->stepsUntilEncounter <= 0) {
      $this->startEncounter($this->table->getTroopWeightsAt($position));
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
    return $this->table->getTroopWeightsAt($position);
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
      extraSettings: $this->table->entries[$troopName]->getBattleSettings($this->table->presentationSettings)
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
