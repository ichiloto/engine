<?php

namespace Ichiloto\Engine\Events\Interfaces;

/**
 * The observer interface.
 *
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface ObserverInterface
{
  /**
   * This method is called when an event is fired.
   *
   * @template T
   * @param object<T> $entity The entity that is being observed.
   * @param EventInterface $event The event that is being observed.
   */
  public function onNotify(object $entity, EventInterface $event): void;
}