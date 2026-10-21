<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngGroundContact;

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
   * @param CanvasImage|null $drawn The art as drawn this frame, whose own ground contact a grounded battler's shadow follows.
   * @param bool $lying Whether the art shows the body lying down (a knockout), meeting the ground along its whole lower edge.
   * @return array{images: list<CanvasImage>, composites: list<CanvasComposite>}
   */
  public static function compose(string $id, float $feetX, float $feetY, float $footprint, bool $horizontal, float $drawnWidth,
    float $elevation, BattlerContact $contact, BattleCanvasLayout $layout, ?BattleArenaDefinition $arena,
    ?BattlerGrounding $grounding, string $assetRoot, float $elapsedSeconds = 0, bool $reducedMotion = false,
    float $opacity = 1.0, ?CanvasImage $drawn = null, bool $lying = false): array
  {
    $none = ['images' => [], 'composites' => []];
    if ($opacity <= 0 || $contact === BattlerContact::NONE) { return $none; }
    $strength = $grounding?->contactOpacity ?? 1.0;
    $scale = $grounding?->contactWidth ?? 1.0;
    if ($contact === BattlerContact::SHADOW) {
      // A battler on the ground casts its shadow where its art meets the ground; one in the air, under its feet point.
      $measured = $elevation <= 0 && $drawn !== null ? PngGroundContact::getContact($assetRoot, $drawn->asset, $drawn->sourceRect) : null;
      $patches = $measured === null ? [] : $measured[$lying ? 'edge' : 'patches'];
      if ($patches !== []) {
        $shadow = self::composeContactShadow($id, $drawn, $measured, $patches, $layout, $arena, $scale, $strength * $opacity);
        return $shadow === null ? $none : ['images' => [], 'composites' => [$shadow]];
      }
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

  /**
   * The shadow where a grounded battler's art meets the ground: an ellipse
   * around each contact patch, joined into one soft shape (so a wide or
   * staggered stance is grounded at every foot, the ground between them
   * shaded as under a body), darker at each foot's core.
   *
   * @param array{top: float, bottom: float} $measured The art's extent.
   * @param list<array{0: float, 1: float, 2: float}> $patches
   */
  private static function composeContactShadow(string $id, CanvasImage $drawn, array $measured, array $patches, BattleCanvasLayout $layout,
    ?BattleArenaDefinition $arena, float $scale, float $opacity): ?CanvasComposite
  {
    $style = $layout->shadow;
    if ($style === null) { return null; }
    $light = $arena?->shadowLight ?? new BattleShadowLight();
    $strength = min(1.0, $style->opacity * $light->strength * $opacity);
    if ($strength <= 0) { return null; }
    $box = $drawn->destination;
    $place = static fn(float $share): float => $box->x + ($drawn->flipX ? 1 - $share : $share) * $box->width;
    // A foot's shadow reaches past it by the theme's width, and is never smaller than a small share of the art's height.
    $least = .045 * ($measured['bottom'] - $measured['top']) * $box->height;
    $feet = [];
    foreach ($patches as [$left, $right, $low]) {
      [$from, $to] = [min($place($left), $place($right)), max($place($left), $place($right))];
      $radius = max($least, ($to - $from) / 2 * $style->width * 1.25) * $scale;
      $feet[] = [($from + $to) / 2, $box->y + ($drawn->flipY ? 1 - $low : $low) * $box->height, $radius,
        max($least * .5, $radius * $style->contactFlatness)];
    }
    $points = [];
    foreach ($feet as [$x, $y, $rx, $ry]) {
      for ($step = 0; $step < 16; $step++) {
        $points[] = [$x + cos($step * M_PI / 8) * $rx, $y + sin($step * M_PI / 8) * $ry];
      }
    }
    $hull = self::getHull($points);
    $xs = array_column($hull, 0);
    $ys = array_column($hull, 1);
    $left = max(0.0, floor(min($xs)));
    $top = max(0.0, floor(min($ys)));
    $width = (int) max(2, min(1024, ceil(min($layout->width, max($xs)) - $left)));
    $height = (int) max(2, min(1024, ceil(min($layout->height, max($ys)) - $top)));
    $local = static fn(array $point): array => [round($point[0] - $left, 2), round($point[1] - $top, 2)];
    // The renderer takes up to 128 distinct corners: thin a long outline evenly, then drop any that coincide.
    $outline = array_map($local, count($hull) > 128 ? array_values(array_filter($hull,
      static fn(int $index): bool => $index % (int) ceil(count($hull) / 128) === 0, ARRAY_FILTER_USE_KEY)) : $hull);
    $outline = array_values(array_filter($outline, static fn(array $point, int $index): bool =>
      $point !== $outline[($index + 1) % count($outline)], ARRAY_FILTER_USE_BOTH));
    if (count($outline) < 3) { return null; }
    $color = ($light->color ?? $style->color)->toArray();
    $destination = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
    $operations = [new CanvasCompositeOperation(['type' => 'fill', 'destination' => $destination,
      'brush' => ['type' => 'solid', 'color' => $color],
      'masks' => [['type' => 'polygon', 'contours' => [$outline],
        // Feather by the shape's own thickness (twice its area over its outline), not its extent: a steep stance's thin
        // band and a lying body's broad shadow each soften across their width.
        'feather' => max(1.0, self::getThickness($hull) * $style->softness * .5)]]])];
    foreach ($style->contactCore > 0 ? array_slice($feet, 0, 128) : [] as [$x, $y, $rx, $ry]) {
      [$cx, $cy] = $local([$x, $y]);
      $operations[] = new CanvasCompositeOperation(['type' => 'fill', 'destination' => $destination, 'opacity' => $style->contactCore,
        'brush' => ['type' => 'solid', 'color' => $color],
        'masks' => [['type' => 'ellipse', 'center' => [$cx, $cy], 'radius' => [$rx * .8, $ry * .8], 'feather' => $ry * .8]]]);
    }
    return new CanvasComposite($id, $width, $height, new CanvasRectangle($left, $top, $width, $height), $operations,
      self::SHADOW_LAYER, $strength);
  }

  /**
   * A convex shape's typical thickness: twice its area over its perimeter.
   *
   * @param list<array{0: float, 1: float}> $hull
   */
  private static function getThickness(array $hull): float
  {
    $area = $perimeter = 0.0;
    foreach ($hull as $index => [$x, $y]) {
      [$nx, $ny] = $hull[($index + 1) % count($hull)];
      $area += $x * $ny - $nx * $y;
      $perimeter += hypot($nx - $x, $ny - $y);
    }
    return $perimeter > 0 ? abs($area) / $perimeter : 0.0;
  }

  /**
   * The convex hull of points, counter-clockwise without repeats.
   *
   * @param list<array{0: float, 1: float}> $points
   * @return list<array{0: float, 1: float}>
   */
  private static function getHull(array $points): array
  {
    usort($points, static fn(array $one, array $two): int => [$one[0], $one[1]] <=> [$two[0], $two[1]]);
    $cross = static fn(array $o, array $a, array $b): float => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    $chain = static function (array $sorted) use ($cross): array {
      $chain = [];
      foreach ($sorted as $point) {
        while (count($chain) >= 2 && $cross($chain[count($chain) - 2], $chain[count($chain) - 1], $point) <= 0) { array_pop($chain); }
        $chain[] = $point;
      }
      array_pop($chain);
      return $chain;
    };
    return [...$chain($points), ...$chain(array_reverse($points))];
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
