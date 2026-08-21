<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Fully typed observable result of one resolved hit. */
final readonly class CombatHitResult
{
  public function __construct(
    public string $actionId,
    public string $executionId,
    public string $actorId,
    public string $targetId,
    public bool $hit,
    public string $missReason,
    public int $rawMagnitude,
    public ResolutionKind $kind,
    public int $offensiveInput,
    public int $defensiveInput,
    public int $mitigationAmount,
    public float $mitigationRate,
    public bool $criticalEligible,
    public ?int $criticalRoll,
    public bool $critical,
    public float $criticalMultiplier,
    public bool $guardApplied,
    public ?string $element,
    public ElementalOutcome $elementalOutcome,
    public float $elementalMultiplier,
    public int $preEffectHp,
    public int $requestedHpChange,
    public int $actualHpLost,
    public int $actualHpRestored,
    public int $overkill,
    public int $postEffectHp,
    public ?int $hitRoll,
    public int $hitChance,
  )
  {
  }
}
