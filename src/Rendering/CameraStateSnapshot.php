<?php

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Core\Vector2;

/** Restorable camera ownership and viewport state. */
final readonly class CameraStateSnapshot
{
  public function __construct(
    public Vector2 $position,
    public bool $followsPlayer,
  )
  {
  }
}
