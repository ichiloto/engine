<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actions\SleepAction;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\ConfirmDialogue;

/**
 * SleepEventTrigger. This event trigger is used to simulate the player sleeping.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class SleepEventTrigger extends EventTrigger
{
  /**
   * @var Vector2 The spawn point of the player after the event is triggered.
   */
  protected(set) Vector2 $spawnPoint;
  /**
   * @var string[] The sprite of the player after the event is triggered.
   */
  protected(set) array $spawnSprite;
  /**
   * @var ConfirmDialogue The confirmation dialogue of the event.
   */
  protected(set) ConfirmDialogue $confirmDialogue;
  /**
   * @var int The cost of the event.
   */
  protected(set) int $cost = 0;

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this->spawnPoint = new Vector2(
      $this->data->spawnPoint->x ?? throw new RequiredFieldException('spawnPoint.x'),
      $this->data->spawnPoint->y ?? throw new RequiredFieldException('spawnPoint.y')
    );
    $this->spawnSprite = $this->data->spawnSprite ?? throw new RequiredFieldException('spawnSprite');

    if (! isset($this->data->confirmDialogue)) {
      throw new RequiredFieldException('confirmDialogue');
    }

    $this->confirmDialogue = ConfirmDialogue::fromObject($this->data->confirmDialogue);
    $this->cost = $this->data->cost ?? 0;
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);
    $context->player->erase();
    $context->player->availableAction = new SleepAction($this);
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