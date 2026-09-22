<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues as V;

/** A bounded two-curve flame and local glow, with artist-owned registration and waves. */
final class TitleFlameMotion
{
  public static function getOperations(array $data, float $time, float $weight): array
  {
    V::validateKeys($data, ['clip', 'shape', 'bend', 'height', 'intensity', 'gradient', 'glow']);
    V::validateKeys($data['intensity'], ['base', 'waves']);
    $intensity = V::getNumber($data['intensity']['base'], -1, 1) + TitleMotionValues::getWave($data['intensity']['waves'], $time);
    $bend = TitleMotionValues::getWave($data['bend'], $time);
    $height = TitleMotionValues::getWave($data['height'], $time);
    $controls = [];
    foreach (V::getList($data['shape'], 7, 7) as $point) {
      [$x, $y, $bx, $hy] = V::getList($point, 4, 4);
      $controls[] = [V::getNumber($x, -8192, 8192) + V::getNumber($bx, -1, 1) * $bend,
        V::getNumber($y, -8192, 8192) + V::getNumber($hy, -1, 1) * $height];
    }
    $polygon = [$controls[0]];
    foreach ([0, 3] as $start) {
      for ($step = 1; $step <= 16; $step++) {
        $t = $step / 16; $s = 1 - $t;
        $point = [];
        foreach ([0, 1] as $axis) {
          $point[] = $s ** 3 * $controls[$start][$axis] + 3 * $s ** 2 * $t * $controls[$start + 1][$axis]
            + 3 * $s * $t ** 2 * $controls[$start + 2][$axis] + $t ** 3 * $controls[$start + 3][$axis];
        }
        $polygon[] = $point;
      }
    }
    $gradient = $data['gradient']; $glow = $data['glow'];
    V::validateKeys($gradient, ['start', 'end', 'stops']);
    V::validateKeys($glow, ['center', 'radius', 'innerRadius', 'stops']);
    $center = V::getPoint($glow['center']);
    $radius = V::getPoint($glow['radius'], 0);
    $inner = V::getNumber($glow['innerRadius'], 0, min($radius) - .001) / min($radius);
    $stops = TitleMotionValues::getStops($glow['stops'], $intensity);
    // Map the reference's inner-radius plateau onto a normalized elliptical brush.
    foreach ($stops as &$stop) { $stop['offset'] = $inner + (1 - $inner) * $stop['offset']; }
    unset($stop);
    if ($inner > 0) { array_unshift($stops, [...$stops[0], 'offset' => 0]); }
    $start = V::getPoint($gradient['start']); $start[1] += $height;
    return [
      ['type' => 'fill', 'destination' => TitleMotionValues::getBounds([[$center[0] - $radius[0], $center[1] - $radius[1]],
        [$center[0] + $radius[0], $center[1] + $radius[1]]]), 'blend' => 'screen', 'opacity' => $weight,
        'brush' => ['type' => 'radial', 'center' => $center, 'radius' => $radius, 'stops' => $stops]],
      ['type' => 'fill', 'destination' => TitleMotionValues::getBounds($polygon), 'blend' => 'screen', 'opacity' => $weight,
        'brush' => ['type' => 'linear', 'start' => $start, 'end' => V::getPoint($gradient['end']),
          'stops' => TitleMotionValues::getStops($gradient['stops'], $intensity)],
        'masks' => [['type' => 'polygon', 'contours' => [$polygon]],
          ['type' => 'polygon', 'contours' => [array_map(V::getPoint(...), V::getList($data['clip'], 3, 128))]]]],
    ];
  }
}
