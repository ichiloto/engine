<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanEnable is an interface implemented by all classes that can enable.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanEnable
{
  /**
   * Enables the object.
   */
  public function enable(): void;

  /**
   * Disables the object.
   */
  public function disable(): void;
}