<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanActivate is an interface implemented by all classes that can activate.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanActivate
{
  /**
   * Activates the object.
   */
  public function activate(): void;

  /**
   * Deactivates the object.
   */
  public function deactivate(): void;
}