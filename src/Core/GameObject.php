<?php

namespace Ichiloto\Engine\Core;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\CanActivate;
use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/**
 * Class GameObject. Represents a game object.
 *
 * @package Ichiloto\Engine\Core
 */
abstract class GameObject implements CanActivate, SubjectInterface, CanUpdate, CanStart, CanRender, CanResume, CanCompare
{
  /**
   * @var ItemList<ObserverInterface> $observers The list of observers.
   */
  protected ItemList $observers;
  /**
   * @var ItemList<StaticObserverInterface> $staticObservers The list of static observers.
   */
  protected ItemList $staticObservers;
  /**
   * @var string $hash The hash of the object.
   */
  protected(set) string $hash = '';
  /**
   * @var bool $isActive Determines whether the object is active.
   */
  protected(set) bool $isActive = false;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected(set) EventManager $eventManager;

  /**
   * GameObject constructor.
   *
   * @param SceneInterface $scene The scene of the object.
   * @param string $name The name of the object.
   * @param Vector2 $position The position of the object.
   * @param Rect $shape The size of the object.
   * @param string[] $sprite The sprite of the object.
   */
  public function __construct(
    protected SceneInterface  $scene,
    protected string          $name,
    protected(set) Vector2    $position = new Vector2(),
    protected Rect            $shape = new Rect(0, 0, 1, 1),
    public array              $sprite = ['x']
  )
  {
    $this->hash = uniqid(md5(__CLASS__) . 'Core' . md5($this->name));
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
    $this->eventManager = EventManager::getInstance($this->getGame());
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->isActive = true;
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    $this->isActive = false;
    $this->erase();
  }

  /**
   * Determines whether this object and the given equatable are equal.
   *
   * @param CanEquate $equatable The other equatable.
   * @return bool Returns true if this object and the given equatable are equal, otherwise false.
   */
  public function equals(CanEquate $equatable): bool
  {
    return $this->hash === $equatable->hash;
  }

  /**
   * Determines whether this object and the given equatable are NOT equal.
   *
   * @param CanEquate $equatable The other equatable.
   * @return bool Returns true if this object and the given equatable are NOT equal, otherwise false.
   */
  public function notEquals(CanEquate $equatable): bool
  {
    return !$this->equals($equatable);
  }

  /**
   * @inheritDoc
   */
  public function compareTo(CanCompare $other): int
  {
    return $this->hash <=> $other->hash;
  }

  /**
   * @inheritDoc
   */
  public function greaterThan(CanCompare $other): bool
  {
    return $this->compareTo($other) > 0;
  }

  /**
   * @inheritDoc
   */
  public function greaterThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) >= 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThan(CanCompare $other): bool
  {
    return $this->compareTo($other) < 0;
  }

  /**
   * @inheritDoc
   */
  public function lessThanOrEqual(CanCompare $other): bool
  {
    return $this->compareTo($other) <= 0;
  }

  /**
   * Returns the size of the object.
   *
   * @return Rect The size of the object.
   */
  public function getShape(): Rect
  {
    return $this->shape;
  }

  /**
   * Returns the name of the object.
   *
   * @return string The name of the object.
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  /**
   * @inheritDoc
   */
  public function addObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {
    if ($observer instanceof StaticObserverInterface) {
      $this->staticObservers->add($observer);
    }

    if ($observer instanceof  ObserverInterface) {
      $this->observers->add($observer);
    }

    if (is_string($observer)) {
      if (class_exists($observer)) {
        $observer = new $observer();
        if ($observer instanceof StaticObserverInterface) {
          $this->staticObservers->add($observer);
        }
        if ($observer instanceof ObserverInterface) {
          $this->observers->add($observer);
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function removeObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {
    if ($observer instanceof StaticObserverInterface) {
      $this->staticObservers->remove($observer);
    }

    if ($observer instanceof  ObserverInterface) {
      $this->observers->remove($observer);
    }

    if (is_string($observer)) {
      if (class_exists($observer)) {
        $observer = new $observer();
        if ($observer instanceof StaticObserverInterface) {
          $this->staticObservers->remove($observer);
        }

        if ($observer instanceof ObserverInterface) {
          $this->observers->remove($observer);
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    foreach ($this->observers as $observer) {
      assert($observer instanceof ObserverInterface);
      $observer->onNotify($entity, $event);
    }

    foreach ($this->staticObservers as $observer) {
      assert($observer instanceof StaticObserverInterface);
      $observer::onNotify($entity, $event);
    }
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    for ($y = $this->shape->getY(); $y < $this->shape->getY() + $this->shape->getHeight(); $y++) {
      $output = substr($this->sprite[$y], $this->shape->getX(), $this->shape->getWidth());
      Console::write($output, $this->position->x + 1, $this->position->y + 1 + $y);
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    for($y = $this->shape->getY(); $y < $this->shape->getY() + $this->shape->getHeight(); $y++) {
      $this->scene->renderBackgroundTile($this->position->x, $this->position->y + $y);
//      Console::write(' ', $this->position->x + 1, $this->position->y + 1 + $y);
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->erase();
  }

  /**
   * Returns the game of the object.
   *
   * @return Game The game of the object.
   */
  public function getGame(): Game
  {
    return $this->scene->getGame();
  }
}