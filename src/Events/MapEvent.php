<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\MapEventType;
use Ichiloto\Engine\Events\Event;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * MapEvent represents a map event.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class MapEvent extends Event
{
  /**
   * MapEvent constructor.
   *
   * @param MapEventType $mapEventType The type of map event.
   * @param EventTargetInterface|null $target The event target.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    public MapEventType   $mapEventType,
    ?EventTargetInterface $target = null,
    DateTimeInterface     $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::MAP, $target, $timestamp);
  }
}