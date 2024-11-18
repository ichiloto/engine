<?php

namespace Ichiloto\Engine\UI\Interfaces;

/**
 * Interface CanFocus. This interface is for elements that can be focused.
 *
 * @package Ichiloto\Engine\UI\Interfaces
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
   * Blur's the element. The opposite of focus.
   *
   * @return void
   */
  public function blur(): void;
}