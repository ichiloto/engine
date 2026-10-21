<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * Where a battler meets the surface, shared by the battle and every
 * formation preview. Its feet point stays on the surface line, where staging,
 * footing, effects and its contact meet the ground; its body rises by a
 * positive elevation or sinks by a negative one, the art below the surface
 * line hidden, so a creature rises out of water or pokes out of the ground
 * without art painted half-buried. Its contact is drawn there: a soft shadow
 * under the feet (shrinking and fading as the body rises), the water's or
 * ground's reaction around a sunk body (a back layer behind it and a front
 * lip over its surface line), or nothing. The Terminal
 * battle never reads any of it.
 */
final class BattlerGroundContact
{
  /** Shadows lie on the ground under every body. */
  public const int SHADOW_LAYER = 90;
  /** A ripple's or burrow's back layer rises around the body, behind it. */
  public const int SURFACE_BACK_LAYER = 95;
  /** Its front lip lies over the body's waterline or groundline. */
  public const int SURFACE_FRONT_LAYER = 101;

  private function __construct() {}

  /**
   * A battler's elevation in reference heights: its own, else the theme's
   * height for an air enemy, else none. A knocked-out battler lies on the
   * ground, and artwork that paints its own shadow (the pose shown, else the
   * battler's) keeps the height it was painted at.
   */
  public static function getElevation(?BattlerGrounding $grounding, ?EnemyHabitat $habitat, BattleCanvasLayout $layout,
    bool $knockedOut = false, ?BattlerPose $pose = null): float
  {
    if ($knockedOut || self::isPaintedShadow($grounding, $pose)) { return 0.0; }
    return $grounding?->elevation ?? ($habitat === EnemyHabitat::AIR ? $layout->airElevation : 0.0);
  }

  /** What is drawn where it meets the surface: its own choice, else its habitat's and elevation's; none over a painted shadow. */
  public static function getContact(?BattlerGrounding $grounding, ?EnemyHabitat $habitat, float $elevation,
    ?BattlerPose $pose = null): BattlerContact
  {
    if (self::isPaintedShadow($grounding, $pose)) { return BattlerContact::NONE; }
    return $grounding?->contact ?? BattlerContact::getDefault($habitat, $elevation);
  }

  /** Whether the art shown paints its own shadow: the pose's say, else the battler's. */
  public static function isPaintedShadow(?BattlerGrounding $grounding, ?BattlerPose $pose): bool
  {
    return $pose?->bakedShadow ?? $grounding?->bakedShadow ?? false;
  }

  /** The canvas distance an elevation raises (positive) or sinks (negative) the body at a slot's perspective. */
  public static function getOffset(float $elevation, BattlerSlot $slot, ?BattleScale $scale): float
  {
    return $elevation * ($scale?->referenceHeight ?? $slot->height) * $slot->displayScale;
  }

  /**
   * Drawn bounds raised or sunk by an offset, kept on the canvas, and for a
   * sunk body the part above the surface line, which is all that shows.
   *
   * @return array{0: CanvasRectangle, 1: ?CanvasRectangle} The bounds and the clip, none for a body at or above the surface.
   */
  public static function place(CanvasRectangle $bounds, float $offset, float $surfaceY, BattleCanvasLayout $layout): array
  {
    $y = max(0.0, min($layout->height - $bounds->height, $bounds->y - $offset));
    $placed = new CanvasRectangle($bounds->x, $y, $bounds->width, $bounds->height);
    if ($offset >= 0) { return [$placed, null]; }
    return [$placed, new CanvasRectangle($bounds->x, $y, $bounds->width, max(1.0, min($bounds->height, $surfaceY - $y)))];
  }

  /**
   * What is drawn where a battler meets the surface at its feet point.
   *
   * @param float $footprint The body span at its slot: a lying or four-legged body's length, a standing body's height.
   * @param float $drawnWidth How wide its art is drawn, which a ripple or burrow rings.
   * @param float $opacity How visible the battler itself is (a fading defeat, a subdued knockout).
   * @return array{images: list<CanvasImage>, composites: list<CanvasComposite>}
   */
  public static function compose(string $id, float $feetX, float $feetY, float $footprint, bool $horizontal, float $drawnWidth,
    float $elevation, BattlerContact $contact, BattleCanvasLayout $layout, ?BattleArenaDefinition $arena,
    ?BattlerGrounding $grounding, string $assetRoot, float $elapsedSeconds = 0, bool $reducedMotion = false,
    float $opacity = 1.0): array
  {
    $none = ['images' => [], 'composites' => []];
    if ($opacity <= 0 || $contact === BattlerContact::NONE) { return $none; }
    $strength = $grounding?->contactOpacity ?? 1.0;
    $scale = $grounding?->contactWidth ?? 1.0;
    if ($contact === BattlerContact::SHADOW) {
      $shadow = self::composeShadow($id, $feetX, $feetY, $footprint * $scale, $horizontal, max(0.0, $elevation), $layout, $arena,
        $strength * $opacity);
      return $shadow === null ? $none : ['images' => [], 'composites' => [$shadow]];
    }
    $effects = $layout->contactEffects;
    $width = max(2.0, min(1024.0, $drawnWidth * .9 * $scale));
    $effect = $contact === BattlerContact::RIPPLE ? $effects->ripple : $effects->burrow;
    $alpha = min(1.0, $strength * $opacity);
    if ($effect->hasArt()) {
      $images = [];
      foreach ([[$effect->back, self::SURFACE_BACK_LAYER, 'back'], [$effect->front, self::SURFACE_FRONT_LAYER, 'front']] as [$sheet, $layer, $part]) {
        $frame = $sheet?->getArtwork($assetRoot, $elapsedSeconds, $reducedMotion);
        if ($frame === null) { continue; }
        $height = $width * $frame->height / $frame->width;
        $x = max(0.0, min($layout->width - $width, $feetX - $frame->pivotX / $frame->width * $width));
        $y = max(0.0, min($layout->height - $height, $feetY - $frame->pivotY / $frame->height * $height));
        $images[] = new CanvasImage("{$id}-{$part}", $frame->asset, new CanvasRectangle($x, $y, $width, $height), $layer,
          $frame->sourceRect, $alpha);
      }
      return ['images' => $images, 'composites' => []];
    }
    $color = $contact === BattlerContact::RIPPLE ? $effects->rippleColor : $effects->burrowColor;
    return ['images' => [], 'composites' => self::composePlaceholder($id, $feetX, $feetY, $width, $contact, $color, $layout, $alpha)];
  }

