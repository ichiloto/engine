<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasTextureStrip};
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;
use Ichiloto\Engine\Animations\Timelines\{EffectGroundSpan, EffectGroundSpanOrientation};

/** Fits a complete source rigidly between two captured ground anchors. */
final class GraphicalBattleGroundSpan
{
  public static function parseSource(mixed $source): array
  {
    return EffectGroundSpan::fromArray($source)->toArray();
  }

  /** One uniform scale and a rigid rotation; the two zero-offset knots retain a straight image. */
  public static function createStrip(BattleEffectAnchorSnapshot $snapshot, SpriteSourceRect $crop,
    array $source, PresentationSpritePivot $rear, bool $flipX = false, bool $flipY = false): CanvasTextureStrip
  {
    return self::createProjection($snapshot, $crop, $source, $rear, $flipX, $flipY)['strip'];
  }

  /** Geometry and actual source flips belong to one projection; no deformation or resampling.
   * @return array{strip: CanvasTextureStrip, flipX: bool, flipY: bool}
   */
  public static function createProjection(BattleEffectAnchorSnapshot $snapshot, SpriteSourceRect $crop,
    array $source, PresentationSpritePivot $rear, bool $flipX = false, bool $flipY = false, ?int $recipient = null, float $revealProgress = 1): array
  {
    $definition = EffectGroundSpan::fromArray($source);
    $tip = $definition->tip->toArray();
    $rearX = $flipX ? 1 - $rear->x : $rear->x; $rearY = $flipY ? 1 - $rear->y : $rear->y;
    $tipX = $flipX ? 1 - $tip['x'] : $tip['x']; $tipY = $flipY ? 1 - $tip['y'] : $tip['y'];
    $sourceX = ($tipX - $rearX) * $crop->width; $sourceY = ($tipY - $rearY) * $crop->height;
    $sourceReach = hypot($sourceX, $sourceY);
    $start = $snapshot->getGroundSpanStart($definition->casterPoint);
    $end = $snapshot->getGroundSpanEndpoint($recipient);
    $dx = $end->x - $start->x; $dy = $end->y - $start->y; $reach = hypot($dx, $dy);
    if ($sourceReach <= 0 || $reach <= 0) {
      throw new InvalidArgumentException('A captured ground span requires distinct source and destination endpoints.');
    }
    $scale = $reach / $sourceReach;
    $angle = atan2($dy, $dx) - atan2($sourceY, $sourceX);
    if ($definition->orientation === EffectGroundSpanOrientation::UPRIGHT) {
      $reflectedAngle = atan2($dy, $dx) - atan2($sourceY, -$sourceX);
      if (cos($reflectedAngle) > cos($angle)) {
        $flipX = !$flipX; $rearX = 1 - $rearX; $sourceX = -$sourceX; $angle = $reflectedAngle;
      }
      if (cos($angle) <= PHP_FLOAT_EPSILON * 4) {
        throw new InvalidArgumentException('groundSpan.orientation upright cannot keep this source top above its bottom at the captured endpoints. Use a compatible source or the explicit rigid orientation.');
      }
    }
    $cos = cos($angle); $sin = sin($angle);
    $x = $rearX * $crop->width * $scale; $y = $rearY * $crop->height * $scale;
    $strip = new CanvasTextureStrip([
      'origin' => [$start->x - $x * $cos + $y * $sin, $start->y - $x * $sin - $y * $cos],
      'axis' => [$cos, $sin], 'length' => $crop->width * $scale, 'height' => $crop->height * $scale,
      'knots' => [['u' => 0.0, 'offset' => 0.0], ['u' => 1.0, 'offset' => 0.0]],
    ]);
    if ($revealProgress < 1) {
      $normal = [$dx / $reach, $dy / $reach];
      [$nx, $ny] = $normal;
      // Move the clip edge across the rigid image. Its pose, scale and sampling stay fixed.
      $clip = ['normal' => $normal, 'feather' => $definition->reveal?->featherPixels ?? 0,
        'limit' => ($start->x - $strip->origin[0]) * $nx + ($start->y - $strip->origin[1]) * $ny + $reach * $revealProgress];
      $strip = new CanvasTextureStrip([...$strip->toArray(), 'clipPlane' => $clip]);
    }
    return ['strip' => $strip, 'flipX' => $flipX, 'flipY' => $flipY];
  }

  public static function compose(string $id, string $asset, SpriteSourceRect $crop,
    BattleEffectAnchorSnapshot $snapshot, BattleCanvasLayout $arena, array $track, ?int $recipient = null, float $revealProgress = 1): ?CanvasComposite
  {
    $projection = self::createProjection($snapshot, $crop, $track[EffectGroundSpan::FIELD],
      PresentationSpritePivot::fromArray($track['pivot']), $track['flipX'] ?? false, $track['flipY'] ?? false, $recipient, $revealProgress);
    return GraphicalBattleStrip::composeStrip($id, $asset, $crop, $projection['strip'], $arena,
      [...$track, 'flipX' => $projection['flipX'], 'flipY' => $projection['flipY']],
      opacity: $revealProgress < 1 ? (float)($track[EffectGroundSpan::FIELD]['reveal']['beforeContactOpacity'] ?? 1) : 1);
  }
}
