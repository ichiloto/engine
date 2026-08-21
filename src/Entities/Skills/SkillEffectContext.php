<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\NativeCombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * Represents the skill effect context.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
class SkillEffectContext
{
  /**
   * SkillEffectContext constructor.
   *
   * @param CharacterInterface $user
   * @param CharacterInterface|CharacterInterface[] $target
   */
  public function __construct(
    protected(set) CharacterInterface $user,
    protected(set) CharacterInterface|array $target,
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
    protected(set) string $actionId = 'skill-effect',
    protected(set) string $executionId = 'skill-effect:1',
    protected(set) ?int $baseAccuracy = null,
    protected(set) ResolutionKind $damageKind = ResolutionKind::PHYSICAL_DAMAGE,
    protected(set) SkillResolutionScope $hitScope = SkillResolutionScope::PER_HIT,
    protected(set) SkillResolutionScope $criticalScope = SkillResolutionScope::PER_HIT,
    ?SkillRollLedger $rollLedger = null,
  )
  {
    $this->resolver = $resolver ?? new CombatResolver();
    $this->random = $random ?? new NativeCombatRandomSource();
    $this->rollLedger = $rollLedger ?? new SkillRollLedger();
  }

  public CombatResolver $resolver;
  public CombatRandomSource $random;
  public SkillRollLedger $rollLedger;

  /** @var CombatHitResult[] */
  private array $results = [];
  /** @var array<int, array{type: string, id: string, applied: bool}> */
  private array $secondaryOutcomes = [];

  /**
   * @var bool True when the action rolled a critical hit for this target.
   */
  public bool $criticalHit = false;

  public function recordResult(CombatHitResult $result): void
  {
    $this->results[] = $result;
    $this->criticalHit = $result->critical;
  }

  /** @return CombatHitResult[] */
  public function results(): array
  {
    return $this->results;
  }

  public function hitRollOverride(): ?int
  {
    return $this->rollLedger->roll(
      'hit',
      $this->hitScope,
      $this->executionId,
      CombatResolver::identity(is_array($this->target) ? $this->target[0] : $this->target),
      $this->random,
    );
  }

  public function criticalRollOverride(): ?int
  {
    return $this->rollLedger->roll(
      'critical',
      $this->criticalScope,
      $this->executionId,
      CombatResolver::identity(is_array($this->target) ? $this->target[0] : $this->target),
      $this->random,
    );
  }

  public function hadSuccessfulResolution(): bool
  {
    return array_any($this->results, static fn(CombatHitResult $result): bool => $result->hit);
  }

  public function recordSecondaryOutcome(string $type, string $id, bool $applied): void
  {
    $this->secondaryOutcomes[] = [
      'type' => trim($type),
      'id' => trim($id),
      'applied' => $applied,
    ];
  }

  /** @return array<int, array{type: string, id: string, applied: bool}> */
  public function secondaryOutcomes(): array
  {
    return $this->secondaryOutcomes;
  }
}
