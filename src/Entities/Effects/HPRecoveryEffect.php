<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;
use Ichiloto\Engine\Util\Debug;

/**
 * The HP recovery effect class.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPRecoveryEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if ( $target->isKnockedOut ) {
      return;
    }

    $magnitude = $this->valueBasis === ValueBasis::PERCENTAGE
      ? intval(round($target->stats->totalHp * (floatval($this->value) / 100), 0, PHP_ROUND_HALF_UP))
      : intval($this->value);
    $this->lastResult = new CombatResolver()->resolve(new CombatResolutionRequest(
      actionId: 'effect.hp-recovery',
      executionId: 'effect.hp-recovery:1',
      actor: $target,
      target: $target,
      rawMagnitude: max(0, $magnitude),
      kind: ResolutionKind::HEALING,
      baseAccuracy: null,
      criticalEligible: false,
      guardEligible: false,
      minimumDamage: 0,
    ));
  }
}
