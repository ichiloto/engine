<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Conservative all-cold work admission; native output reuse may reduce actual work. */
final class CanvasCompositeBudget
{
  public const int MAX_WORK = 268435456;

  public static function getWork(array $composites): int
  {
    $work = 0;
    foreach ($composites as $composite) {
      $work += 2 * $composite->width * $composite->height;
      foreach ($composite->operations as $operation) {
        $data = $operation->data;
        if ($data['type'] === 'stroke') {
          $half = $data['width'] / 2;
          $left = min(array_column($data['points'], 0)) - $half;
          $top = min(array_column($data['points'], 1)) - $half;
          $right = max(array_column($data['points'], 0)) + $half;
          $bottom = max(array_column($data['points'], 1)) + $half;
        } else {
          $rect = $data['destination'];
          $left = $rect['x']; $top = $rect['y'];
          $right = $left + $rect['width']; $bottom = $top + $rect['height'];
        }
        $pixels = (int)(max(0, min($composite->width, ceil($right)) - max(0, floor($left)))
          * max(0, min($composite->height, ceil($bottom)) - max(0, floor($top))));
        $cost = match ($data['type']) {
          'image' => 16 + (isset($data['displacement']) ? 8 : 0),
          'fill' => 4, 'stroke' => 4 + count($data['points']),
        };
        foreach ([$data['masks'], $data['displacement']['masks'] ?? []] as $masks) {
          if ($masks === []) { continue; }
          $cost += 2;
          foreach ($masks as $mask) {
            $cost += match ($mask['type']) {
              'polygon' => array_sum(array_map(count(...), $mask['contours'])), 'ellipse' => 8, 'image_alpha' => 16,
            };
          }
        }
        $work += $pixels * $cost;
        if ($work > self::MAX_WORK) { throw new InvalidArgumentException('Canvas composites exceed the bounded cold raster work budget.'); }
      }
    }
    return $work;
  }
}
