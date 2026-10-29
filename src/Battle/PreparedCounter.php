<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\States\StateInstance;

/** One battle's selected opponent and the exact stance instance granting a response. */
final class PreparedCounter
{
  public private(set) bool $consumed = false;

  public function __construct(
    public readonly CharacterInterface $actor,
    public readonly CharacterInterface $opponent,
    public readonly CounterAttackRule $rule,
    public readonly StateInstance $stance,
  ) {}

  public function isActive(): bool
  {
    return !$this->consumed && $this->actor->isFighting && $this->opponent->isFighting
      && $this->stance->remainingTurns !== 0
      && in_array($this->stance, $this->actor->states, true);
  }

  public function consume(): void
  {
    $this->consumed = true;
    $this->releaseStance();
  }

  public function releaseStance(): void
  {
    if (in_array($this->stance, $this->actor->states, true)) {
      $this->actor->removeState($this->stance->state->id);
    }
  }
}
