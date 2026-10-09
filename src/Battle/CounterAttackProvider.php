<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\States\State;

/** Optional combat capability; existing CharacterInterface implementations remain valid. */
interface CounterAttackProvider
{
  /** @return list<CounterAttackRule> Rules granted by current definitions and live ownership. */
  public function getCounterAttackRules(): array;

  public function getActionBlockingState(): ?State;
}
