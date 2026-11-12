<?php

namespace Ichiloto\Engine\Entities\States;

/**
 * One battler's live affliction with a state.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class StateInstance
{
  public private(set) int $remainingDelayTurns = 0;

  public bool $isPending { get => $this->remainingDelayTurns > 0; }

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
    return $this->remainingDelayTurns === 0;
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
