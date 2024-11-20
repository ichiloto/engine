<?php

namespace Ichiloto\Engine\Events;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\EventListenerInterface;
use Ichiloto\Engine\Events\Interfaces\EventTargetInterface;
use RuntimeException;

/**
 * The event manager.
 *
 * @package Ichiloto\Engine\Events
 */
class EventManager implements EventTargetInterface
{
  /**
   * @var EventManager|null The instance of the event manager.
   */
  protected static ?EventManager $instance = null;

  /**
   * @var array<string, array<EventListenerInterface|callable>> The listeners.
   */
  protected array $listeners = [];

  /**
   * EventManager constructor.
   *
   * @param Game $game The game.
   */
  private function __construct(protected Game $game)
  {
  }

  /**
   * Return the instance of the event manager.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): self
  {
    if (self::$instance === null) {
      self::$instance = new EventManager($game);
    }

    return self::$instance;
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
        if ($entry instanceof EventListenerInterface) {
          if ($listener->equals($entry)) {
            unset($this->listeners[$type->value][$index]);
          }
        } else {
          if ($listener === $entry) {
            unset($this->listeners[$type->value][$index]);
          }
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