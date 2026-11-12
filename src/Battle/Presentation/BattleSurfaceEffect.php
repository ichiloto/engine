<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/**
 * How the surface reacts where a sunk battler breaks it (water parting,
 * earth heaving): a back layer drawn behind the body, where the surface
 * rises around it, and a front lip drawn over the body's waterline or
 * groundline. Either is optional; each is a sprite sheet with its pivot on
 * the surface line, stretched to the battler's drawn width, and two aligned
 * sheets share one placement.
 */
final readonly class BattleSurfaceEffect
{
  public function __construct(public ?BattlerPose $back = null, public ?BattlerPose $front = null) {}

  /** Whether the game supplies any art for it; without, the Engine draws a quiet placeholder. */
  public function hasArt(): bool
  {
    return $this->back !== null || $this->front !== null;
  }
}
