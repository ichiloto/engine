<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Action-owned execution checks shared by presentation and resource spending. */
interface ExecutionEligibility
{
  /** Null authorizes execution; otherwise return a concise player-facing refusal. */
  public function getExecutionRefusal(CharacterInterface $actor): ?string;
}
