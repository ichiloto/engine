<?php

namespace Ichiloto\Engine\Events\Interfaces;

/**
 * EventListenerInterface is the interface implemented by all event listener classes.
 *
 * @since 1.0
 * @version 1.0
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface EventListenerInterface
{
  /**
   * Handles the given event.
   *
   * @param EventInterface $event The event to handle.
   * @return void
   */
  public function handle(EventInterface $event): void;

  /**
   * Gets the unique id of the event listener.
   *
   * @return string The unique id of the event listener.
   */
  public function getUniqueId(): string;
}