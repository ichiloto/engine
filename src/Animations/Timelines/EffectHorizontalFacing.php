<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** The authored horizontal direction of a drawing, independent of its recipient. */
enum EffectHorizontalFacing: string
{
  case EAST = 'east';
  case WEST = 'west';

  public function getSign(): int { return $this === self::EAST ? 1 : -1; }
}
