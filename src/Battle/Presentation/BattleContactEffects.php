<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * The theme's surface reactions where a sunk battler meets the surface: a
 * ripple in water and a burrow in the ground, each a back layer behind the
 * body and a front lip over it ({@see BattleSurfaceEffect}), art the game
 * supplies. Without art the Engine draws a quiet placeholder in these
 * colours (a faint ring behind the body and a thin lip along its front edge,
 * never across it), so a game is never left without one.
 */
final readonly class BattleContactEffects
{
  public PresentationColor $rippleColor;
  public PresentationColor $burrowColor;

  public function __construct(
    public BattleSurfaceEffect $ripple = new BattleSurfaceEffect(),
    public BattleSurfaceEffect $burrow = new BattleSurfaceEffect(),
    ?PresentationColor $rippleColor = null,
    ?PresentationColor $burrowColor = null,
  ) {
    $this->rippleColor = $rippleColor ?? PresentationColor::rgb(222, 236, 244);
    $this->burrowColor = $burrowColor ?? PresentationColor::rgb(84, 62, 42);
  }
}
