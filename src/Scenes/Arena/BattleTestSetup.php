<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/**
 * What a battle test fights with, as in RPG Maker's Battle Test: chosen
 * members at chosen levels and equipment, each starting with full HP and
 * MP and no states unless set otherwise, and the party's items. Every fight
 * gets a fresh party built from it, so nothing a battle does (experience,
 * levels, gold, items, states) carries into the next one. Unless its items
 * are set, the party holds the default stock: 99 of every item that is not
 * equipment, as RPG Maker gives its Battle Test party.
 */
final readonly class BattleTestSetup
{
  /** At most this many members; battleSize determines the active frontline. */
  public const int MAX_MEMBERS = Party::MAX_BATTLE_SIZE;
  /** How many of each item the default stock holds, within each item's own limit. */
  public const int ITEM_QUANTITY = 99;
  /** At most this many different items, as many as a party's inventory holds. */
  public const int MAX_ITEMS = Inventory::DEFAULT_CAPACITY;
  /** The keys project data writes a setup with ({@see fromArray()}), for every reader that embeds one. */
  public const array KEYS = ['members', 'arena', 'battleSize', 'inventory'];

  public ?int $configuredBattleSize;
  public int $battleSize;

  /**
   * @param list<BattleTestMember> $members
   * @param string|null $arena The battle presentation's arena to fight in, by key; null for its default.
   *   Graphical presentation only: it changes nothing a fight does.
   * @param int|null $battleSize The selected frontline size; null to inherit the project size when resolved.
   * @param list<BattleTestItem>|null $inventory The party's items, each once; null for the default stock.
   */
  public function __construct(public array $members, public ?string $arena = null, ?int $battleSize = null,
    public ?array $inventory = null)
  {
    if ($battleSize !== null && ($battleSize < 1 || $battleSize > Party::MAX_BATTLE_SIZE)) {
      throw new InvalidArgumentException('A battle test frontline has 1 to 8 members.');
    }
    if ($members === [] || count($members) > self::MAX_MEMBERS || !array_is_list($members)) {
      throw new InvalidArgumentException(sprintf('A battle test party has 1 to %d members.', self::MAX_MEMBERS));
    }
    if ($inventory !== null) {
      BattleTestItem::requireUnique($inventory);
    }
    $this->configuredBattleSize = $battleSize;
    $this->battleSize = $battleSize ?? Party::BATTLE_SIZE;
  }

  /**
   * The setup a new game starts with: the system's starting party, each
   * actor at its authored level and wearing its authored equipment.
   */
  public static function getFromStartingParty(ActorStore $actors, ?int $battleSize = null): self
  {
    $system = asset('Data/system.php', true);

    $system = SystemData::fromArray(is_array($system) ? $system : []);
    return self::getFromPartyReferences($actors, $system->startingParty, $battleSize ?? $system->battle->partySize);
  }

  /**
   * The setup a starting-party list describes, as system data writes one
   * (actor ids, or the older actor file references): each actor at its
   * authored level and wearing its authored equipment. An editor passes the
   * list as it stands, unsaved edits included.
   *
   * @param list<mixed> $references
   */
  public static function getFromPartyReferences(ActorStore $actors, array $references, int $battleSize = Party::BATTLE_SIZE): self
  {
    $members = [];
    foreach ($references as $reference) {
      $definition = $actors->requireStartingPartyActor(strval($reference));
      $members[] = self::describeCharacter($definition->createCharacter());
    }
    if ($members === []) {
      throw new InvalidArgumentException('The project has no starting party to set up a battle test from.');
    }

    return new self(array_slice($members, 0, self::MAX_MEMBERS), battleSize: $battleSize);
  }

  /**
   * A setup as project data writes one: `members`, a list of
   * {@see BattleTestMember::fromArray()} entries, and optionally `arena`,
   * `battleSize` and `inventory`, a list of {@see BattleTestItem::fromArray()}
   * entries. Anything else is refused, never ignored.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a setup.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), self::KEYS);
    if ($unknown !== []) {
      throw new InvalidArgumentException('A battle test setup has no ' . implode(', ', $unknown) . '.');
    }
    $members = $data['members'] ?? null;
    if (!is_array($members) || !array_is_list($members) || array_any($members, static fn(mixed $member): bool => !is_array($member))) {
      throw new InvalidArgumentException('A battle test setup\'s members are a list of members.');
    }
    $arena = $data['arena'] ?? null;
    if ($arena !== null && (!is_string($arena) || trim($arena) === '')) {
      throw new InvalidArgumentException('A battle test setup\'s arena is an arena key.');
    }
    $inventory = array_key_exists('inventory', $data) ? BattleTestItem::listFromArray($data['inventory']) : null;

    return new self(array_map(BattleTestMember::fromArray(...), $members), $arena, $data['battleSize'] ?? null, $inventory);
  }

  /**
   * The setup as project data writes it, including only configured options.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      ...($this->configuredBattleSize === null ? [] : ['battleSize' => $this->configuredBattleSize]),
      'members' => array_map(static fn(BattleTestMember $member): array => $member->toArray(), $this->members),
      ...($this->arena === null ? [] : ['arena' => $this->arena]),
      ...($this->inventory === null ? [] : ['inventory' => array_map(static fn(BattleTestItem $item): array => $item->toArray(), $this->inventory)]),
    ];
  }

  /** The setup a party describes: its members, their levels and what they wear. */
  public static function getFromParty(Party $party): self
  {
    return new self(array_map(self::describeCharacter(...), array_slice($party->participants->toArray(), 0, self::MAX_MEMBERS)), battleSize: $party->battleSize);
  }

  private static function describeCharacter(Character $character): BattleTestMember
  {
    $equipment = [];
    foreach ($character->equipment as $slot) {
      $equipment[$slot->name] = $slot->equipment?->id;
    }

    return new BattleTestMember($character->actorId, $character->level, $equipment,
      $character->battleCommandLoadout?->commands,
      $character->battleCommandLoadout?->additionalSkills ?? [], $character->battleCommandLoadout?->availableSummons ?? []);
  }

  /**
   * Everything that stops the party being built as set up: an actor the
   * project does not have, a level beyond its actor's highest, a slot the
   * actor does not have, equipment that is not equipment, does not fit its
   * slot or cannot be worn by the actor, a starting state the project does
   * not define, starting points beyond the member's maximum, story facts
   * that are not one of the member's forms or that disagree between members,
   * a party that would start the fight knocked out, and items the party
   * cannot hold.
   *
   * @return list<string> The problems, each naming its member or the items; empty when the setup can be built.
   */
  public function getProblems(ActorStore $actors, ItemStore $items, ?SkillCatalog $skills = null, ?SummonCutsceneLibrary $summons = null): array
  {
    $loadouts = $this->createLoadoutCatalog($skills, $summons);
    $problems = [];
    $characters = [];
    foreach ($this->members as $number => $member) {
      $place = sprintf('Member %d (%s)', $number + 1, $member->actorId);
      $definition = $actors->get($member->actorId);
      if ($definition === null) {
        $problems[] = "{$place}: the project has no such actor.";
        continue;
      }
      $character = $definition->createCharacter();
      $characters[$member->actorId] = $character;
      $memberProblems = [];
      if ($member->level > $character->maxLevel) {
        $memberProblems[] = sprintf('level %d is beyond its highest, %d.', $member->level, $character->maxLevel);
      }
      foreach ($member->equipment as $slotName => $itemId) {
        $slot = array_find($character->equipment, static fn($slot): bool => $slot->name === $slotName);
        $item = $itemId === null ? null : $items->get($itemId);
        $problem = match (true) {
          $slot === null => sprintf('has no %s slot (its slots: %s).', $slotName,
            implode(', ', array_map(static fn($slot): string => $slot->name, $character->equipment))),
          $itemId === null => null,
          $item === null => "the project has no item {$itemId}.",
          ! $item instanceof Equipment => "{$item->name} is not equipment.",
          $slot->acceptsType !== $item::class || $slot->semanticSlot !== $item->semanticSlot => "{$item->name} does not go in the {$slotName} slot.",
          ! $character->canEquip($item) => "it cannot equip {$item->name}.",
          default => null,
        };
        if ($problem !== null) {
          $memberProblems[] = $problem;
        }
      }
      foreach ($member->states as $stateId) {
        if (StateRegistry::get($stateId) === null) {
          $memberProblems[] = "the project has no state {$stateId}.";
        }
      }
      if ($member->world !== null && !array_any(BattleTestWorld::listCharacterForms($character),
        static fn(array $form): bool => $form['world']->equals($member->world))) {
        $forms = array_map(static fn(array $form): string => $form['world']->describe(), BattleTestWorld::listCharacterForms($character));
        $memberProblems[] = sprintf('%s is not one of its forms (%s).', $member->world->describe(), $forms === [] ? 'it has none' : implode('; ', $forms));
      }
      // Points are checked against the member as it fights, at its level and in its equipment.
      if ($memberProblems === [] && ($member->hp?->unit === BattleTestVitalUnit::POINTS || $member->mp?->unit === BattleTestVitalUnit::POINTS)) {
        $built = $this->createMemberCharacter($member, $actors, $items);
        foreach (['HP' => [$member->hp, $built->stats->totalHp], 'MP' => [$member->mp, $built->stats->totalMp]] as $label => [$vital, $maximum]) {
          if ($vital?->unit === BattleTestVitalUnit::POINTS && $vital->amount > $maximum) {
            $memberProblems[] = sprintf('%d %s is beyond its maximum, %d.', $vital->amount, $label, $maximum);
          }
        }
      }
      foreach ($memberProblems as $problem) {
        $problems[] = "{$place}: {$problem}";
      }
      foreach ($loadouts?->getProblems($member, $character) ?? [] as $problem) {
        $problems[] = "{$place}: {$problem}";
      }
    }
    if ($this->findWorld() === false) {
      $problems[] = 'Members choose forms that set one story fact differently, so a fight cannot have both.';
    }
    if (array_all($this->members, static fn(BattleTestMember $member): bool => $member->hp?->amount === 0)) {
      $problems[] = 'Every member starts knocked out, so there is no fight; give one member some HP.';
    }

    return [...$problems, ...($loadouts?->getTenancyProblems($this->members, $characters) ?? []), ...$this->getInventoryProblems($items)];
  }

  /** @return list<string> */
  private function getInventoryProblems(ItemStore $items): array
  {
    if ($this->inventory === null) {
      return [];
    }
    $problems = count($this->inventory) > self::MAX_ITEMS
      ? [sprintf('Items: a party holds at most %d different items; %d are listed.', self::MAX_ITEMS, count($this->inventory))] : [];
    foreach ($this->inventory as $entry) {
      $item = $items->get($entry->itemId);
      $problem = match (true) {
        $item === null => "the project has no item {$entry->itemId}.",
        $item instanceof Equipment => "{$item->name} is equipment, which members wear; set it on a member instead.",
        $entry->quantity > $item->maxQuantity => sprintf('the party holds at most %d %s, not %d.', $item->maxQuantity, $item->name, $entry->quantity),
        default => null,
      };
      if ($problem !== null) {
        $problems[] = "Items: {$problem}";
      }
    }

    return $problems;
  }

  /** The setup with a member placed, replaced or, given null, removed; the last member stays. */
  public function withMember(int $index, ?BattleTestMember $member): self
  {
    $members = $this->members;
    if ($member === null) {
      if (count($members) > 1 && isset($members[$index])) {
        array_splice($members, $index, 1);
      }

      return new self($members, $this->arena, $this->configuredBattleSize, $this->inventory);
    }
    if ($index >= count($members)) {
      $members[] = $member;
    } else {
      $members[$index] = $member;
    }

    return new self(array_slice($members, 0, self::MAX_MEMBERS), $this->arena, $this->configuredBattleSize, $this->inventory);
  }

  public function withBattleSize(int $size): self
  {
    return new self($this->members, $this->arena, $size, $this->inventory);
  }

  /** The setup fighting in an arena of the battle presentation, by key; null for its default. */
  public function withArena(?string $arena): self
  {
    return new self($this->members, $arena === null || trim($arena) === '' ? null : $arena, $this->configuredBattleSize, $this->inventory);
  }

  /** @param list<BattleTestItem>|null $inventory The party's items; null for the default stock. */
  public function withInventory(?array $inventory): self
  {
    return new self($this->members, $this->arena, $this->configuredBattleSize, $inventory);
  }

  /**
   * The items the party holds: those set, or else the default stock.
   *
   * @return list<BattleTestItem>
   */
  public function getInventory(ItemStore $items): array
  {
    return $this->inventory ?? self::getDefaultStock($items);
  }

  /**
   * The default stock: every item a battle test party may carry
   * ({@see listCarriedItemIds()}), 99 of each or as many as the item allows.
   *
   * @return list<BattleTestItem>
   */
  public static function getDefaultStock(ItemStore $items): array
  {
    $stock = [];
    foreach (self::listCarriedItemIds($items) as $id) {
      $quantity = min(self::ITEM_QUANTITY, $items->get($id)?->maxQuantity ?? 0);
      if ($quantity > 0) {
        $stock[] = new BattleTestItem($id, $quantity);
      }
    }

    return $stock;
  }

  /**
   * Every item a battle test party may carry, in authored order: all but
   * equipment, which members wear.
   *
   * @return list<string> Item definition ids.
   */
  public static function listCarriedItemIds(ItemStore $items): array
  {
    return array_values(array_filter(array_map(strval(...), $items->getItemIds()), static function (string $id) use ($items): bool {
      $item = $items->get($id);
      return $item !== null && ! $item instanceof Equipment;
    }));
  }

  /**
   * The settings a fight from this setup passes its battle: the arena, when
   * one is chosen, and a fresh world state holding the members' story
   * facts, when any are set ({@see createWorldState()}).
   *
   * @return array<string, mixed>
   */
  public function getBattleSettings(): array
  {
    $world = $this->createWorldState();

    return [...($this->arena === null ? [] : ['battleArena' => $this->arena]),
      ...($world === null ? [] : [BattleConfig::TEST_WORLD_STATE => $world])];
  }

  /**
   * A fresh world state for one test fight, holding every member's story
   * facts; none when no member sets any. Each call makes a new one, so
   * nothing a fight does to it reaches the next.
   */
  public function createWorldState(): ?GameState
  {
    $world = $this->findWorld();

    return $world === null || $world === false ? null : $world->createGameState();
  }

  /** @return BattleTestWorld|false|null Every member's facts together, none when no member sets any, false when two disagree. */
  private function findWorld(): BattleTestWorld|false|null
  {
    $world = null;
    foreach ($this->members as $member) {
      if ($member->world === null) { continue; }
      $world = $world === null ? $member->world : $world->merge($member->world);
      if ($world === null) { return false; }
    }

    return $world;
  }

  /**
   * Builds a fresh party: each actor as authored, at its chosen level (any
   * from 1 to its maximum) with its class's skills for that level learned,
   * wearing its equipment and starting with its HP, MP and states, and
   * holding the party's items.
   *
   * @throws InvalidArgumentException When the setup has problems ({@see getProblems()}); nothing is left out quietly.
   */
  public function createParty(ActorStore $actors, ItemStore $items, ?SkillCatalog $skills = null, ?SummonCutsceneLibrary $summons = null): Party
  {
    $problems = $this->getProblems($actors, $items, $skills, $summons);
    if ($problems !== []) {
      throw new InvalidArgumentException("The battle test party cannot be built:\n" . implode("\n", $problems));
    }
    $party = new Party();
    $party->setBattleSize($this->battleSize);
    $loadouts = $this->createLoadoutCatalog($skills, $summons);
    foreach ($this->members as $member) {
      $character = $this->createMemberCharacter($member, $actors, $items);
      $loadouts?->applyToCharacter($member, $character);
      $this->applyStartingCondition($member, $character);
      $party->addMember($character);
    }
    foreach ($this->getInventory($items) as $entry) {
      $party->addItems(...$items->instantiate($entry->itemId, $entry->quantity, 'stocking the battle test party'));
    }

    return $party;
  }

  /**
   * One member as the setup builds it, before its test loadout and starting
   * condition: the actor as authored, holding the experience its class
   * needs for the level, restored as a save restores it, then taught the
   * class's skills up to that level, wearing its equipment with HP and MP
   * full. Interfaces read a member's maxima from it.
   */
  public function createMemberCharacter(BattleTestMember $member, ActorStore $actors, ItemStore $items): Character
  {
    $definition = $actors->require($member->actorId, 'the battle test party');
    $authored = $definition->createCharacter();
    $level = min($member->level, $authored->maxLevel);
    $experience = $authored->getLevelExperienceThresholds()[$level] ?? $authored->currentExp;
    $character = $definition->createCharacter([...$definition->data(), 'currentExp' => max(0, $experience)]);
    ExperienceAwarder::reconcileAutomaticRoleSkills($character);
    foreach ($member->equipment as $slotName => $itemId) {
      $equipment = $itemId === null ? null : $items->get($itemId);
      $character->assignEquipment($slotName, $equipment instanceof Equipment ? $equipment : null);
    }
    $character->restoreVitals();

    return $character;
  }

  private function createLoadoutCatalog(?SkillCatalog $skills, ?SummonCutsceneLibrary $summons): ?BattleTestLoadoutCatalog
  {
    if (array_any($this->members, static fn($member): bool => $member->commands !== null || $member->skills !== [] || $member->summons !== [])) {
      return new BattleTestLoadoutCatalog($skills ?? SkillCatalog::getProjectCatalog(), $summons ?? new SummonCutsceneLibrary());
    }
    return null;
  }

  /** Sets the member's starting HP and MP from its maxima and gives it its starting states, without a chance roll. */
  private function applyStartingCondition(BattleTestMember $member, Character $character): void
  {
    if ($member->hp !== null) {
      $character->stats->currentHp = $member->hp->resolvePoints($character->stats->totalHp);
    }
    if ($member->mp !== null) {
      $character->stats->currentMp = $member->mp->resolvePoints($character->stats->totalMp);
    }
    foreach ($member->states as $stateId) {
      $state = StateRegistry::get($stateId) ?? throw new InvalidArgumentException("The project has no state {$stateId}.");
      $character->addState($state, guaranteed: true);
    }
  }
}
