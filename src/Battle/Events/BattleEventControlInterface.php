<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

/**
 * What battle event commands may change in the battle their page runs in.
 *
 * @package Ichiloto\Engine\Battle\Events
 */
interface BattleEventControlInterface
{
  /**
   * Makes a troop member concede.
   *
   * @param int $member The member's authored index in the troop.
   * @throws \RuntimeException When the troop has no such member.
   */
  public function concedeEnemy(int $member): void;

  /** Ends the battle with the outcome once the running page finishes. */
  public function requestOutcome(BattleOutcome $outcome): void;
}
