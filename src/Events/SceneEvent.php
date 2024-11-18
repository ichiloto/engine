<?php

namespace Ichiloto\Engine\Events;

use DateTimeImmutable;
use DateTimeInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/**
 * Class SceneEvent. Represents a scene event.
 *
 * @package Ichiloto\Engine\Events
 */
readonly class SceneEvent extends Event
{
  /**
   * SceneEvent constructor.
   *
   * @param SceneEventType $sceneEventType The type of scene event.
   * @param SceneInterface|null $scene The scene that triggered the event.
   * @param EventTargetInterface|null $target The target of the event.
   * @param DateTimeInterface $timestamp The timestamp of the event.
   */
  public function __construct(
    public SceneEventType $sceneEventType,
    public ?SceneInterface $scene = null,
    ?EventTargetInterface $target = null,
    DateTimeInterface $timestamp = new DateTimeImmutable()
  )
  {
    parent::__construct(EventType::SCENE, $target, $timestamp);
  }
}