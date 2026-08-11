<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/**
 * Starts a script trigger through the GameScene-owned resumable interpreter.
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
    $this->trigger->startSession($context->scene);
  }
}
