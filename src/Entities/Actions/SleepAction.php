<?php

namespace Ichiloto\Engine\Entities\Actions;

use Exception;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Inn\InnStay;

/**
 * Class SleepAction. A field action that offers the party a stay at an inn.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class SleepAction extends FieldAction
{
  /**
   * Constructs a new instance of SleepAction.
   *
   * @param SleepEventTrigger $trigger The trigger that initiated the sleep event.
   */
  public function __construct(
    protected SleepEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while showing the dialogue.
   */
  public function execute(ActionContextInterface $context): void
  {
    new InnStay($this->trigger->offer)->perform($context->scene);
  }
}
