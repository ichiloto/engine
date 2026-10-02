<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actions\SleepAction;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Inn\InnOffer;
use Ichiloto\Engine\Messaging\Dialogue\ConfirmDialogue;

/**
 * SleepEventTrigger. This event trigger is used to simulate the player sleeping.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class SleepEventTrigger extends EventTrigger
{
  /**
   * @var InnOffer What the inn asks and where the party wakes, shared with the `inn` script command.
   */
  protected(set) InnOffer $offer;
  /**
   * @var Vector2 The spawn point of the player after the event is triggered.
   */
  public Vector2 $spawnPoint {
    get => $this->offer->spawnPoint;
  }
  /**
   * @var string[] The sprite of the player after the event is triggered.
   */
  public array $spawnSprite {
    get => $this->offer->spawnSprite;
  }
  /**
   * @var ConfirmDialogue The confirmation dialogue of the event.
   */
  public ConfirmDialogue $confirmDialogue {
    get => $this->offer->confirmDialogue;
  }
  /**
   * @var int The cost of the event.
   */
  public int $cost {
    get => $this->offer->cost;
  }
  /**
   * The rest music this inn declares, or null to use the project-wide sleep
   * theme.
   *
   * @var string|null
   */
  public ?string $backgroundMusic {
    get => $this->offer->backgroundMusic;
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    // A sleep trigger always wakes the party somewhere: the bed it stands
    // beside. The `inn` command may leave them where they stand.
    $this->data->spawnPoint->x ?? throw new RequiredFieldException('spawnPoint.x');
    $this->data->spawnPoint->y ?? throw new RequiredFieldException('spawnPoint.y');
    $this->data->spawnSprite ?? throw new RequiredFieldException('spawnSprite');
    $this->offer = InnOffer::fromData($this->data);
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