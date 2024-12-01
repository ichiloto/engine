<?php

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanActivate;
use Ichiloto\Engine\Core\Interfaces\CanRender;

/**
 * Interface UIElementInterface. The interface for all UI elements.
 *
 * @package Ichiloto\Engine\UI\Interfaces
 */
interface UIElementInterface extends CanRender, CanActivate
{
  /**
   * Returns whether the UI element is active.
   *
   * @return bool Whether the UI element is active.
   */
  public bool $isActive {
    get;
  }
}