<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use InvalidArgumentException;

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
      return $this->members->toArray()[0] ?? null;
    }
  }
  /**
   * @var ItemList<CharacterInterface> The party's battlers.
   */
  public ItemList $battlers {
    get {
      $members = $this->members->toArray();
      $frontline = array_slice($members, 0, 3);
      $livingFrontline = array_filter(
        $frontline,
        static fn(CharacterInterface $member): bool => ! $member->isKnockedOut
      );

      if (! empty($livingFrontline) || count($members) <= 3) {
        return new ItemList(CharacterInterface::class, $frontline);
      }

      $livingMembers = array_values(array_filter(
        $members,
        static fn(CharacterInterface $member): bool => ! $member->isKnockedOut
      ));

      return new ItemList(CharacterInterface::class, array_slice($livingMembers, 0, 3));
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

  /**
   * Checks if the party can afford a cost.
   *
   * @param int $cost The cost to check.
   * @return bool True if the party can afford the cost, false otherwise.
   */
  public function canAfford(int $cost): bool
  {
    return $this->accountBalance >= $cost;
  }

  /**
   * Checks if the party cannot afford a cost.
   *
   * @param int $cost The cost to check.
   * @return bool True if the party cannot afford the cost, false otherwise.
   */
  public function cannotAfford(int $cost): bool
  {
    return ! $this->canAfford($cost);
  }

  /**
   * Counts how many copies of an equipment item are currently worn by the party.
   *
   * @param Equipment $equipment The equipment to count.
   * @return int The number of equipped copies.
   */
  public function getEquippedEquipmentCount(Equipment $equipment): int
  {
    $count = 0;

    foreach ($this->members->toArray() as $member) {
      assert($member instanceof Character);

      foreach ($member->equipment as $slot) {
        if ($slot->equipment === null) {
          continue;
        }

        if ($slot->equipment::class === $equipment::class && $slot->equipment->name === $equipment->name) {
          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * Returns the number of unequipped copies still available in the party inventory.
   *
   * @param Equipment $equipment The equipment to check.
   * @return int The number of available copies.
   */
  public function getAvailableEquipmentQuantity(Equipment $equipment): int
  {
    return max(0, $equipment->quantity - $this->getEquippedEquipmentCount($equipment));
  }

  /**
   * Returns the party members currently holding the given summon.
   *
   * @param string $summonId The summon id to look up.
   * @return Character[] The members with the summon assigned.
   */
  public function getSummonHolders(string $summonId): array
  {
    $holders = [];

    foreach ($this->members->toArray() as $member) {
      assert($member instanceof Character);

      if ($member->hasSummon($summonId)) {
        $holders[] = $member;
      }
    }

    return $holders;
  }

  /**
   * Determines whether the summon can be assigned to the given member.
   *
   * Checks the summon's wielder eligibility (role, named character, or open)
   * and its tenancy (an exclusive summon may only be held by one member at a
   * time). A summon without a wielder policy is openly usable and never needs
   * assignment.
   *
   * @param SummonCutsceneDefinition $definition The summon definition.
   * @param Character $character The member to assign the summon to.
   * @return bool True when the assignment is allowed.
   */
  public function canAssignSummon(SummonCutsceneDefinition $definition, Character $character): bool
  {
    $policy = $definition->wielders;

    if (! $policy instanceof SummonWielderPolicy) {
      return false;
    }

    if (! $policy->allowsCharacter($character)) {
      return false;
    }

    if ($policy->isExclusive()) {
      foreach ($this->getSummonHolders($definition->id) as $holder) {
        if ($holder !== $character) {
          return false;
        }
      }
    }

    return true;
  }

  /**
   * Assigns the summon to the given member when the rules allow it.
   *
   * @param SummonCutsceneDefinition $definition The summon definition.
   * @param Character $character The member to assign the summon to.
   * @return bool True when the summon was assigned.
   */
  public function assignSummon(SummonCutsceneDefinition $definition, Character $character): bool
  {
    if (! $this->canAssignSummon($definition, $character)) {
      return false;
    }

    $character->assignSummon($definition->id);

    return true;
  }

  /**
   * Removes the summon assignment from the given member.
   *
   * @param string $summonId The summon id to remove.
   * @param Character $character The member losing the summon.
   * @return void
   */
  public function unassignSummon(string $summonId, Character $character): void
  {
    $character->unassignSummon($summonId);
  }

  /**
   * Swaps the positions of two party members.
   *
   * @param int $firstIndex The first party-member index.
   * @param int $secondIndex The second party-member index.
   * @return void
   */
  public function swapMembers(int $firstIndex, int $secondIndex): void
  {
    $members = $this->members->toArray();

    if (! isset($members[$firstIndex], $members[$secondIndex])) {
      throw new InvalidArgumentException('Invalid party-member index.');
    }

    if ($firstIndex === $secondIndex) {
      return;
    }

    [$members[$firstIndex], $members[$secondIndex]] = [$members[$secondIndex], $members[$firstIndex]];
    $this->members = new ItemList(CharacterInterface::class, $members);
  }

}
