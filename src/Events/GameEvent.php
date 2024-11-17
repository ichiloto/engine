<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\GameEventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * GameEvent is the base class for all game events.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class GameEvent extends Event
{
  /**
   * Creates a new event.
   *
   * @param GameEventType $gameEventType
   * @param EventTargetInterface|null $target The target of the event.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    protected GameEventType $gameEventType,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::GAME, $target, $timestamp);
  }

  /**
   * Gets the type of the game event.
   *
   * @return GameEventType The type of the game event.
   */
  public function getGameEventType(): GameEventType
  {
    return $this->gameEventType;
  }
}