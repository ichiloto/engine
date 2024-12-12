<?php

namespace Ichiloto\Engine\Entities\Actions;

use Exception;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Item\Item;
use Ichiloto\Engine\Entities\Inventory\Weapon;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * The ChestOpeningAction class. This class is responsible for opening a chest.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class ChestOpeningAction extends FieldAction
{
  /**
   * ChestOpeningAction constructor.
   *
   * @param ChestEventTrigger $trigger The event trigger.
   */
  public function __construct(
    protected ChestEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while loading the configuration.
   */
  public function execute(ActionContextInterface $context): void
  {
    $message = config(ProjectConfig::class, 'messages.obtained_item');
    $loot = null;
    $lootName = 'Nothing';

    if ($this->trigger->isComplete) {
      $message = str_replace('%1', $lootName, $message);
      alert($message);
      return;
    }

    switch ($this->trigger->lootType) {
      case LootType::GOLD:
        $message = config(ProjectConfig::class, 'messages.obtained_gold');
        break;

      case LootType::ITEM:
        $loot = Item::fromObject($this->trigger->loot);
        break;

      case LootType::ACCESSORY:
        $loot = Accessory::fromObject($this->trigger->loot);
        break;

      case LootType::ARMOR:
        $loot = Armor::fromObject($this->trigger->loot);
        break;

      case LootType::WEAPON:
        $loot = Weapon::fromObject($this->trigger->loot);
        break;

      default:
        break;
    }

    $lootName = "{$this->trigger->quantity} {$loot->name}";
    $message = str_replace('%1', $lootName, $message);
    $context->party->addItems($loot);
    $this->trigger->complete();
    $context->player->availableAction = null;
    alert($message);
  }
}