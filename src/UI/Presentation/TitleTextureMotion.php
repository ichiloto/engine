<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues as V;
use InvalidArgumentException;

/** Drift and flow registrations sample the current scene painting, never a baked copy. */
final class TitleTextureMotion
{
  public static function getDriftOperations(string $asset, array $data, float $time, float $weight): array
  {
    V::validateKeys($data, ['polygons', 'amplitude', 'seconds', 'feather'], ['exclusions']);
    $amplitude = V::getPoint($data['amplitude'], -512, 512);
    $phase = sin($time / TitleMotionValues::getPositive($data['seconds']));
    $feather = V::getNumber($data['feather'], 0, 4096);
    $exclusions = [];
    foreach (V::getList($data['exclusions'] ?? [], 0, 6) as $exclusion) {
      V::validateKeys($exclusion, ['center', 'radius', 'feather']);
      $exclusions[] = ['type' => 'ellipse', 'center' => V::getPoint($exclusion['center']),
        'radius' => V::getPoint($exclusion['radius'], 0), 'feather' => V::getNumber($exclusion['feather'], 0, 4096), 'invert' => true];
    }
    $operations = [];
    foreach (V::getList($data['polygons'], 1, 8) as $polygon) {
      $points = array_map(V::getPoint(...), V::getList($polygon, 3, 128));
      $bounds = TitleMotionValues::getBounds($points);
      $mask = ['type' => 'polygon', 'contours' => [$points]];
      $operations[] = ['type' => 'image', 'asset' => $asset, 'destination' => $bounds,
        'source' => self::getSource($bounds), 'opacity' => $weight, 'masks' => [$mask],
        'displacement' => ['columns' => 2, 'rows' => 2,
          'offsets' => array_fill(0, 4, [-$amplitude[0] * $phase, -$amplitude[1] * $phase]),
          'masks' => [[...$mask, 'feather' => $feather], ...$exclusions]]];
    }
    return $operations;
  }

  public static function getWaterOperations(string $asset, array $data, float $time, float $weight): array
  {
    V::validateKeys($data, ['paths', 'cycleSeconds', 'refractionAmplitude', 'refractionWavelength',
      'refractionSeconds', 'streakColor', 'streakOpacity', 'mistColor']);
    $cycle = TitleMotionValues::getPositive($data['cycleSeconds']);
    $amplitude = V::getNumber($data['refractionAmplitude'], 0, 128);
    $wavelength = TitleMotionValues::getPositive($data['refractionWavelength']);
    $speed = TitleMotionValues::getPositive($data['refractionSeconds']);
    $streakColor = TitleMotionValues::getColor($data['streakColor']);
    $streakOpacity = V::getNumber($data['streakOpacity'], 0, 1);
    $mistColor = TitleMotionValues::getColor($data['mistColor']);
    $operations = [];
    foreach (V::getList($data['paths'], 1, 6) as $index => $fall) {
      V::validateKeys($fall, ['points', 'widths']);
      $points = array_map(V::getPoint(...), V::getList($fall['points'], 2, 32));
      $widths = array_map(static fn($n) => TitleMotionValues::getPositive($n, 256), V::getList($fall['widths'], count($points), count($points)));
      for ($i = 1; $i < count($points); $i++) {
        if ($points[$i][1] <= $points[$i - 1][1]) { throw new InvalidArgumentException('Water path points must descend monotonically.'); }
      }
      $left = $right = [];
      foreach ($points as $i => [$x, $y]) { $left[] = [$x - $widths[$i] / 2, $y]; $right[] = [$x + $widths[$i] / 2, $y]; }
      $sides = [...$left, ...array_reverse($right)];
      $bounds = TitleMotionValues::getBounds($sides);
      $offsets = [];
      $rows = min(64, max(2, (int)ceil($bounds['height'] / 2) + 1));
      for ($row = 0; $row < $rows; $row++) {
        $p = $row / ($rows - 1);
        $y = $bounds['y'] + $bounds['height'] * $p;
        $flow = $amplitude * sin($y / $wavelength - $time / $speed) * sin(M_PI * $p);
        array_push($offsets, [0, -$flow], [0, -$flow]);
      }
      $operations[] = ['type' => 'image', 'asset' => $asset, 'source' => self::getSource($bounds), 'destination' => $bounds,
        'opacity' => .88 * $weight, 'masks' => [['type' => 'polygon', 'contours' => [$sides]]],
        'displacement' => ['columns' => 2, 'rows' => $rows, 'offsets' => $offsets]];
      for ($lane = 0; $lane < 7; $lane++) {
        for ($streak = 0; $streak < 5; $streak++) {
          $p = fmod($time / $cycle + $streak / 5 + $lane * .143 + $index * .217, 1);
          $a = self::getPathPoint($points, $widths, $p);
          $b = self::getPathPoint($points, $widths, min(1, $p + .055 + ($lane % 3) * .018));
          $offset = ($lane - 3) / 3;
          $operations[] = ['type' => 'stroke', 'points' => [[$a[0] + $offset * $a[2] / 2, $a[1]], [$b[0] + $offset * $b[2] / 2, $b[1]]],
            'width' => $lane % 3 === 0 ? 1.5 : .8, 'blend' => 'screen',
            'opacity' => $streakOpacity * sin(M_PI * $p) * (.55 + .45 * sin($lane * 2.3 + $streak) ** 2) * $weight,
            'brush' => ['type' => 'solid', 'color' => $streakColor]];
        }
      }
      [$x, $y, $width] = self::getPathPoint($points, $widths, 1);
      $radius = $width * 1.3;
      $pulse = .5 + .5 * sin($time / .760 + $index * 1.9);
      $operations[] = ['type' => 'fill', 'destination' => TitleMotionValues::getBounds([[$x - $radius, $y - 5], [$x + $radius, $y + 5]]),
        'blend' => 'screen', 'opacity' => (.10 + .09 * $pulse) * $weight,
        'brush' => ['type' => 'radial', 'center' => [$x, $y], 'radius' => [$radius, $radius],
          'stops' => [['offset' => 0, 'color' => $mistColor], ['offset' => 1, 'color' => $mistColor, 'opacity' => 0]]],
        'masks' => [['type' => 'ellipse', 'center' => [$x, $y], 'radius' => [$radius, 5]]]];
    }
    return $operations;
  }

  private static function getPathPoint(array $points, array $widths, float $progress): array
  {
    $scaled = $progress * (count($points) - 1);
    $index = min(count($points) - 2, (int)floor($scaled));
    $fraction = $scaled - $index;
    return [$points[$index][0] + ($points[$index + 1][0] - $points[$index][0]) * $fraction,
      $points[$index][1] + ($points[$index + 1][1] - $points[$index][1]) * $fraction,
      $widths[$index] + ($widths[$index + 1] - $widths[$index]) * $fraction];
  }

  private static function getSource(array $bounds): array
  {
    return ['x' => $bounds['x'] / PresentationCanvas::DEFAULT_WIDTH, 'y' => $bounds['y'] / PresentationCanvas::DEFAULT_HEIGHT,
      'width' => $bounds['width'] / PresentationCanvas::DEFAULT_WIDTH, 'height' => $bounds['height'] / PresentationCanvas::DEFAULT_HEIGHT];
  }
}
