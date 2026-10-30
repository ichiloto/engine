<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonWielderPolicy;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Exceptions\SummonAssignmentException;
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
  /** Legacy default; use battleSize for this party's current limit. */
  public const int BATTLE_SIZE = 3;
  public const int MAX_BATTLE_SIZE = 8;
  private int $battleMemberLimit = self::BATTLE_SIZE;
  /** @var list<string>|null Null means all permanent members are present. */
  private ?array $participatingActorIds = null;

  public int $battleSize { get => $this->battleMemberLimit; }
  /** Present members share the permanent actors; a split never clones or removes them. */
  public ItemList $participants {
    get => new ItemList(CharacterInterface::class, $this->getParticipatingMembers());
  }

  public function setBattleSize(int $size): void
  {
    if ($size < 1 || $size > self::MAX_BATTLE_SIZE) {
      throw new InvalidArgumentException('A battle party requires between 1 and 8 members.');
    }
    $this->battleMemberLimit = $size;
  }

  /** @param list<string> $actorIds Stable project actor identities, in participation order. */
  public function selectParticipants(array $actorIds): void
  {
    if (!array_is_list($actorIds) || $actorIds === [] || count($actorIds) > self::MAX_BATTLE_SIZE
      || array_any($actorIds, static fn(mixed $id): bool => !is_string($id) || trim($id) === '')
      || count(array_unique($actorIds)) !== count($actorIds)) {
      throw new InvalidArgumentException('Select 1 to 8 distinct party actor identities.');
    }
    $this->resolveParticipants($actorIds); // Validate the complete selection before changing state.
    $this->participatingActorIds = $actorIds;
  }

  public function regroupParticipants(): void
  {
    $this->participatingActorIds = null;
  }

  /** @return list<string>|null Saved stable selection, including its order. */
  public function getSelectedActorIds(): ?array { return $this->participatingActorIds; }

  /** @return list<CharacterInterface> */
  private function getParticipatingMembers(): array
  {
    return $this->participatingActorIds === null
      ? $this->members->toArray() : $this->resolveParticipants($this->participatingActorIds);
  }

  /** @param list<string> $ids @return list<CharacterInterface> */
  private function resolveParticipants(array $ids): array
  {
    $members = [];
    foreach ($ids as $id) {
      $matches = array_values(array_filter($this->members->toArray(),
        static fn($member): bool => $member instanceof Character && $member->actorId === $id));
      if (count($matches) !== 1) {
        throw new InvalidArgumentException(sprintf('Participating actor "%s" must identify one permanent party member.', $id));
      }
      $members[] = $matches[0];
    }
    return $members;
  }

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
      return $this->participants->toArray()[0] ?? null;
    }
  }
  /**
   * @var ItemList<CharacterInterface> The party's battlers.
   */
  public ItemList $battlers {
    get {
      return new ItemList(CharacterInterface::class, array_slice($this->participants->toArray(), 0, $this->battleSize));
    }
  }

  public function isDefeated(): bool
  {
    $battlers = $this->battlers->toArray();
    return $battlers !== [] && array_all($battlers, static fn(CharacterInterface $member): bool => $member->isKnockedOut);
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
    $party->setBattleSize($data['battleSize'] ?? self::BATTLE_SIZE);
    $party->location = new PartyLocation(
      $locationData['name'] ?? PartyLocation::DEFAULT_LOCATION_NAME,
      $locationData['region'] ?? PartyLocation::DEFAULT_LOCATION_REGION
    );

    unset($data['location']);

    foreach ($data as $key => $datum) {
      if (! is_int($key) || ! is_array($datum)) {
        continue;
      }

      $party->addMember(Character::fromArray($datum));
    }

    if (isset($data['participants'])) { $party->selectParticipants($data['participants']); }
    return $party;
  }

  /** Recruit a current project actor once, preserving existing members and their mutable progress. */
  public function recruitActor(ActorDefinition $definition, RecruitmentLevelPolicy $levelPolicy = RecruitmentLevelPolicy::AUTHORED,
    RecruitmentPointPolicy $spPolicy = RecruitmentPointPolicy::AUTHORED): bool
  {
    foreach ($this->members as $member) {
      if ($member instanceof Character && $member->actorId === $definition->id) { return false; }
    }
    // Fully construct before changing the roster. A rejected definition leaves the party untouched.
    $pointAward = RecruitmentPointCatchUp::prepare($this->members->toArray(), $spPolicy);
    $startingLevel = null;
    if ($levelPolicy === RecruitmentLevelPolicy::PARTY_AVERAGE) {
      $members = $this->members->toArray();
      if ($members === []) { throw new \InvalidArgumentException('Party-average recruitment requires an existing party.'); }
      $startingLevel = intdiv(array_sum(array_map(static fn(Character $member): int => $member->level, $members)), count($members));
    }
    $character = $definition->createCharacter(startingLevel: $startingLevel);
    if ($pointAward !== null) {
      $character->mastery->addPoints($pointAward->points);
      $pointAward->initializePreparedHistories();
    }
    $this->addMember($character);
    return true;
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

        if ($slot->equipment->id === $equipment->id) {
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
  public function canAssignSummon(
    SummonCutsceneDefinition $definition,
    Character $character,
    ?GameState $gameState = null,
  ): bool
  {
    if (!$this->canReassignSummon($definition, $character, $gameState)) { return false; }
    $policy = $definition->wielders;

    if ($policy->isExclusive()) {
      $holders = $this->getSummonHolders($definition->id);

      if (count($holders) > 1) {
        throw new SummonAssignmentException(sprintf(
          'Exclusive summon "%s" has multiple holders.',
          $definition->id,
        ));
      }

      foreach ($holders as $holder) {
        if ($holder !== $character) {
          return false;
        }
      }
    }

    return true;
  }

  /** Preview the same eligibility and exclusive-ownership checks used by an atomic holder change. */
  public function canReassignSummon(SummonCutsceneDefinition $definition, Character $character,
    ?GameState $gameState = null): bool
  {
    $policy = $definition->wielders;
    if (!$policy instanceof SummonWielderPolicy || !$policy->isValid()
      || !$this->members->contains($character) || !$policy->allowsCharacter($character)
      || ($definition->availability !== null && ($gameState === null || !$definition->isAvailable($gameState, $this)))) {
      return false;
    }
    if ($policy->isExclusive() && count($this->getSummonHolders($definition->id)) > 1) {
      throw new SummonAssignmentException(sprintf('Exclusive summon "%s" has multiple holders.', $definition->id));
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
  public function assignSummon(
    SummonCutsceneDefinition $definition,
    Character $character,
    ?GameState $gameState = null,
  ): bool
  {
    if (! $this->canAssignSummon($definition, $character, $gameState)) {
      return false;
    }

    $character->assignSummon($definition->id);

    return true;
  }

  /**
   * Moves a summon to another eligible holder as one validated operation.
   *
   * Existing menu flows may still require an explicit release; this method
   * gives scripted and future management flows an atomic reassignment seam.
   */
  public function reassignSummon(
    SummonCutsceneDefinition $definition,
    Character $character,
    ?GameState $gameState = null,
  ): bool
  {
    $policy = $definition->wielders;

    if (! $policy instanceof SummonWielderPolicy || ! $policy->isExclusive()) {
      return $this->assignSummon($definition, $character, $gameState);
    }

    $holders = $this->getSummonHolders($definition->id);

    if (count($holders) > 1) {
      throw new SummonAssignmentException(sprintf(
        'Exclusive summon "%s" has multiple holders.',
        $definition->id,
      ));
    }

    if (!$this->canReassignSummon($definition, $character, $gameState)) {
      return false;
    }

    foreach ($holders as $holder) {
      if ($holder !== $character) {
        $holder->unassignSummon($definition->id);
      }
    }

    $character->assignSummon($definition->id);

    return true;
  }

  /**
   * Rejects malformed, stale, ineligible, or duplicate restored ownership.
   * Locked but otherwise legal ownership is intentionally preserved.
   *
   * @param SummonCutsceneDefinition[] $definitions
   * @throws SummonAssignmentException
   */
  public function assertSummonAssignments(array $definitions): void
  {
    $byId = [];

    foreach ($definitions as $definition) {
      if ($definition instanceof SummonCutsceneDefinition && $definition->id !== '') {
        $byId[strtolower($definition->id)] = $definition;
      }
    }

    foreach ($this->members->toArray() as $member) {
      assert($member instanceof Character);

      if (count($member->summons) !== count(array_unique($member->summons))) {
        throw new SummonAssignmentException(sprintf('%s has duplicate summon assignments.', $member->name));
      }

      foreach ($member->summons as $summonId) {
        if (! is_string($summonId) || trim($summonId) === '') {
          throw new SummonAssignmentException(sprintf('%s has malformed summon assignment data.', $member->name));
        }

        $definition = $byId[strtolower(trim($summonId))] ?? null;

        if (! $definition instanceof SummonCutsceneDefinition) {
          throw new SummonAssignmentException(sprintf(
            '%s references missing summon "%s".',
            $member->name,
            $summonId,
          ));
        }

        if ($definition->wielders !== null
          && (! $definition->wielders->isValid() || ! $definition->wielders->allowsCharacter($member))
        ) {
          throw new SummonAssignmentException(sprintf(
            '%s is not eligible to hold summon "%s".',
            $member->name,
            $definition->id,
          ));
        }
      }
    }

    foreach ($byId as $definition) {
      if ($definition->wielders?->isExclusive() && count($this->getSummonHolders($definition->id)) > 1) {
        throw new SummonAssignmentException(sprintf(
          'Exclusive summon "%s" has multiple holders.',
          $definition->id,
        ));
      }
    }
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
    $members = $this->participants->toArray();

    if (! isset($members[$firstIndex], $members[$secondIndex])) {
      throw new InvalidArgumentException('Invalid party-member index.');
    }

    if ($firstIndex === $secondIndex) {
      return;
    }

    [$members[$firstIndex], $members[$secondIndex]] = [$members[$secondIndex], $members[$firstIndex]];
    if ($this->participatingActorIds !== null) {
      $this->selectParticipants(array_map(static fn(Character $actor): string => $actor->actorId, $members));
    } else {
      $this->members = new ItemList(CharacterInterface::class, $members);
    }
  }

}
