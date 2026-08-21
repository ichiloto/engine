<?php

namespace Ichiloto\Engine\Entities\States;

/**
 * Gives a battler buff/debuff stat stages.
 *
 * Each buffable stat holds a stage from -4 to +4; every stage shifts the
 * effective value by 25%. Stages last for the whole battle and reset when
 * it ends.
 *
 * @package Ichiloto\Engine\Entities\States
 */
trait HasStatStages
{
  /**
   * @var array<string, int> The live stages, keyed by stat name.
   */
  protected(set) array $statStages = [];

  /**
   * The stats that accept stages.
   */
  public static function buffableStats(): array
  {
    return ['attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'];
  }

  /**
   * Shifts a stat's stage.
   *
   * @param string $stat The stat name.
   * @param int $delta The stage shift (positive buffs, negative debuffs).
   * @return int The resulting stage.
   */
  public function addStatStage(string $stat, int $delta): int
  {
    if (! in_array($stat, self::buffableStats(), true)) {
      return 0;
    }

    $this->statStages[$stat] = intval(clamp(($this->statStages[$stat] ?? 0) + $delta, -4, 4));

    return $this->statStages[$stat];
  }

  /**
   * Returns a stat's current stage.
   *
   * @param string $stat The stat name.
   * @return int The stage (0 when untouched).
   */
  public function getStatStage(string $stat): int
  {
    return $this->statStages[$stat] ?? 0;
  }

  /**
   * Returns the multiplier a stat's stage applies.
   *
   * @param string $stat The stat name.
   * @return float The multiplier (1.0 when untouched, floor 0.25).
   */
  public function getStatStageMultiplier(string $stat): float
  {
    return max(0.25, 1.0 + 0.25 * $this->getStatStage($stat));
  }

  /** Applies the current stage to an uncapped stat-layer value. */
  public function applyStatStage(string $stat, int $uncappedValue): int
  {
    return max(0, intval(round($uncappedValue * $this->getStatStageMultiplier($stat))));
  }

  /**
   * Clears every stage (battle end).
   *
   * @return void
   */
  public function resetStatStages(): void
  {
    $this->statStages = [];
  }
}
