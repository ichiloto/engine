<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Core\Clocks\RuntimeClock;

/** Measures time spent in running combat phases for the current battle. */
final class BattleStateClock
{
  private float $elapsed = 0.0;
  private ?float $runningSince = null;

  public function getSeconds(): float
  {
    return $this->elapsed + ($this->runningSince === null ? 0.0 : RuntimeClock::getSeconds() - $this->runningSince);
  }

  public function setRunning(bool $running): void
  {
    if ($running) {
      $this->runningSince ??= RuntimeClock::getSeconds();
    } elseif ($this->runningSince !== null) {
      $this->elapsed = $this->getSeconds();
      $this->runningSince = null;
    }
  }
}
