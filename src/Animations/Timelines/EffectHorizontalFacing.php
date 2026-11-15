<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** Horizontal orientation shared by authored drawings and resolved battle geometry. */
enum EffectHorizontalFacing: string
{
  case EAST = 'east';
  case WEST = 'west';

  public function getSign(): int { return $this === self::EAST ? 1 : -1; }
}
