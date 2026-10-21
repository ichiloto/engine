<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/**
 * A battler's contact shadow and hover, shared by the battle and every
 * formation preview: the shadow lies on the arena's ground at the battler's
 * feet point, wherever the battler moves, sized by its footprint and shrinking
 * and fading as its body hovers higher, in the theme's style under the
 * arena's light. The body rises by its hover; its feet point (where effects,
 * staging and the shadow meet the ground) never does. The Terminal battle
 * never reads any of it.
 */
final class BattlerContactShadow
{
  /** Shadows lie on the ground, under every battler's body. */
  public const int LAYER = 90;

  private function __construct() {}

  /**
   * How many reference heights a battler's body hovers over its feet: its
   * own, else the theme's flier height for an air enemy, else none. A
   * knocked-out battler lies on the ground.
   */
  public static function getHover(?BattlerGrounding $grounding, ?EnemyHabitat $habitat, BattleCanvasLayout $layout,
    bool $knockedOut = false): float
  {
    if ($knockedOut) { return 0.0; }
    return $grounding?->hover ?? ($habitat === EnemyHabitat::AIR ? $layout->airHover : 0.0);
  }

  /**
   * How far, in canvas units, a body hovering that many reference heights
   * rises above its feet at a slot's perspective; a battler without a body
   * profile hovers by its slot's own height.
   */
  public static function getLift(float $hover, BattlerSlot $slot, ?BattleScale $scale): float
  {
    return $hover * ($scale?->referenceHeight ?? $slot->height) * $slot->displayScale;
  }

  /**
   * The shadow under a battler, or none where the theme draws none, the
   * battler's artwork paints its own or the battler opts out.
   *
   * @param float $footprint The battler's body span at its slot: a lying or four-legged body's length, a standing body's height.
   * @param bool $horizontal Whether the body lies along the ground rather than standing.
   * @param float $opacity How visible the battler itself is (a fading defeat, a subdued knockout).
   */
  public static function compose(string $id, float $feetX, float $feetY, float $footprint, bool $horizontal,
    float $hover, BattleCanvasLayout $layout, ?BattleArenaDefinition $arena, ?BattlerGrounding $grounding,
    float $opacity = 1.0): ?CanvasComposite
  {
    $style = $layout->shadow;
    $source = $grounding?->shadow ?? BattlerShadowSource::ENGINE;
    if ($style === null || $source !== BattlerShadowSource::ENGINE || $footprint <= 0 || $opacity <= 0) { return null; }
    $light = $arena?->shadowLight ?? new BattleShadowLight();
    $fade = 1 / (1 + $style->hoverFade * $hover);
    $width = $footprint * ($horizontal ? 1.0 : $style->standing) * $style->width * ($grounding?->shadowWidth ?? 1.0) * $fade;
    $strength = min(1.0, $style->opacity * $light->strength * ($grounding?->shadowOpacity ?? 1.0) * $fade * $opacity);
    $pixels = (int) max(2, min(1024, round($width)));
    $height = (int) max(2, round($pixels * $style->flatness));
    if ($strength <= 0) { return null; }
    $color = ($light->color ?? $style->color)->toArray();
    $inner = 1 - $style->softness;
    $stops = [['offset' => 0, 'color' => $color, 'opacity' => 1.0],
      ...($inner > 0.001 && $inner < 0.999 ? [['offset' => $inner, 'color' => $color, 'opacity' => 1.0]] : []),
      ...($inner >= 0.999 ? [['offset' => 0.999, 'color' => $color, 'opacity' => 1.0]] : []),
      ['offset' => 1, 'color' => $color, 'opacity' => 0.0]];
    $operation = new CanvasCompositeOperation(['type' => 'fill',
      'destination' => ['x' => 0, 'y' => 0, 'width' => $pixels, 'height' => $height],
      'brush' => ['type' => 'radial', 'center' => [$pixels / 2, $height / 2], 'radius' => [$pixels / 2, $height / 2],
        'stops' => $stops]]);
    $x = max(0.0, min($layout->width - $pixels, $feetX - $pixels / 2));
    $y = max(0.0, min($layout->height - $height, $feetY - $height / 2));
    return new CanvasComposite($id, $pixels, $height, new CanvasRectangle($x, $y, $pixels, $height), [$operation],
      self::LAYER, $strength);
  }
}
