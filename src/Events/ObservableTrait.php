<?php

namespace Ichiloto\Engine\Events;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;

trait ObservableTrait
{
  /**
   * Adds an observer to the list of observers.
   *
   * @param StaticObserverInterface|ObserverInterface|string $observer The observer to add.
   * @return void
   */
  public function addObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {
    if (isset($this->observers)) {
      assert($this->observers instanceof ItemList);
      if ($observer instanceof  ObserverInterface) {
        $this->observers->add($observer);
      }

      if (is_string($observer)) {
        if (class_exists($observer)) {
          if (is_subclass_of($observer, ObserverInterface::class)) {
            $this->observers->add(new $observer());
          }
        }
      }
    }

    if (isset($this->staticObservers)) {
      assert($this->staticObservers instanceof ItemList);
      if ($observer instanceof StaticObserverInterface) {
        $this->staticObservers->add($observer);
      }
    }
  }

  /**
   * Removes an observer from the list of observers.
   *
   * @param StaticObserverInterface|ObserverInterface|string $observer The observer to remove.
   * @return void
   */
  public function removeObserver(StaticObserverInterface|ObserverInterface|string $observer): void
  {
    if (isset($this->observers)) {
      assert($this->observers instanceof ItemList);
      if ($observer instanceof  ObserverInterface) {
        $this->observers->remove($observer);
      }

      if (is_string($observer)) {
        if (class_exists($observer)) {
          if (is_subclass_of($observer, ObserverInterface::class)) {
            $this->observers->remove(new $observer());
          }
        }
      }
    }

    if (isset($this->staticObservers)) {
      assert($this->staticObservers instanceof ItemList);
      if ($observer instanceof StaticObserverInterface) {
        $this->staticObservers->remove($observer);
      }
    }
  }

  /**
   * Notifies the observers.
   *
   * @param object $entity The entity to notify.
   * @param EventInterface $event The event to notify.
   * @return void
   */
  public function notify(object $entity, EventInterface $event): void
  {
    if (isset($this->observers)) {
      assert($this->observers instanceof ItemList);
      foreach ($this->observers as $observer) {
        $observer->onNotify($entity, $event);
      }
    }

    if (isset($this->staticObservers)) {
      assert($this->staticObservers instanceof ItemList);
      foreach ($this->staticObservers as $observer) {
        $observer::onNotify($entity, $event);
      }
    }
  }
}