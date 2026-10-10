<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;

/**
 * What a battle test fights with, as in RPG Maker's Battle Test: chosen
 * members at chosen levels and equipment. Every fight gets a fresh party
 * built from it, so nothing a battle does (experience, levels, gold, items,
 * states) carries into the next one. The party holds 99 of every item that
 * is not equipment, as RPG Maker gives its Battle Test party.
 */
final readonly class BattleTestSetup
{
  /** At most this many members, the front three fighting and any fourth in reserve. */
  public const int MAX_MEMBERS = 4;
  /** How many of each item the party holds. */
  public const int ITEM_QUANTITY = 99;

  /**
   * @param list<BattleTestMember> $members
   * @param string|null $arena The battle presentation's arena to fight in, by key; null for its default.
   *   Graphical presentation only: it changes nothing a fight does.
   */
  public function __construct(public array $members, public ?string $arena = null)
  {
    if ($members === [] || count($members) > self::MAX_MEMBERS || !array_is_list($members)) {
      throw new InvalidArgumentException(sprintf('A battle test party has 1 to %d members.', self::MAX_MEMBERS));
    }
  }

  /**
   * The setup a new game starts with: the system's starting party, each
   * actor at its authored level and wearing its authored equipment.
   */
  public static function getFromStartingParty(ActorStore $actors): self
  {
    $system = asset('Data/system.php', true);

    return self::getFromPartyReferences($actors, SystemData::fromArray(is_array($system) ? $system : [])->startingParty);
  }

  /**
   * The setup a starting-party list describes, as system data writes one
   * (actor ids, or the older actor file references): each actor at its
   * authored level and wearing its authored equipment. An editor passes the
   * list as it stands, unsaved edits included.
   *
   * @param list<mixed> $references
   */
  public static function getFromPartyReferences(ActorStore $actors, array $references): self
  {
    $members = [];
    foreach ($references as $reference) {
      $definition = $actors->requireStartingPartyActor(strval($reference));
      $members[] = self::describeCharacter($definition->createCharacter());
    }
    if ($members === []) {
      throw new InvalidArgumentException('The project has no starting party to set up a battle test from.');
    }

    return new self(array_slice($members, 0, self::MAX_MEMBERS));
  }

  /**
   * A setup as project data writes one: `members`, a list of
   * {@see BattleTestMember::fromArray()} entries, and optionally `arena`.
   * Anything else is refused, never ignored.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a setup.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['members', 'arena']);
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

    return new self(array_map(BattleTestMember::fromArray(...), $members), $arena);
  }

  /**
   * The setup as project data writes it; the arena only when one is chosen.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'members' => array_map(static fn(BattleTestMember $member): array => $member->toArray(), $this->members),
      ...($this->arena === null ? [] : ['arena' => $this->arena]),
    ];
  }

  /** The setup a party describes: its members, their levels and what they wear. */
  public static function getFromParty(Party $party): self
  {
    return new self(array_map(self::describeCharacter(...), array_slice($party->members->toArray(), 0, self::MAX_MEMBERS)));
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
   * actor does not have, or equipment that is not equipment, does not fit
   * its slot or cannot be worn by the actor.
   *
   * @return list<string> The problems, each naming its member; empty when the setup can be built.
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
      if ($member->level > $character->maxLevel) {
        $problems[] = sprintf('%s: level %d is beyond its highest, %d.', $place, $member->level, $character->maxLevel);
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
          $problems[] = "{$place}: {$problem}";
        }
      }
      foreach ($loadouts?->getProblems($member, $character) ?? [] as $problem) {
        $problems[] = "{$place}: {$problem}";
      }
    }

    $problems = [...$problems, ...($loadouts?->getTenancyProblems($this->members, $characters) ?? [])];

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

      return new self($members, $this->arena);
    }
    if ($index >= count($members)) {
      $members[] = $member;
    } else {
      $members[$index] = $member;
    }

    return new self(array_slice($members, 0, self::MAX_MEMBERS), $this->arena);
  }

  /** The setup fighting in an arena of the battle presentation, by key; null for its default. */
  public function withArena(?string $arena): self
  {
    return new self($this->members, $arena === null || trim($arena) === '' ? null : $arena);
  }

  /**
   * The settings a fight from this setup passes its battle: the arena,
   * when one is chosen.
   *
   * @return array<string, string>
   */
  public function getBattleSettings(): array
  {
    return $this->arena === null ? [] : ['battleArena' => $this->arena];
  }

  /**
   * Builds a fresh party: each actor as authored, at its chosen level (any
   * from 1 to its maximum) with its class's skills for that level learned
   * and its health full, wearing its equipment, and holding every item.
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
    $loadouts = $this->createLoadoutCatalog($skills, $summons);
    foreach ($this->members as $member) {
      $character = $this->createCharacter($member, $actors, $items);
      $loadouts?->applyToCharacter($member, $character);
      $party->addMember($character);
    }
    foreach ($items->getItemIds() as $id) {
      $item = $items->get($id);
      if ($item !== null && ! $item instanceof Equipment) {
        $party->addItems(...$items->instantiate($id, self::ITEM_QUANTITY, 'stocking the battle test party'));
      }
    }

    return $party;
  }

  private function createLoadoutCatalog(?SkillCatalog $skills, ?SummonCutsceneLibrary $summons): ?BattleTestLoadoutCatalog
  {
    if (array_any($this->members, static fn($member): bool => $member->commands !== null || $member->skills !== [] || $member->summons !== [])) {
      return new BattleTestLoadoutCatalog($skills ?? SkillCatalog::getProjectCatalog(), $summons ?? new SummonCutsceneLibrary());
    }
    return null;
  }

  private function createCharacter(BattleTestMember $member, ActorStore $actors, ItemStore $items): Character
  {
    $definition = $actors->require($member->actorId, 'the battle test party');
    // The actor as authored, holding the experience its class needs for the
    // level: restored as a save restores it, then taught the class's skills
    // up to that level, as a restored save is.
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
}
