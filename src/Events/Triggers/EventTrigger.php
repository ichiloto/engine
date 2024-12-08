<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerInterface;

/**
 * The EventTrigger class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
abstract class EventTrigger implements EventTriggerInterface
{
  protected object $data;

  /**
   * EventTrigger constructor.
   *
   * @param Rect $area The trigger area. The area on the map where the trigger is activated.
   * @param array $data The data.
   */
  final public function __construct(protected Rect $area, array $data = [])
  {
    $this->data = (object) $data;
    $this->configure();
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    // Do nothing
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
  }

  /**
   * @inheritDoc
   */
  public function stay(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
  }
}