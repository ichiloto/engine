<?php

namespace Ichiloto\Engine\Animations;

/**
 * Describes where an animation should anchor relative to its target.
 *
 * @package Ichiloto\Engine\Animations
 */
enum AnimationTargetPosition: string
{
  case CENTER = 'center';
  case HEAD = 'head';
  case FEET = 'feet';
  case SCREEN = 'screen';

  /**
   * Resolves a position from stored data.
   *
   * @param string|null $value The stored value.
   * @return self
   */
  public static function fromValue(?string $value): self
  {
    return self::tryFrom(strtolower((string) $value)) ?? self::CENTER;
  }
}
