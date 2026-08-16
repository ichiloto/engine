<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * The resurrection effect. This effect is used to bring a character back to life.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class ResurrectionEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if ($target->isKnockedOut) {
      $magnitude = $this->valueBasis === ValueBasis::PERCENTAGE
        ? intval(round($target->stats->totalHp * (floatval($this->value) / 100), 0, PHP_ROUND_HALF_UP))
        : intval($this->value);
      $this->lastResult = new CombatResolver()->resolve(new CombatResolutionRequest(
        actionId: 'effect.resurrection',
        executionId: 'effect.resurrection:1',
        actor: $target,
        target: $target,
        rawMagnitude: max(1, $magnitude),
        kind: ResolutionKind::HEALING,
        baseAccuracy: null,
        criticalEligible: false,
        guardEligible: false,
        minimumDamage: 0,
      ));
    }
  }
}
