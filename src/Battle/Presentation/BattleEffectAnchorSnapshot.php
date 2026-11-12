<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** One presentation capture owns its source ground and its selected recipients independently. */
final readonly class BattleEffectAnchorSnapshot
{
  private Vector2 $casterGround;

  public function __construct(public BattleEffectTargetGroup $targets, Vector2 $casterGround)
  {
    if (!is_finite($casterGround->x) || !is_finite($casterGround->y)) {
      throw new InvalidArgumentException('Captured caster ground requires finite coordinates.');
    }
    $this->casterGround = clone $casterGround;
  }

  /** Copies keep the held source point independent of later presentation movement. */
  public function getCasterGround(): Vector2 { return clone $this->casterGround; }
}
