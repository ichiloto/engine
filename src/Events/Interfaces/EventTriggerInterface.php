<?php

namespace Ichiloto\Engine\Events\Interfaces;

/**
 * The EventTriggerInterface interface.
 *
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface EventTriggerInterface
{
  /**
   * Enters the event.
   *
   * @param EventInterface $event The event.
   */
  public function enter(EventInterface $event): void;

  /**
   * Handles the event.
   *
   * @param EventInterface $event The event.
   */
  public function handle(EventInterface $event): void;

  /**
   * Exits the event.
   *
   * @param EventInterface $event The event.
   */
  public function exit(EventInterface $event): void;
}