<?php

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;

/**
 * Describes a screen-space presentation that participates in occlusion.
 */
interface LayeredPresentationInterface
{
  /** Returns the presentation's current terminal-cell footprint. */
  public function getPresentationBounds(): Rect;

  /** Returns the presentation's precedence relative to other layers. */
  public function getPresentationPriority(): PresentationPriority;
}
