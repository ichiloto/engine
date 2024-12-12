<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerInterface;
use Ichiloto\Engine\Util\Debug;

/**
 * The EventTrigger class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
abstract class EventTrigger implements EventTriggerInterface
{
  /**
   * @var object The data.
   */
  protected(set) object $data;
  /**
   * @var bool Whether the trigger is reusable.
   */
  protected(set) bool $isReusable = true;
  /**
   * @var bool Whether the trigger is completed.
   */
  protected bool $completed = false;
  /**
   * @var bool Whether the trigger is complete.
   */
  public bool $isComplete {
    get {
      return !$this->isReusable && $this->completed;
    }
  }

  /**
   * EventTrigger constructor.
   *
   * @param Rect $area The trigger area. The area on the map where the trigger is activated.
   * @param array $data The data.
   */
  final public function __construct(protected(set) Rect $area, array $data = [])
  {
    $this->data = json_decode(json_encode($data));
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
    Debug::info("Trigger entered: " . get_class($this) . " at " . $context->coordinates);
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
    Debug::info("Trigger exited: " . get_class($this) . " at " . $context->coordinates);
    // Do nothing. This method should be overridden by the subclass.
  }

  /**
   * @inheritDoc
   */
  public function complete(): void
  {
    $this->completed = true;
  }
}