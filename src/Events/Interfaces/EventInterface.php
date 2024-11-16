<?php

namespace Ichiloto\Engine\Events\Interfaces;

use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;

/**
 * EventInterface is the interface implemented by all event classes.
 *
 * @since 1.0
 * @version 1.0
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface EventInterface
{
  /**
   * Gets the type of the event.
   *
   * @return EventType The type of the event.
   */
  public function getType(): EventType;

  /**
   * Returns the type of the event as a string.
   *
   * @return string The type of the event as a string.
   */
  public function getTypeAsString(): string;

  /**
   * Gets the target of the event.
   *
   * @return EventTargetInterface|null The target of the event.
   */
  public function getTarget(): ?EventTargetInterface;

  /**
   * Gets the timestamp of the event.
   *
   * @return DateTimeInterface The timestamp of the event.
   */
  public function getTimestamp(): DateTimeInterface;
}