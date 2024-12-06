<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;

/**
 * Class Party. Represents a party of characters in a battle.
 *
 * @package Ichiloto\Engine\Entities
 */
class Party extends BattleGroup
{
  /**
   * The maximum gold the party can have.
   */
  const int MAX_GOLD = 9999999;
  /**
   * The minimum gold the party can have.
   */
  const int MIN_GOLD = 0;

  /**
   * @var int The gold the party has.
   */
  protected(set) int $gold = 0 {
    get {
      return $this->gold;
    }

    set {
      $this->gold = clamp($value, self::MIN_GOLD, self::MAX_GOLD);
    }
  }
  /**
   * @var PartyLocation|null The party's location.
   */
  public ?PartyLocation $location = null;
  /**
   * @var ItemList|null The party's inventory.
   */
  protected(set) ?ItemList $inventory;

  /**
   * @inheritDoc
   */
  public function configure(array $config = []): void
  {
    $this->inventory = new ItemList(InventoryItemInterface::class);
  }

  /**
   * Creates a new party from an array.
   *
   * @param array<string, mixed> $data The data to create the party from.
   */
  public static function fromArray(array $data): Party
  {
    $locationData = $data['location'] ?? [
      'name' => PartyLocation::DEFAULT_LOCATION_NAME,
      'region' => PartyLocation::DEFAULT_LOCATION_REGION
    ];
    $party = new Party();
    $party->location = new PartyLocation(
      $locationData['name'] ?? PartyLocation::DEFAULT_LOCATION_NAME,
      $locationData['region'] ?? PartyLocation::DEFAULT_LOCATION_REGION
    );

    foreach ($data as $datum) {
      $party->addMember(Character::fromArray($datum));
    }

    return $party;
  }

  /**
   * Adds items to the party's inventory.
   *
   * @param InventoryItemInterface ...$items The items to add to the party's inventory.
   * @return void
   */
  public function addItems(InventoryItemInterface ...$items): void
  {
    foreach ($items as $item) {
      $this->inventory->add($item);
    }
  }

  /**
   * Removes items from the party's inventory.
   *
   * @param InventoryItemInterface ...$items The items to remove from the party's inventory.
   * @return void
   */
  public function removeItems(InventoryItemInterface ...$items): void
  {
    foreach ($items as $item) {
      $this->inventory->remove($item);
    }
  }
}