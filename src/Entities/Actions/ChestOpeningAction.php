<?php

namespace Ichiloto\Engine\Entities\Actions;

use Assegai\Util\Text;
use Exception;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;

/**
 * The ChestOpeningAction class. This class is responsible for opening a chest.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class ChestOpeningAction extends FieldAction
{
  /**
   * @var ItemStore The item store.
   */
  protected ItemStore $itemStore;

  /**
   * ChestOpeningAction constructor.
   *
   * @param ChestEventTrigger $trigger The event trigger.
   */
  public function __construct(
    protected ChestEventTrigger $trigger
  )
  {
    $itemStore = ConfigStore::get(ItemStore::class);
    if (! $itemStore instanceof ItemStore) {
      throw new RuntimeException('Item store not found.');
    }
    $this->itemStore = $itemStore;
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while loading the configuration.
   */
  public function execute(ActionContextInterface $context): void
  {
    $message = config(ProjectConfig::class, 'messages.obtained_item');
    $loot = null;
    $replacement = 'Nothing';

    if ($this->trigger->isComplete) {
      $message = str_replace('%1', $replacement, $message);
      alert($message);
      return;
    }

    switch ($this->trigger->lootType) {
      case LootType::GOLD:
        $amount = $this->trigger->quantity;
        $symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'G');
        $message = config(ProjectConfig::class, 'messages.obtained_gold', '%1%2 found!');
        $message = str_replace('%1', $amount, $message);
        $message = str_replace('%2', $symbol, $message);
        $context->party->transact($amount);
        break;

      default:
        $loot = $this->itemStore->get($this->trigger->loot);
        $quantity = $this->trigger->quantity;
        $lootNameText = new Text($loot->name);
        $lootName = ($quantity > 1) ? $lootNameText->getPluralForm() : $lootNameText->getSingularForm();
        $replacement = "{$quantity} {$lootName}";
        $message = str_replace('%1', $replacement, $message);
        for ($count = 0; $count < $quantity; $count++) {
          $context->party->addItems($loot);
        }
        break;
    }

    $this->trigger->complete();
    $context->player->availableAction = null;
    alert($message);
  }
}