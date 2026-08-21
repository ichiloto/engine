<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Ordered per-target action result used by live UI, effects and simulation. */
final readonly class CombatActionResult
{
  /** @param CombatTargetResult[] $targets */
  public function __construct(
    public string $actionId,
    public string $executionId,
    public string $actorId,
    public array $targets,
  )
  {
  }

  /** @return CombatHitResult[] */
  public function hits(): array
  {
    if ($this->targets === []) {
      return [];
    }

    return array_merge(...array_map(static fn(CombatTargetResult $target): array => $target->hits, $this->targets));
  }

  public function actualHpLost(): int
  {
    return array_sum(array_map(static fn(CombatTargetResult $target): int => $target->actualHpLost(), $this->targets));
  }

  public function actualHpRestored(): int
  {
    return array_sum(array_map(static fn(CombatTargetResult $target): int => $target->actualHpRestored(), $this->targets));
  }

  public function mitigation(): int
  {
    return array_sum(array_map(static fn(CombatTargetResult $target): int => $target->mitigation(), $this->targets));
  }

  public function hitCount(): int
  {
    return count(array_filter($this->hits(), static fn(CombatHitResult $hit): bool => $hit->hit));
  }

  public function targetCount(): int
  {
    return count($this->targets);
  }

  public function defeatedTargetCount(): int
  {
    return count(array_filter($this->targets, static fn(CombatTargetResult $target): bool => $target->wasDefeated()));
  }
}
