<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * Represents an object that can change selection.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanChangeSelection
{
  /**
   * Selects the previous item.
   *
   * @return void
   */
  public function selectPrevious(): void;

  /**
   * Selects the next item.
   *
   * @return void
   */
  public function selectNext(): void;
}