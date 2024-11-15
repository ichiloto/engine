<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanStart is an interface implemented by all classes that can start.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanStart
{
  /**
   * Starts the object.
   */
  public function start(): void;

  /**
   * Stops the object.
   */
  public function stop(): void;
}