<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\Event;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

readonly class ModalEvent extends Event
{
  /**
   * ModalEvent constructor.
   *
   * @param ModalEventType $modalEventType The type of modal event.
   * @param mixed $value The value of the event.
   * @param EventTargetInterface|null $target The target of the event.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    public ModalEventType $modalEventType,
    public mixed $value,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::MODAL, $target, $timestamp);
  }
}