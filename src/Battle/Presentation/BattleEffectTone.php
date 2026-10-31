<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;

/** Intrinsic actor appearance; no extra surface, pixel copy or gameplay state. */
final class BattleEffectTone
{
  /** @return array<int, float> Stable instance identity to brightness multiplier. */
  public static function getMultipliers(BattleEffectPlayback $playback, bool $reducedMotion): array
  {
    $multipliers = [];
    foreach ($playback->getActiveSegments($reducedMotion) as $segment) {
      if ($segment['layer'] !== 'tone') { continue; }
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $data = $command['payload'];
        $subjects = ($data['anchor'] ?? 'target') === 'caster' ? [$playback->actor] : $playback->targets;
        $seen = [];
        foreach ($subjects as $subject) {
          $id = spl_object_id($subject);
          if (isset($seen[$id])) { continue; }
          $seen[$id] = true;
          $multipliers[$id] = ($multipliers[$id] ?? 1.0) * $data['brightness'];
        }
      }
    }
    return $multipliers;
  }

  public static function applyToImage(CanvasImage $image, float $multiplier): CanvasImage
  {
    return $multiplier === 1.0 ? $image : new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
      $image->sourceRect, $image->opacity, $image->clipRect, $image->brightness * $multiplier, $image->flipX, $image->flipY);
  }
}
