<?php

namespace Ichiloto\Engine\Events\Interfaces;

use Ichiloto\Engine\Events\Enumerations\EventType;

/**
 * EventTargetInterface is the interface implemented by all event target classes.
 *
 * @since 1.0
 * @version 1.0
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface EventTargetInterface
{
  /**
   * Adds an event listener to the event target.
   *
   * @param EventType $type The type of the event.
   * @param callable $listener The listener to add.
   * @param bool $useCapture Whether to use capture or not.
   * @return void
   */
  public function addEventListener(EventType $type, callable $listener, bool $useCapture = false): void;

  /**
   * Removes an event listener from the event target.
   *
   * @param EventType $type The type of the event.
   * @param callable $listener The listener to remove.
   * @param bool $useCapture Whether to use capture or not.
   * @return void
   */
  public function removeEventListener(EventType $type, callable $listener, bool $useCapture = false): void;

  /**
   * Dispatches an event to the event target.
   *
   * @param EventInterface $event The event to dispatch.
   * @return bool True if the event was dispatched, false otherwise.
   */
  public function dispatchEvent(EventInterface $event): bool;
}