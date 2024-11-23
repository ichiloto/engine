<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * The interface CanFocus.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanFocus
{
  /**
   * Focuses the element.
   *
   * @return void
   */
  public function focus(): void;

  /**
   * Blurs the element.
   *
   * @return void
   */
  public function blur(): void;
}