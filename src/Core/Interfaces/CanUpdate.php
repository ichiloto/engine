<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanUpdate is an interface implemented by all classes that can update.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanUpdate
{
  /**
   * Updates the object.
   */
  public function update(): void;
}