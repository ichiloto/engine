<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * Event is the base class for all events.
 *
 * @package Ichiloto\Engine\Events
 */
abstract readonly class Event implements EventInterface
{
  /**
   * Creates a new event.
   *
   * @param EventType $type The type of the event.
   * @param EventTargetInterface|null $target The target of the event.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    protected EventType $type,
    protected ?EventTargetInterface $target = null,
    protected DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function getType(): \Ichiloto\Engine\Events\Enumerations\EventType
  {
    return $this->type;
  }

  /**
   * @inheritDoc
   */
  public function getTypeAsString(): string
  {
    return $this->type->value;
  }

  /**
   * @inheritDoc
   */
  public function getTarget(): ?EventTargetInterface
  {
    return $this->target;
  }

  /**
   * @inheritDoc
   */
  public function getTimestamp(): DateTimeInterface
  {
    return $this->timestamp;
  }
}