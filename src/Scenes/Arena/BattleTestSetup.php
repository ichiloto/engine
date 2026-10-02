<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Util\Debug;
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

  /** @param list<BattleTestMember> $members */
  public function __construct(public array $members)
  {
    if ($members === [] || count($members) > self::MAX_MEMBERS || !array_is_list($members)) {
      throw new InvalidArgumentException(sprintf('A battle test party has 1 to %d members.', self::MAX_MEMBERS));
    }
  }

  /** The setup a party describes: its members, their levels and what they wear. */
  public static function getFromParty(Party $party): self
  {
    $members = [];
    foreach (array_slice($party->members->toArray(), 0, self::MAX_MEMBERS) as $character) {
      $equipment = [];
      foreach ($character->equipment as $slot) {
        $equipment[$slot->name] = $slot->equipment?->id;
      }
      $members[] = new BattleTestMember($character->actorId, $character->level, $equipment);
    }

    return new self($members);
  }

  /** The setup with a member placed, replaced or, given null, removed; the last member stays. */
  public function withMember(int $index, ?BattleTestMember $member): self
  {
    $members = $this->members;
    if ($member === null) {
      if (count($members) > 1 && isset($members[$index])) {
        array_splice($members, $index, 1);
      }

      return new self($members);
    }
    if ($index >= count($members)) {
      $members[] = $member;
    } else {
      $members[$index] = $member;
    }

    return new self(array_slice($members, 0, self::MAX_MEMBERS));
  }

  /**
   * Builds a fresh party: each actor as authored, at its chosen level (any
   * from 1 to its maximum) with its class's skills for that level learned
   * and its health full, wearing its equipment, and holding every item.
   * Equipment it cannot wear is left off with a logged note.
   */
  public function createParty(ActorStore $actors, ItemStore $items): Party
  {
    $party = new Party();
    foreach ($this->members as $member) {
      $party->addMember($this->createCharacter($member, $actors, $items));
    }
    foreach ($items->getItemIds() as $id) {
      $item = $items->get($id);
      if ($item !== null && ! $item instanceof Equipment) {
        $party->addItems(...$items->instantiate($id, self::ITEM_QUANTITY, 'stocking the battle test party'));
      }
    }

    return $party;
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
      if ($itemId === null) {
        continue;
      }
      $equipment = $items->get($itemId);
      if (! $equipment instanceof Equipment || ! $character->assignEquipment($slotName, $equipment)) {
        Debug::warn(sprintf('The battle test left %s off %s: it is not equipment that fits the %s slot.', $itemId, $member->actorId, $slotName));
      }
    }
    $character->restoreVitals();

    return $character;
  }
}