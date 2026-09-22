<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues as V;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Authoring values shared by the finite title decoration treatments. */
final class TitleMotionValues
{
  public static function getColor(mixed $rgb): array
  {
    $parts = V::getList($rgb, 3, 3);
    if (!array_all($parts, static fn($value) => is_int($value))) { throw new InvalidArgumentException('Title motion colors require RGB integers.'); }
    return PresentationColor::rgb($parts[0], $parts[1], $parts[2])->toArray();
  }

  public static function getPositive(mixed $value, float $maximum = 16384): float
  {
    $number = V::getNumber($value, 0, $maximum);
    if ($number <= 0) { throw new InvalidArgumentException('Title motion requires a positive value.'); }
    return $number;
  }

  public static function getWave(mixed $waves, float $time): float
  {
    $value = 0;
    foreach (V::getList($waves, 0, 8) as $wave) {
      [$amplitude, $frequency, $phase] = V::getList($wave, 3, 3);
      $value += V::getNumber($amplitude, -128, 128) * sin($time * V::getNumber($frequency, 0, 100) + V::getNumber($phase, -100, 100));
    }
    return $value;
  }

  public static function getStops(mixed $stops, ?float $intensity = null): array
  {
    return array_map(static function ($stop) use ($intensity): array {
      V::validateKeys($stop, ['offset', 'color', ...($intensity === null ? ['opacity'] : ['base', 'gain'])]);
      $opacity = $intensity === null ? V::getNumber($stop['opacity'], 0, 1)
        : max(0, min(1, V::getNumber($stop['base'], 0, 1) + $intensity * V::getNumber($stop['gain'], -1, 1)));
      return ['offset' => V::getNumber($stop['offset'], 0, 1), 'color' => self::getColor($stop['color']), 'opacity' => $opacity];
    }, V::getList($stops, 2, 8));
  }

  public static function getBounds(array $points, float $padding = 0): array
  {
    $x = max(0, min(array_column($points, 0)) - $padding);
    $y = max(0, min(array_column($points, 1)) - $padding);
    return ['x' => $x, 'y' => $y,
      'width' => min(1350, max(array_column($points, 0)) + $padding) - $x,
      'height' => min(720, max(array_column($points, 1)) + $padding) - $y];
  }
}
