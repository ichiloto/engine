<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** A response belongs to its original command, never the reactor's ordinary turn. */
final readonly class CounterAttack
{
  public function __construct(
    public CharacterInterface $actor,
    public CharacterInterface $target,
    public SkillBattleAction $action,
    public CounterAttackRule $rule,
  ) {}
}
