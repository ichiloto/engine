<?php

namespace Ichiloto\Engine\Field;

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
 *   'troops' => ['Bat x 2' => 5, 'Rat + Bat' => 3],  // name => weight
 *   'rate' => 12,          // average steps between encounters
 *   'tiles' => 'encounter' // 'encounter' (default): only danger tiles count; 'any': every step counts
 * ],
 * ```
 *
 * Every qualifying step burns down a randomized counter; when it empties, a
 * weighted troop is chosen and battle begins, with a small chance of a
 * preemptive strike or an ambush. `encounterRateMultiplier` is the
 * repel/lure hook: below 1 stretches the counter (repel), above 1 burns it
 * faster (lure), 0 suppresses encounters entirely.
 *
 * @package Ichiloto\Engine\Field
 */
class EncounterManager
{
  protected const int PREEMPTIVE_CHANCE_PERCENT = 5;
  protected const int AMBUSH_CHANCE_PERCENT = 5;

  /**
   * @var array<string, int> Troop weights, keyed by troop name.
   */
  protected array $troopWeights = [];
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
   * @return void
   */
  public function configure(?array $encounters): void
  {
    $this->troopWeights = [];
    $this->averageStepsBetween = 0;
    $this->countsEveryTile = strval($encounters['tiles'] ?? 'encounter') === 'any';

    foreach ((array) ($encounters['troops'] ?? []) as $troopName => $weight) {
      if (is_string($troopName) && is_numeric($weight) && intval($weight) > 0) {
        $this->troopWeights[$troopName] = intval($weight);
      }
    }

    if (! empty($this->troopWeights)) {
      $this->averageStepsBetween = max(1, intval($encounters['rate'] ?? 15));
    } elseif (! empty($encounters)) {
      // A map that asks for encounters and gets none is a data mistake, not a
      // design choice, and silence is how it stays unnoticed.
      Debug::warn(
        'A map declares encounters but names no troops the engine can read. '
        . "Expected ['troops' => ['Troop Name' => weight, ...], 'rate' => steps]."
      );
    }

    $this->resetCounter();
  }

  /**
   * Registers one player step.
   *
   * @param CollisionType|null $tile The collision type of the tile stepped onto.
   * @return void
   */
  public function registerStep(?CollisionType $tile): void
  {
    if ($this->averageStepsBetween < 1 || $this->encounterRateMultiplier <= 0.0) {
      return;
    }

    $isDangerTile = $tile === CollisionType::ENCOUNTER;

    if (! $isDangerTile && ! $this->countsEveryTile) {
      return;
    }

    // Danger tiles burn the counter twice as fast on maps that count
    // every tile, mirroring RPG Maker's bush/terrain danger.
    $stepWeight = ($this->countsEveryTile && $isDangerTile) ? 2.0 : 1.0;
    $this->stepsUntilEncounter -= $stepWeight * $this->encounterRateMultiplier;

    if ($this->stepsUntilEncounter <= 0) {
      $this->startEncounter();
    }
  }

  /**
   * Starts a weighted-random encounter.
   *
   * @return void
   */
  protected function startEncounter(): void
  {
    $this->resetCounter();
    $troopName = $this->pickTroopName();

    if ($troopName === null || ! $this->gameScene->party) {
      return;
    }

    try {
      $troop = get_troop($troopName);
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Encounter skipped, unknown troop "%s": %s', $troopName, $exception->getMessage()));
      return;
    }

    $firstStrike = match (true) {
      rand(1, 100) <= self::PREEMPTIVE_CHANCE_PERCENT => 'party',
      rand(1, 100) <= self::AMBUSH_CHANCE_PERCENT => 'troop',
      default => null,
    };

    $this->gameScene->sceneManager->loadBattleScene(
      $this->gameScene->party,
      $troop,
      extraSettings: $firstStrike !== null ? ['firstStrike' => $firstStrike] : []
    );
  }

  /**
   * Picks a troop name weighted by the map's table.
   *
   * @return string|null The chosen troop name.
   */
  protected function pickTroopName(): ?string
  {
    if (empty($this->troopWeights)) {
      return null;
    }

    $roll = rand(1, array_sum($this->troopWeights));

    foreach ($this->troopWeights as $troopName => $weight) {
      $roll -= $weight;

      if ($roll <= 0) {
        return $troopName;
      }
    }

    return array_key_last($this->troopWeights);
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
