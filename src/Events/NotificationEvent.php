<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\NotificationEventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * Class NotificationEvent. Represents a notification event.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class NotificationEvent extends Event
{
  /**
   * NotificationEvent constructor.
   *
   * @param NotificationEventType $notificationEventType The notification event type.
   * @param EventTargetInterface|null $target The event target.
   * @param DateTimeInterface $timestamp The event timestamp.
   */
  public function __construct(
    public NotificationEventType $notificationEventType,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable(),
  )
  {
    parent::__construct(
      EventType::NOTIFICATION,
      $target,
      $timestamp,
    );
  }
}