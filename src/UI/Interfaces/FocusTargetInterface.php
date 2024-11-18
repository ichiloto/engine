<?php

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Events\Interfaces\EventInterface;

/**
 * Interface FocusTargetInterface. Represents an element that can be focused.
 *
 * @package Ichiloto\Engine\UI\Interfaces
 */
interface FocusTargetInterface
{
  /**
   * Called when the element is focused.
   *
   * @param EventInterface $event The event that is being observed.
   * @return void
   */
  public function onFocus(EventInterface $event): void;

  /**
   * Called when the element is blurred.
   *
   * @param EventInterface $event The event that is being observed.
   * @return void
   */
  public function onBlur(EventInterface $event): void;
}