  private static function composeShadow(string $id, float $feetX, float $feetY, float $footprint, bool $horizontal, float $height,
    BattleCanvasLayout $layout, ?BattleArenaDefinition $arena, float $opacity): ?CanvasComposite
  {
    $style = $layout->shadow;
    if ($style === null || $footprint <= 0) { return null; }
    $light = $arena?->shadowLight ?? new BattleShadowLight();
    $fade = 1 / (1 + $style->hoverFade * $height);
    $width = $footprint * ($horizontal ? 1.0 : $style->standing) * $style->width * $fade;
    $strength = min(1.0, $style->opacity * $light->strength * $fade * $opacity);
    if ($strength <= 0) { return null; }
    $color = ($light->color ?? $style->color)->toArray();
    $inner = 1 - $style->softness;
    $stops = [['offset' => 0, 'color' => $color, 'opacity' => 1.0],
      ...($inner > 0.001 ? [['offset' => min($inner, .999), 'color' => $color, 'opacity' => 1.0]] : []),
      ['offset' => 1, 'color' => $color, 'opacity' => 0.0]];
    return self::composeEllipse($id, $feetX, $feetY, $width, $style->flatness, $stops, $layout, self::SHADOW_LAYER, $strength);
  }

  /**
   * The Engine's quiet stand-in until art supplies the effect: a faint ring
   * behind the body where the surface rises around it, and a thin lip only
   * along the ring's front edge, below the body's middle, so nothing lies
   * across the body itself.
   *
   * @return list<CanvasComposite>
   */
  private static function composePlaceholder(string $id, float $feetX, float $feetY, float $width, BattlerContact $contact,
    PresentationColor $color, BattleCanvasLayout $layout, float $opacity): array
  {
    $value = $color->toArray();
    $ring = $contact === BattlerContact::RIPPLE
      ? [['offset' => 0, 'color' => $value, 'opacity' => 0.0], ['offset' => .62, 'color' => $value, 'opacity' => 0.0],
        ['offset' => .8, 'color' => $value, 'opacity' => .5], ['offset' => 1, 'color' => $value, 'opacity' => 0.0]]
      : [['offset' => 0, 'color' => $value, 'opacity' => 0.0], ['offset' => .55, 'color' => $value, 'opacity' => .15],
        ['offset' => .8, 'color' => $value, 'opacity' => .6], ['offset' => 1, 'color' => $value, 'opacity' => 0.0]];
    $lip = [['offset' => 0, 'color' => $value, 'opacity' => 0.0], ['offset' => .86, 'color' => $value, 'opacity' => 0.0],
      ['offset' => .93, 'color' => $value, 'opacity' => .7], ['offset' => 1, 'color' => $value, 'opacity' => 0.0]];
    $back = self::composeEllipse("{$id}-back", $feetX, $feetY, $width, .3, $ring, $layout, self::SURFACE_BACK_LAYER, .55 * $opacity);
    // The lip covers only the ring's front edge: the part of the ellipse below its centre line, outside the body's middle.
    $front = self::composeEllipse("{$id}-front", $feetX, $feetY, $width, .3, $lip, $layout, self::SURFACE_FRONT_LAYER, .5 * $opacity,
      static fn(int $pixels, int $height): array => [['type' => 'polygon', 'contours' => [[[0, $height / 2], [$pixels, $height / 2],
        [$pixels, $height], [0, $height]]]]]);
    return [$back, $front];
  }

  /** @param list<array{offset: float|int, color: array, opacity: float}> $stops */
  private static function composeEllipse(string $id, float $feetX, float $feetY, float $width, float $flatness, array $stops,
    BattleCanvasLayout $layout, int $layer, float $opacity, ?\Closure $masks = null): CanvasComposite
  {
    $pixels = (int) max(2, min(1024, round($width)));
    $height = (int) max(2, round($pixels * $flatness));
    $operation = new CanvasCompositeOperation(['type' => 'fill',
      'destination' => ['x' => 0, 'y' => 0, 'width' => $pixels, 'height' => $height],
      'brush' => ['type' => 'radial', 'center' => [$pixels / 2, $height / 2], 'radius' => [$pixels / 2, $height / 2], 'stops' => $stops],
      ...($masks === null ? [] : ['masks' => $masks($pixels, $height)])]);
    $x = max(0.0, min($layout->width - $pixels, $feetX - $pixels / 2));
    $y = max(0.0, min($layout->height - $height, $feetY - $height / 2));
    return new CanvasComposite($id, $pixels, $height, new CanvasRectangle($x, $y, $pixels, $height), [$operation], $layer, $opacity);
  }
}
