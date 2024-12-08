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
   * Configures the event.
   */
  public function configure(): void;

  /**
   * Fired when the event is entered.
   *
   * @param EventTriggerContextInterface $context The context.
   */
  public function enter(EventTriggerContextInterface $context): void;

  /**
   * Fired while the event is active.
   *
   * @param EventTriggerContextInterface $context The context.
   */
  public function stay(EventTriggerContextInterface $context): void;

  /**
   * Fired when the event is exited.
   *
   * @param EventTriggerContextInterface $context The context.
   */
  public function exit(EventTriggerContextInterface $context): void;
}