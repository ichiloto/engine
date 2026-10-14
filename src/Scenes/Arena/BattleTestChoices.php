<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * What a battle test member may be given, for every interface that sets one
 * up: the in-game arena and the editors. An actor's equipment slots and
 * highest level, what each slot accepts that the actor can wear, and the
 * commands, skills, spells and summons a test loadout may grant, each a
 * choice the setup would accept ({@see BattleTestSetup::getProblems()}).
 */
final readonly class BattleTestChoices
{
  public function __construct(
    private ActorStore $actors,
    private ItemStore $items,
    private BattleTestLoadoutCatalog $loadouts,
  ) {}

  /** @return list<string> The actor's equipment slot names, in its own order. */
  public function getSlotNames(string $actorId): array
  {
    return array_map(static fn($slot): string => $slot->name, $this->createProbe($actorId)->equipment);
  }

  /** The actor's highest level. */
  public function getMaxLevel(string $actorId): int
  {
    return $this->createProbe($actorId)->maxLevel;
  }

  /**
   * What an actor can wear in a slot: nothing, then every equipment item
   * the slot accepts and the actor can equip, in authored order.
   *
   * @return list<array{id: ?string, name: string}>
   */
  public function getEquipmentChoices(string $actorId, string $slotName): array
  {
    $character = $this->createProbe($actorId);
    $slot = array_find($character->equipment, static fn($slot): bool => $slot->name === $slotName);
    $choices = [['id' => null, 'name' => '(None)']];

    foreach ($slot === null ? [] : $this->items->getItemIds() as $id) {
      $item = $this->items->get($id);
      if ($item instanceof Equipment && $slot->acceptsType === $item::class && $slot->semanticSlot === $item->semanticSlot
        && $character->canEquip($item)) {
        $choices[] = ['id' => $id, 'name' => $item->name];
      }
    }

    return $choices;
  }

  /**
   * What a member's loadout field may hold: `commands` (the normal menu
   * first, then each command as the actor's role names it), `skills`,
   * `magic`, or `summons`. A summon is offered only when the setup would
   * still be accepted with it, so one exclusive summon is never offered to a
   * second member.
   *
   * @return list<array{id: ?string, name: string}>
   */
  public function getLoadoutChoices(BattleTestSetup $setup, int $memberIndex, string $field): array
  {
    $member = $setup->members[$memberIndex] ?? null;
    if ($member === null) {
      return [];
    }
    if ($field === 'commands') {
      $role = $this->createProbe($member->actorId)->role->name;

      return [['id' => null, 'name' => 'Normal commands'], ...array_map(
        static fn(BattleCommandType $type): array => ['id' => $type->value, 'name' => $type->labelForRole($role)],
        BattleCommandType::cases(),
      )];
    }
    $choices = match ($field) {
      'skills' => $this->loadouts->getSkillChoices(false),
      'magic' => $this->loadouts->getSkillChoices(true),
      'summons' => $this->loadouts->getSummonChoices($this->createProbe($member->actorId)),
      default => [],
    };
    if ($field !== 'summons') {
      return $choices;
    }

    return array_values(array_filter($choices, function (array $choice) use ($setup, $memberIndex, $member): bool {
      if (in_array($choice['id'], $member->summons, true)) {
        return true;
      }
      $candidate = $setup->withMember($memberIndex, $member->withSummons(array_values(array_unique([...$member->summons, $choice['id']]))));

      return $candidate->getProblems($this->actors, $this->items) === [];
    }));
  }

  /** The actor as authored, to read its slots, level and role from. */
  private function createProbe(string $actorId): Character
  {
    return $this->actors->require($actorId, 'the battle test setup')->createCharacter();
  }
}
