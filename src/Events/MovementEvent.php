<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * Represents a movement event.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class MovementEvent extends Event
{
  /**
   * MovementEvent constructor.
   *
   * @param MovementEventType $movementEventType The movement event type.
   * @param EventTargetInterface|null $target The event target.
   * @param DateTimeInterface $timestamp The event timestamp.
   */
  public function __construct(
    public MovementEventType $movementEventType,
    public Vector2 $origin,
    public Vector2 $destination,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::MOVEMENT, $target, $timestamp);
  }
}