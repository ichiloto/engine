<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** Presentation selection never changes effect identity or dispatches cues. */
enum EffectPresentation: string
{
  case TERMINAL = 'terminal';
  case GRAPHICAL = 'graphical';

  public static function validateTrack(mixed $value): string
  {
    if (!is_string($value) || !in_array($value, ['all', self::TERMINAL->value, self::GRAPHICAL->value], true)) {
      throw new InvalidArgumentException('Effect track presentation must be all, terminal or graphical.');
    }
    return $value;
  }

  public function acceptsSegment(array $segment): bool
  {
    $scope = self::validateTrack($segment['presentation'] ?? 'all');
    return ($scope === 'all' || $scope === $this->value)
      && ($this !== self::TERMINAL || ($segment['layer'] ?? '') !== 'image');
  }
}
