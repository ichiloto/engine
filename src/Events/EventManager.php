<?php

namespace Ichiloto\Engine\Events;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\EventListenerInterface;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use RuntimeException;

/**
 * The event manager.
 *
 * @package Ichiloto\Engine\Events
 */
class EventManager implements EventTargetInterface
{
  /**
   * @var array<string, array<EventListenerInterface|callable>> The listeners.
   */
  protected array $listeners = [];
  /**
   * @var ItemList<EventTrigger> $activeEvents The list of active events.
   */
  protected(set) ItemList $activeEvents;

  public function __construct()
  {
    $this->activeEvents = new ItemList(EventTrigger::class);
  }

  /**
   * Return the event manager owned by this Game.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): self
  {
    return $game->getEventManager();
  }

  /**
   * @inheritDoc
   */
  public function addEventListener(EventType $type, EventListenerInterface|callable $listener, bool $useCapture = false): void
  {
    $this->listeners[$type->value][] = $listener;
  }

  /**
   * @inheritDoc
   */
  public function removeEventListener(EventType $type, EventListenerInterface|callable $listener, bool $useCapture = false): void
  {
    if (isset($this->listeners[$type->value])) {
      foreach ($this->listeners[$type->value] as $index => $entry) {
        if ($listener === $entry || ($listener instanceof EventListenerInterface
          && $entry instanceof EventListenerInterface && $listener->equals($entry))) {
          unset($this->listeners[$type->value][$index]);
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function dispatchEvent(EventInterface $event): bool
  {
    if (isset($this->listeners[$event->getType()->value])) {
      foreach ($this->listeners[$event->getType()->value] as $listener) {
        if ($listener instanceof EventListenerInterface) {
          $listener->handle($event);
        } else {
          if (! is_callable($listener) ) {
            throw new RuntimeException('Listener is not callable.');
          }

          $listener($event);
        }
      }
    }

    return true;
  }
}
