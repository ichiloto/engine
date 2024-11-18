<?php

namespace Ichiloto\Engine\Core\Interfaces;

interface CanRenderAt
{
  /**
   * Renders the object at the specified coordinates.
   *
   * @param int|null $x The x-coordinate.
   * @param int|null $y The y-coordinate.
   */
  public function render(?int $x = null, ?int $y = null): void;

  /**
   * Erases the object at the specified coordinates.
   *
   * @param int|null $x The x-coordinate.
   * @param int|null $y The y-coordinate.
   */
  public function erase(?int $x = null, ?int $y = null): void;
}