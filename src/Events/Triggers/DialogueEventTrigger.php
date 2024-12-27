<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\ShowDialogAction;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\Dialogue;

/**
 * The DialogueEventTrigger class. This class is used to trigger a dialogue event.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class DialogueEventTrigger extends EventTrigger
{
  /**
   * @var Dialogue[] $dialogue
   */
  protected(set) array $dialogue = [];

  /**
   * @throws RequiredFieldException
   */
  public function configure(): void
  {
    foreach ($this->data->dialogue ?? [] as $dialogue) {
      $this->dialogue[] = Dialogue::fromObject($dialogue);
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);
    $context->player->erase();
    $context->player->availableAction = new ShowDialogAction($this);
    $context->player->render();
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);
    $context->player->erase();
    $context->player->availableAction = null;
    $context->player->render();
  }
}