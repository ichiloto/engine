<?php

namespace Ichiloto\Engine\Battle\Resolution;

use InvalidArgumentException;

/** Bounded generic seam for effects derived from actual per-action HP loss. */
final readonly class ActualHpLossAggregatePolicy
{
  public function __construct(
    public float $share,
    public ?int $absoluteCeiling = null,
  )
  {
    if ($this->share < 0.0 || $this->share > CombatCalibration::FUTURE_DRAIN_AGGREGATE_CEILING) {
      throw new InvalidArgumentException(sprintf(
        'Actual HP-loss share must be between 0 and %.2f.',
        CombatCalibration::FUTURE_DRAIN_AGGREGATE_CEILING,
      ));
    }

    if ($this->absoluteCeiling !== null && $this->absoluteCeiling < 0) {
      throw new InvalidArgumentException('Actual HP-loss absolute ceiling cannot be negative.');
    }
  }

  public function magnitudeFor(CombatActionResult $result): int
  {
    $magnitude = intval(round($result->actualHpLost() * $this->share, 0, PHP_ROUND_HALF_UP));

    return $this->absoluteCeiling === null ? $magnitude : min($magnitude, $this->absoluteCeiling);
  }
}
