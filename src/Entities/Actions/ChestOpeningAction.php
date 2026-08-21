<?php

namespace Ichiloto\Engine\Entities\Actions;

use Assegai\Util\Text;
use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
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
    $loot = null;
    $replacement = 'Nothing';

    if ($this->trigger->isComplete) {
      alert(get_message('obtained_item', '%1 found!', $replacement));
      return;
    }

    $message = get_message('obtained_item', '%1 found!');

    switch ($this->trigger->lootType) {
      case LootType::GOLD:
        $amount = is_numeric($this->trigger->loot) ? (int) $this->trigger->loot : $this->trigger->quantity;
        $symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'G');
        $message = get_message('obtained_gold', '%1 %2 found!', $amount, $symbol);
        $context->party->transact($amount);
        break;

      default:
        $loot = $this->itemStore->get($this->itemStore->requireDefinitionId(
          strval($this->trigger->loot),
          'opening a chest',
        ));
        assert($loot !== null);
        $quantity = $this->trigger->quantity;
        $lootNameText = new Text($loot->name);
        $lootName = ($quantity > 1) ? $lootNameText->getPluralForm() : $lootNameText->getSingularForm();
        $message = format_message($message, "{$quantity} {$lootName}");
        for ($count = 0; $count < $quantity; $count++) {
          // Clone so the party inventory never aliases the item store's catalog instance.
          $context->party->addItems(clone $loot);
        }
        break;
    }

    $this->trigger->complete();
    $context->scene->getGame()->audioManager->playSystemSound(SystemSound::ITEM_GET);
    $context->player->availableAction = null;
    alert($message);
  }
}
