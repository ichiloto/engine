<?php

namespace Ichiloto\Engine\Battle\Resolution;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use InvalidArgumentException;

/** Immutable input for one hit against one target. */
final readonly class CombatResolutionRequest
{
  public function __construct(
    public string $actionId,
    public string $executionId,
    public CharacterInterface $actor,
    public CharacterInterface $target,
    public int $rawMagnitude,
    public ResolutionKind $kind,
    public ?string $element = null,
    public ?int $baseAccuracy = 95,
    public bool $criticalEligible = true,
    public bool $guaranteedCritical = false,
    public int $baseCriticalChance = 5,
    public float $criticalMultiplier = CombatCalibration::CRITICAL_MULTIPLIER,
    public bool $guardEligible = true,
    public int $minimumDamage = CombatCalibration::MINIMUM_DAMAGE,
    public ?int $hitRollOverride = null,
    public ?int $criticalRollOverride = null,
  )
  {
    if (trim($this->actionId) === '' || trim($this->executionId) === '') {
      throw new InvalidArgumentException('Combat action and execution identities cannot be empty.');
    }

    if ($this->rawMagnitude < 0 || $this->criticalMultiplier < 1.0 || $this->minimumDamage < 0) {
      throw new InvalidArgumentException('Combat magnitude, critical multiplier, or minimum damage is invalid.');
    }

    if ($this->guaranteedCritical && ! $this->criticalEligible) {
      throw new InvalidArgumentException('A guaranteed Critical must also be Critical-eligible.');
    }

    foreach ([$this->hitRollOverride, $this->criticalRollOverride] as $roll) {
      if ($roll !== null && ($roll < 1 || $roll > 100)) {
        throw new InvalidArgumentException('Combat roll overrides must be between 1 and 100.');
      }
    }
  }
}
