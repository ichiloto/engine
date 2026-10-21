<?php

namespace Ichiloto\Engine\Entities\States;

/**
 * One battler's live affliction with a state.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class StateInstance
{
  /**
   * @param State $state The state definition.
   * @param int|null $remainingTurns Turns left before expiry; null lasts until cured.
   * @param int $fieldSteps Steps walked in the field since the state last acted there.
   * @param bool $awaitsNextTurn True when the bearer gave itself the state this turn, so the end of that turn does not count against it.
   */
  public function __construct(
    protected(set) State $state,
    public ?int $remainingTurns = null,
    public int $fieldSteps = 0,
    public bool $awaitsNextTurn = false,
  )
  {
  }

  /**
   * Counts one step walked in the field.
   *
   * @return bool True when the steps make up one field turn, which restarts the count.
   */
  public function advanceFieldStep(): bool
  {
    if ($this->state->fieldTickSteps === null) {
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
