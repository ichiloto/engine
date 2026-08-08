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
   */
  public function __construct(
    protected(set) State $state,
    public ?int $remainingTurns = null,
  )
  {
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
