<?php

namespace Ichiloto\Engine\Battle\Engines\ActiveTime;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/**
 * Represents the configuration used by the active-time battle engine.
 *
 * @package Ichiloto\Engine\Battle\Engines\ActiveTime
 */
class ActiveTimeBattleConfig extends BattleConfig
{
  /**
   * Creates a new active-time battle configuration.
   *
   * @param Party $party The active party.
   * @param Troop $troop The active troop.
   * @param BattleScreen $ui The battle screen.
   * @param array $events The battle events.
   * @param string $mode The ATB mode.
   * @param float $baseFillRate The base ATB fill rate.
   * @param float $speedFactor The speed-stat contribution factor.
   * @param float $openingVariance The randomized opening-gauge variance.
   * @param float $openingSpeedFactor The speed-stat contribution applied at battle start.
   * @param int $surpriseAttackChancePercent The chance for a party-advantage opener.
   * @param int $backAttackChancePercent The chance for an enemy-advantage opener.
   * @param array<string, mixed> $settings Runtime battle settings.
   */
  public function __construct(
    Party $party,
    Troop $troop,
    protected(set) BattleScreen $ui,
    array $events = [],
    protected(set) string $mode = 'wait',
    protected(set) float $baseFillRate = 35.0,
    protected(set) float $speedFactor = 1.0,
    protected(set) float $openingVariance = 24.0,
    protected(set) float $openingSpeedFactor = 2.5,
    protected(set) int $surpriseAttackChancePercent = 8,
    protected(set) int $backAttackChancePercent = 6,
    array $settings = [],
  )
  {
    parent::__construct($party, $troop, $events, $settings);
  }
}