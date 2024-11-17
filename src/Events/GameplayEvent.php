<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * GamePlayEvent is the base class for all game play events.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class GameplayEvent extends Event
{
  /**
   * Constructs a new instance of the GamePlayEvent class.
   *
   * @param GameplayEventType $gameplayEventType The type of GamePlayEvent.
   * @param EventTargetInterface|null $target The event target. Defaults to null.
   * @param DateTimeInterface $timestamp The timestamp. Defaults to now.
   */
  public function __construct(
    protected GameplayEventType $gameplayEventType,
    ?EventTargetInterface       $target = null,
    DateTimeInterface           $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(
      EventType::GAME_PLAY,
      $target,
      $timestamp
    );
  }

  /**
   * Returns the type of GamePlayEvent.
   *
   * @return GameplayEventType The type of GamePlayEvent.
   */
  public function getGameplayEventType(): GameplayEventType
  {
    return $this->gameplayEventType;
  }
}