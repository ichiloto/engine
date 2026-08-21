<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;

/**
 * AchievementEvent is raised when an achievement is unlocked.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class AchievementEvent extends Event
{
  /**
   * Creates a new event.
   *
   * @param string $achievementId The id of the achievement the event refers to.
   * @param EventTargetInterface|null $target The target of the event.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    protected string $achievementId = '',
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::ACHIEVEMENT, $target, $timestamp);
  }

  /**
   * Gets the id of the achievement the event refers to.
   *
   * @return string The achievement id.
   */
  public function getAchievementId(): string
  {
    return $this->achievementId;
  }
}
