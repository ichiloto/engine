<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Inventory;

/**
 * Class Party. Represents a party of characters in a battle.
 *
 * @package Ichiloto\Engine\Entities
 * @extends BattleGroup<Character>
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
   * @var int The party's account balance.
   */
  public int $accountBalance = 0 {
    get {
      return $this->accountBalance;
    }

    set {
      $this->accountBalance = clamp($value, self::MIN_GOLD, self::MAX_GOLD);
    }
  }
  /**
   * @var PartyLocation|null The party's location.
   */
  public ?PartyLocation $location = null;
  /**
   * @var Inventory|null The party's inventory.
   */
  protected(set) ?Inventory $inventory;
  /**
   * @var Character|null The party's leader.
   */
  public ?Character $leader {
    get {
      return $this->members[0] ?? null;
    }
  }
  /**
   * @var ItemList<CharacterInterface> The party's battlers.
   */
  public ItemList $battlers {
    get {
      return new ItemList(CharacterInterface::class, array_slice($this->members->toArray(), 0, 3));
    }
  }

  /**
   * @inheritDoc
   */
  public function configure(array $config = []): void
  {
    $this->inventory = new Inventory();
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
    $this->inventory->addItems(...$items);
  }

  /**
   * Removes items from the party's inventory.
   *
   * @param InventoryItemInterface ...$items The items to remove from the party's inventory.
   * @return void
   */
  public function removeItems(InventoryItemInterface ...$items): void
  {
    $this->inventory->removeItems(...$items);
  }

  /**
   * Transacts gold with the party.
   *
   * @param int $amount The amount of gold to transact.
   * @return void
   */
  public function transact(int $amount): void
  {
    $this->accountBalance += $amount;
  }

  public function debit(int $amount): void
  {
    $this->transact(-$amount);
  }

  public function credit(int $amount): void
  {
    $this->transact($amount);
  }
}