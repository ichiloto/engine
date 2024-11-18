<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\MenuEventType;
use Ichiloto\Engine\Events\Event;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * MenuEvent represents a menu event.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class MenuEvent extends Event
{
  public function __construct(
    protected MenuEventType $menuEventType,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::MENU, $target, $timestamp);
  }

  /**
   * Returns the type of menu event.
   *
   * @return MenuEventType The type of menu event.
   */
  public function getMenuEventType(): MenuEventType
  {
    return $this->menuEventType;
  }
}