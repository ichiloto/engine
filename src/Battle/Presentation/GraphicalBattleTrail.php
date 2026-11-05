<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/** Sample an authored wake on the same immutable path as the released flight. */
final class GraphicalBattleTrail
{
  public static function compose(string $id, BattlePresentationPath $path, int $frame, array $track,
    BattleCanvasLayout $arena): ?CanvasComposite
  {
    $arrival = $track['flight']['arrivalFrame'];
    $detach = $track['detachFrame'];
    $end = $track['fadeEndFrame'];
    if ($frame < $detach || $frame >= $end) { return null; }

    $profile = $track['profile'];
    $progress = min(1, ($frame - $detach) / max(1, $arrival - $detach));
    $opacity = min(1, ($end - $frame) / max(1, $end - $arrival));
    $travel = $path->distance * $progress;
    $length = min($travel, $profile['length'] * ($profile['fadeLengthRatio'] + (1 - $profile['fadeLengthRatio']) * $opacity));
    $steps = max(1, (int)ceil($length / $profile['spacing']));
    $dabs = [];
    $left = $arena->width;
    $top = $arena->height;
    $right = $bottom = 0;
    for ($index = 0; $index <= $steps; $index++) {
      $fraction = $index / $steps;
      $distance = $travel - $length * (1 - $fraction);
      $point = $path->getPositionAtProgress($path->distance > 0 ? min(1, max(0, $distance / $path->distance)) : 1);
      $radius = $profile['startRadius'] + ($profile['endRadius'] - $profile['startRadius']) * $fraction ** $profile['radiusPower'];
      $strength = $profile['startOpacity'] + ($profile['endOpacity'] - $profile['startOpacity']) * $fraction ** $profile['opacityPower'];
      if ($point->x + $radius <= 0 || $point->y + $radius <= 0
        || $point->x - $radius >= $arena->width || $point->y - $radius >= $arena->height || $strength === 0.0) {
        continue;
      }
      $dabs[] = ['center' => [$point->x, $point->y], 'radius' => $radius, 'opacity' => $strength * $opacity];
      $left = min($left, max(0, (int)floor($point->x - $radius)));
      $top = min($top, max(0, (int)floor($point->y - $radius)));
      $right = max($right, min($arena->width, (int)ceil($point->x + $radius)));
      $bottom = max($bottom, min($arena->height, (int)ceil($point->y + $radius)));
    }
    if ($dabs === []) { return null; }
    foreach ($dabs as &$dab) {
      $dab['center'][0] -= $left;
      $dab['center'][1] -= $top;
    }
    unset($dab);

    $width = $right - $left;
    $height = $bottom - $top;
    $paint = new CanvasCompositeOperation(['type' => 'coverage_dabs', 'dabs' => $dabs, ...$profile['brush']]);
    return new CanvasComposite($id, $width, $height, new CanvasRectangle($left, $top, $width, $height),
      [$paint], $track['depth'] === 'behind' ? 15 : 160);
  }
}
