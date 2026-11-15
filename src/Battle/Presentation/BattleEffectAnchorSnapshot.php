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

  /** The farthest selected ground X, at the selected group's held ground depth. */
  public function getGroundSpanEndpoint(): Vector2
  {
    $farthest = $opposite = null; $reach = -1.0;
    foreach ($this->targets->grounds as $point) {
      if (!is_finite($point->x) || !is_finite($point->y)) {
        throw new InvalidArgumentException('Captured ground spans require finite selected ground points.');
      }
      $distance = abs($point->x - $this->casterGround->x);
      if ($distance > $reach) { $reach = $distance; $farthest = $opposite = $point->x; }
      elseif ($distance === $reach) {
        $farthest = min($farthest, $point->x); $opposite = max($opposite, $point->x);
      }
    }
    if ($farthest === null) { throw new InvalidArgumentException('Captured ground span requires selected recipients.'); }
    if ($farthest !== $opposite) {
      throw new InvalidArgumentException('Captured ground span has opposite equally far endpoints.');
    }
    return new Vector2($farthest, $this->targets->getGroundOrigin()->y);
  }

  /** Copies keep the held source point independent of later presentation movement. */
  public function getCasterGround(): Vector2 { return clone $this->casterGround; }
}
