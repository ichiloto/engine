<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanRender is an interface implemented by all classes that can render.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanRender
{
  /**
   * Renders the object.
   */
  public function render(): void;

  /**
   * Erases the object.
   */
  public function erase(): void;
}