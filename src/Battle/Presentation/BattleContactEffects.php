<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * The theme's surface effects where a sunk battler meets the surface: a
 * ripple in water and a burrow in the ground. Each is a sprite sheet the
 * game's art supplies ({@see BattlerPose}: frames, rate, looping, and its
 * pivot on the surface line, stretched to the battler's footprint), drawn in
 * front of the body's waterline or groundline. Without art the Engine draws
 * a plain placeholder in these colours, so a game is never left without one.
 */
final readonly class BattleContactEffects
{
  public PresentationColor $rippleColor;
  public PresentationColor $burrowColor;

  public function __construct(
    public ?BattlerPose $ripple = null,
    public ?BattlerPose $burrow = null,
    ?PresentationColor $rippleColor = null,
    ?PresentationColor $burrowColor = null,
  ) {
    $this->rippleColor = $rippleColor ?? PresentationColor::rgb(222, 236, 244);
    $this->burrowColor = $burrowColor ?? PresentationColor::rgb(84, 62, 42);
  }
}
