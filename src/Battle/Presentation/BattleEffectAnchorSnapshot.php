<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** One presentation capture owns its source ground and its selected recipients independently. */
final readonly class BattleEffectAnchorSnapshot
{
  private Vector2 $casterGround;
  /** @var array<string, Vector2> */
  private array $casterPoints;

  /** @param array<string, Vector2> $casterPoints Points projected from the held caster pose cell. */
  public function __construct(public BattleEffectTargetGroup $targets, Vector2 $casterGround, array $casterPoints = [])
  {
    if (!is_finite($casterGround->x) || !is_finite($casterGround->y)) {
      throw new InvalidArgumentException('Captured caster ground requires finite coordinates.');
    }
    $this->casterGround = clone $casterGround;
    $this->casterPoints = array_map(static fn(Vector2 $point): Vector2 => clone $point, $casterPoints);
  }

  public function getGroundSpanStart(?string $casterPoint): Vector2
  {
    if ($casterPoint === null) { return $this->getCasterGround(); }
    $point = $this->casterPoints[$casterPoint] ?? null;
    if ($point === null || !is_finite($point->x) || !is_finite($point->y)) {
      throw new InvalidArgumentException('Captured caster pose has no ground-span point ' . $casterPoint . '.');
    }
    return clone $point;
  }

  /** A recipient's captured ground, or the farthest selected ground X at the group's held depth. */
  public function getGroundSpanEndpoint(?int $recipient = null): Vector2
  {
    if ($recipient !== null) {
      $point = $this->targets->grounds[$recipient] ?? null;
      if ($point === null || !is_finite($point->x) || !is_finite($point->y)) {
        throw new InvalidArgumentException('Captured ground span requires the selected recipient ground point.');
      }
      return clone $point;
    }
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
