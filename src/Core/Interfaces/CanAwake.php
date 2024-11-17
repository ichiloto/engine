<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanAwake is an interface implemented by all classes that can awake.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanAwake
{
  /**
   * Awakes the object.
   */
  public function awake(): void;

  /**
   * Destroys the object.
   */
  public function destroy(): void;
}