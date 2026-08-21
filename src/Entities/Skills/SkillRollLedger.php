<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;

/** Shares authored skill rolls at their declared action or target scope. */
final class SkillRollLedger
{
  /** @var array<string, int> */
  private array $rolls = [];

  public function roll(
    string $kind,
    SkillResolutionScope $scope,
    string $executionId,
    string $targetId,
    CombatRandomSource $random,
  ): ?int
  {
    if ($scope === SkillResolutionScope::PER_HIT) {
      return null;
    }

    $key = match ($scope) {
      SkillResolutionScope::PER_ACTION => "{$kind}:{$executionId}",
      SkillResolutionScope::PER_TARGET => "{$kind}:{$executionId}:{$targetId}",
      SkillResolutionScope::PER_HIT => throw new \LogicException('Per-hit rolls are not cached.'),
    };

    return $this->rolls[$key] ??= $random->nextInt(1, 100);
  }
}
