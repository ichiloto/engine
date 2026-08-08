<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Stats;

/**
 * A battler as combat math sees it.
 *
 * Wraps a battler so `$view->stats` carries equipment-adjusted values
 * (characters) with buff/debuff stage multipliers applied, while every
 * other property and method passes through to the real battler. Damage
 * formulas evaluate against views; writes (HP loss, state infliction) go
 * to the real battler.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattlerBattleView
{
  /**
   * @var Stats The stage-adjusted combat stats.
   */
  public readonly Stats $stats;

  /**
   * @param object $battler The real battler (any object exposing `stats`, typically a {@see CharacterInterface}).
   */
  public function __construct(protected(set) object $battler)
  {
    $base = $battler instanceof Character ? $battler->effectiveStats : $battler->stats;
    $stats = clone $base;

    if (method_exists($battler, 'getStatStageMultiplier')) {
      foreach ($battler::buffableStats() as $stat) {
        $stats->$stat = max(1, intval(round($stats->$stat * $battler->getStatStageMultiplier($stat))));
      }
    }

    $this->stats = $stats;
  }

  /**
   * Passes property reads through to the real battler.
   *
   * @param string $name The property name.
   * @return mixed The battler's value.
   */
  public function __get(string $name): mixed
  {
    return $this->battler->$name;
  }

  /**
   * Passes method calls through to the real battler.
   *
   * @param string $method The method name.
   * @param array $arguments The arguments.
   * @return mixed The battler's return value.
   */
  public function __call(string $method, array $arguments): mixed
  {
    return $this->battler->$method(...$arguments);
  }
}
