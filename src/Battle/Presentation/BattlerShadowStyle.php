<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngGroundContact;
use InvalidArgumentException;

/**
 * The theme's contact shadow, drawn under every battler's feet on the arena's
 * ground so artwork need not paint one: its colour and strength at the
 * centre, how softly it fades to its rim (0 a hard edge, 1 fading from the
 * centre), and how wide it reaches beyond each foot. A grounded battler's
 * shadow follows its art's own ground contact ({@see PngGroundContact}): a
 * soft shape joining an ellipse around each foot, paw, hem or lying body,
 * `contactFlatness` as tall as it is wide, darker at each foot's core
 * (`contactCore`, a share of its strength). Where the art cannot be read, and
 * under a battler in the air, it is one ellipse at the feet point: its width
 * a share of the footprint (a lying or four-legged body's length, a standing
 * body's height times `standing`), `flatness` as tall as wide, shrinking and
 * fading as the battler rises (per reference height). Arenas tint or strengthen it ({@see BattleShadowLight})
 * and each battler may scale or opt out of it ({@see BattlerGrounding}).
 */
final readonly class BattlerShadowStyle
{
  public function __construct(
    public PresentationColor $color,
    public float $opacity = .45,
    public float $softness = .6,
    public float $width = .9,
    public float $standing = .45,
    public float $flatness = .28,
    public float $hoverFade = 1.0,
    public float $contactFlatness = .38,
    public float $contactCore = .5,
  ) {
    foreach ([$opacity, $softness, $width, $standing, $flatness, $hoverFade, $contactFlatness, $contactCore] as $value) {
      if (!is_finite($value)) { throw new InvalidArgumentException('Battler shadow style values must be finite.'); }
    }
    if ($opacity < 0 || $opacity > 1 || $softness < 0 || $softness > 1 || $width <= 0 || $width > 4
      || $standing <= 0 || $standing > 4 || $flatness <= 0 || $flatness > 1 || $hoverFade < 0 || $hoverFade > 16
      || $contactFlatness <= 0 || $contactFlatness > 1 || $contactCore < 0 || $contactCore > 1) {
      throw new InvalidArgumentException('Battler shadow style: opacity, softness and contact core within 0..1, width and standing within 0..4, flatness and contact flatness within 0..1, hover fade within 0..16.');
    }
  }
}
