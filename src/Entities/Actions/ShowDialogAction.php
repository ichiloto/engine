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
    foreach ($this->trigger->dialogue as $dialogue) {
      $dialogue->show();
    }

    // Finishing the conversation completes the trigger: completion writes
    // (`sets`, quest grants) apply and talk-to objectives advance.
    $this->trigger->complete();
  }
}