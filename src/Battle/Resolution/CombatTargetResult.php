<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Ordered hit results and actual aggregates for one target. */
final readonly class CombatTargetResult
{
  /**
   * @param CombatHitResult[] $hits
   * @param array<int, array{type: string, id: string, applied: bool}> $secondaryOutcomes
   */
  public function __construct(
    public string $targetId,
    public array $hits,
    public array $secondaryOutcomes = [],
  )
  {
  }

  public function actualHpLost(): int
  {
    return array_sum(array_map(static fn(CombatHitResult $hit): int => $hit->actualHpLost, $this->hits));
  }

  public function actualHpRestored(): int
  {
    return array_sum(array_map(static fn(CombatHitResult $hit): int => $hit->actualHpRestored, $this->hits));
  }

  public function mitigation(): int
  {
    return array_sum(array_map(static fn(CombatHitResult $hit): int => $hit->mitigationAmount, $this->hits));
  }

  public function wasDefeated(): bool
  {
    $lastHit = $this->hits[array_key_last($this->hits)] ?? null;

    return $lastHit?->postEffectHp === 0 && $lastHit->actualHpLost > 0;
  }
}
