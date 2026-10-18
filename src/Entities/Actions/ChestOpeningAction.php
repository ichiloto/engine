<?php

namespace Ichiloto\Engine\Entities\Actions;

use Assegai\Util\Text;
use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
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

  /** The reward categories this action can grant through its current runtime paths. */
  public static function supportsLootType(LootType $type): bool
  {
    return match ($type) {
      LootType::GOLD, LootType::ITEM, LootType::WEAPON, LootType::ARMOR, LootType::ACCESSORY => true,
      default => false,
    };
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
      alert(get_engine_message('obtained_item', $replacement));
      return;
    }

    $issues = MapCompletenessValidator::validateChestReward((array) $this->trigger->data,
      $this->trigger->mapId ?? '(unbound)',
      fn(string $reference): string => $this->itemStore->requireDefinitionId($reference, 'opening a chest'),
      $this->trigger->marker);
    if ($issues !== []) {
      $this->reportUnavailableReward(implode(' ', array_column($issues, 'message')));
      return;
    }

    $message = get_engine_message('obtained_item');

    switch ($this->trigger->lootType) {
      case LootType::GOLD:
        $amount = is_numeric($this->trigger->loot) ? (int) $this->trigger->loot : $this->trigger->quantity;
        $symbol = Vocabulary::getTerm('currency.symbol', 'G');
        $message = get_engine_message('obtained_gold', $amount, $symbol);
        $context->party->transact($amount);
        break;

      default:
        try {
          $loot = $this->itemStore->get($this->itemStore->requireDefinitionId(
            strval($this->trigger->loot),
            'opening a chest',
          ));
        } catch (NotFoundException $error) {
          $this->reportUnavailableReward($error->getMessage());
          return;
        }
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

  private function reportUnavailableReward(string $reason): void
  {
    Debug::error(sprintf('[chest.reward] Chest reward could not be resolved on map "%s", event "%s": %s',
      $this->trigger->mapId ?? '(unbound)', $this->trigger->marker ?? '(unbound)', $reason));
    alert(get_engine_message('chest.unavailable'));
  }
}
