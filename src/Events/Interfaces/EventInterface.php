<?php

namespace Ichiloto\Engine\Events\Interfaces;

use Ichiloto\Engine\Events\Enumerations\EventType;

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
}