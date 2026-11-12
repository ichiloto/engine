<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** Motion variants select drawings; the authored command clock and cues remain shared. */
enum EffectMotionScope: string
{
  case ALL = 'all';
  case NORMAL = 'normal';
  case REDUCED = 'reduced';

  public static function parse(mixed $value): self
  {
    return is_string($value) ? (self::tryFrom($value)
      ?? throw new InvalidArgumentException('Effect motion must be all, normal or reduced.'))
      : throw new InvalidArgumentException('Effect motion must be all, normal or reduced.');
  }

  /** Filter before clear/depth composition, so an unused variant cannot erase the active one. */
  public static function filterSegments(array $segments, bool $reducedMotion): array
  {
    $wanted = $reducedMotion ? self::REDUCED : self::NORMAL;
    return array_values(array_filter($segments, static function (array $segment) use ($wanted): bool {
      $scope = self::parse($segment['motion'] ?? 'all');
      return $scope === self::ALL || $scope === $wanted;
    }));
  }
}
