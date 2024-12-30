<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\ChestOpeningAction;
use Ichiloto\Engine\Events\Enumerations\ChestType;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;

/**
 * The ChestEventTrigger class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class ChestEventTrigger extends EventTrigger
{
  /**
   * @var ChestType $chestType The chest type.
   */
  protected(set) ChestType $chestType;
  /**
   * @var LootType $lootType The loot type.
   */
  protected(set) LootType $lootType;
  /**
   * @var mixed $loot The loot.
   */
  protected(set) mixed $loot;
  /**
   * @var int $quantity The quantity.
   */
  protected(set) int $quantity;

  /**
   * @inheritdoc
   */
  public function configure(): void
  {
    $this->isReusable = $this->data->reusable ?? false;
    $this->chestType = ChestType::tryFrom($this->data->chestType ?? '') ?? ChestType::COMMON;
    $this->lootType = LootType::tryFrom($this->data->lootType ?? '') ?? LootType::ITEM;
    $this->loot = $this->data->loot ?? '';
    $this->quantity = $this->data->quantity ?? 1;
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);
    $context->player->erase();
    $context->player->availableAction = new ChestOpeningAction($this);
    $context->player->render();
  }

  /**
   * @inheritDoc
   */
  public function stay(EventTriggerContextInterface $context): void
  {
    // Do nothing.
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