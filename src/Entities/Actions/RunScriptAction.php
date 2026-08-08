<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/**
 * Runs a script trigger's commands, then completes the trigger.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class RunScriptAction extends FieldAction
{
  public function __construct(
    protected ScriptEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function execute(ActionContextInterface $context): void
  {
    new EventInterpreter($context->scene)->run($this->trigger->script);

    // Completion applies the trigger's `sets` and persists one-shots.
    $this->trigger->complete();
  }
}
