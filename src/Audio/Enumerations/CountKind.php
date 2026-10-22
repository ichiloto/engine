<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio\Enumerations;

/**
 * What an on-screen count is counting, so a game can give each kind of count
 * its own loop. A counting presentation names its kind when it holds the
 * counter tick ({@see \Ichiloto\Engine\Audio\CounterSound::holdTick()}).
 *
 * @package Ichiloto\Engine\Audio\Enumerations
 */
enum CountKind: string
{
  /** Any count without a loop of its own (a points countdown, gold). */
  case GENERAL = 'general';
  /** Experience points being dispensed (battle results EXP gauges and level fills). */
  case EXPERIENCE = 'experience';

  /**
   * Returns the system sound this kind of count loops. A game that binds no
   * track for it hears the generic counter instead; CounterSound owns that
   * fallback.
   *
   * @return SystemSound The kind's own looping system sound.
   */
  public function getSystemSound(): SystemSound
  {
    return match ($this) {
      self::GENERAL => SystemSound::COUNTER,
      self::EXPERIENCE => SystemSound::EXP_TALLY,
    };
  }
}
