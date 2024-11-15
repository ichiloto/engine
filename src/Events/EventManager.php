<?php

use Ichiloto\Engine\Core\Interfaces\SingletonInterface;

/**
 * The event manager.
 *
 * @package Ichiloto\Engine\Events
 */
class EventManager implements SingletonInterface
{
  /**
   * The instance of the event manager.
   * @var EventManager|null
   */
  protected static ?EventManager $instance = null;

  /**
   * @inheritDoc
   */
  public static function getInstance(): SingletonInterface
  {
    if (self::$instance === null) {
      self::$instance = new EventManager();
    }

    return self::$instance;
  }
}