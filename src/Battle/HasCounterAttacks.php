<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

/** Shared innate/state grants for battlers using HasStates. */
trait HasCounterAttacks
{
  protected(set) ?CounterAttackRule $counterAttack = null;

  /** @return list<CounterAttackRule> */
  public function getCounterAttackRules(): array
  {
    $rules = $this->counterAttack === null ? [] : [$this->counterAttack];
    foreach ($this->states as $instance) {
      if ($instance->remainingTurns !== 0 && $instance->state->counterAttack !== null) {
        $rules[] = $instance->state->counterAttack;
      }
    }
    return $rules;
  }
}
