<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Entities\Actions\FieldAction;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;

class ShowDialogAction extends FieldAction
{
  public function __construct(
    protected DialogueEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function execute(ActionContextInterface $context): void
  {
    // TODO: Implement execute() method.
    foreach ($this->trigger->dialogue as $dialogue) {
      $dialogue->show();
    }
  }
}