<?php

namespace Ichiloto\Engine\Entities\States;

use Ichiloto\Engine\Battle\BattleStateClock;

/**
 * One battler's live affliction with a state.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class StateInstance
{
  public private(set) int $remainingDelayTurns = 0;
  private ?BattleStateClock $battleClock = null;
  private ?float $expiresAt = null;

  public bool $isPending { get => $this->remainingDelayTurns > 0; }

  public bool $isElapsedExpired {
    get => $this->expiresAt !== null && ($this->battleClock?->getSeconds() ?? 0.0) >= $this->expiresAt;
  }

  public bool $isActive { get => !$this->isPending && !$this->isElapsedExpired; }

  public function bindBattleClock(BattleStateClock $clock): void
  {
    if ($this->state->durationSeconds === null || $this->battleClock === $clock) { return; }
    $this->battleClock = $clock;
    if (!$this->isPending) { $this->startElapsedDuration(); }
  }

  private function startElapsedDuration(): void
  {
    $duration = $this->state->durationSeconds;
    if ($this->battleClock !== null && $duration !== null) {
      $this->expiresAt = $this->battleClock->getSeconds() + $duration;
    }
  }

  /**
   * @param State $state The state definition.
   * @param int|null $remainingTurns Turns left before expiry; null lasts until cured.
   * @param int $fieldSteps Steps walked in the field since the state last acted there.
   * @param bool $awaitsNextTurn True when the state was received during the bearer's own turn, so that turn's end does not count against it.
   */
  public function __construct(
    protected(set) State $state,
    public ?int $remainingTurns = null,
    public int $fieldSteps = 0,
    public bool $awaitsNextTurn = false,
    int $delayTurns = 0,
  )
  {
    if ($delayTurns < 0) {
      throw new \InvalidArgumentException('State activation delay cannot be negative.');
    }
    $this->remainingDelayTurns = $delayTurns;
  }

  /** Counts one pending turn and reports the transition to active. */
  public function advanceDelay(): bool
  {
    if (!$this->isPending) { return false; }
    $this->remainingDelayTurns--;
    if ($this->isPending) { return false; }
    $this->startElapsedDuration();
    return true;
  }

  /**
   * Counts one step walked in the field.
   *
   * @return bool True when the steps make up one field turn, which restarts the count.
   */
  public function advanceFieldStep(): bool
  {
    if ($this->isPending || $this->state->fieldTickSteps === null) {
      return false;
    }

    $this->fieldSteps++;

    if ($this->fieldSteps < $this->state->fieldTickSteps) {
      return false;
    }

    $this->fieldSteps = 0;

    return true;
  }

  /**
   * Advances the instance by one turn.
   *
   * @return bool True when the state expired this turn.
   */
  public function tickDuration(): bool
  {
    if ($this->remainingTurns === null) {
      return false;
    }

    $this->remainingTurns = max(0, $this->remainingTurns - 1);

    return $this->remainingTurns === 0;
  }
}
