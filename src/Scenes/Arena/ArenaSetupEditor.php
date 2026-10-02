<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Closure;

/**
 * Where the arena's setup is and what each move does, apart from drawing and
 * input devices: the arena reads semantic moves (up, down, left, right, a
 * page step, confirm, cancel) and passes them here, and draws what this
 * says. Focus moves from the troop list down into the party, into one
 * member's actor, level and equipment, and into a list of what a slot can
 * hold, and back out again with cancel.
 */
final class ArenaSetupEditor
{
  public const string TROOPS = 'troops';
  public const string PARTY = 'party';
  public const string MEMBER = 'member';
  public const string CHOOSER = 'chooser';
  /** Confirm on the troop list asks for a fight; cancel there asks to leave. */
  public const string FIGHT = 'fight';
  public const string QUIT = 'quit';

  public private(set) string $focus = self::TROOPS;
  public private(set) int $troopIndex = 0;
  public private(set) int $memberIndex = 0;
  public private(set) int $fieldIndex = 0;
  public private(set) int $choiceIndex = 0;

  /**
   * @param list<string> $actorIds Every actor a member can be, in order.
   * @param Closure(string): list<string> $slotsFor The equipment slot names of an actor.
   * @param Closure(string, string): list<array{id: ?string, name: string}> $choicesFor What an actor can wear in a slot, None first.
   * @param Closure(string): int $maxLevelFor An actor's highest level.
   */
  public function __construct(
    public private(set) BattleTestSetup $setup,
    private readonly int $troopCount,
    private readonly array $actorIds,
    private readonly Closure $slotsFor,
    private readonly Closure $choicesFor,
    private readonly Closure $maxLevelFor,
  ) {}

  /** The member being edited, or null outside a member. */
  public ?BattleTestMember $member {
    get => $this->setup->members[$this->memberIndex] ?? null;
  }

  /** The member's fields: actor, level, each equipment slot, and removal when the party has more than one member. */
  public array $fields {
    get {
      $member = $this->member;
      if ($member === null) { return []; }

      return ['actor', 'level', ...array_map(static fn(string $slot): string => "slot:{$slot}", ($this->slotsFor)($member->actorId)),
        ...(count($this->setup->members) > 1 ? ['remove'] : [])];
    }
  }

  /** @return list<array{id: ?string, name: string}> What the slot being chosen for can hold. */
  public function getChoices(): array
  {
    $field = $this->fields[$this->fieldIndex] ?? '';

    return $this->member !== null && str_starts_with($field, 'slot:')
      ? ($this->choicesFor)($this->member->actorId, substr($field, 5)) : [];
  }

  public function moveVertical(int $step): void
  {
    match ($this->focus) {
      self::TROOPS => $this->troopIndex + $step >= $this->troopCount && $step > 0
        ? $this->enterParty()
        : $this->troopIndex = max(0, min($this->troopCount - 1, $this->troopIndex + $step)),
      self::PARTY => $this->memberIndex + $step < 0
        ? $this->focus = self::TROOPS
        : $this->memberIndex = min(BattleTestSetup::MAX_MEMBERS - 1, $this->memberIndex + $step),
      self::MEMBER => $this->fieldIndex = max(0, min(count($this->fields) - 1, $this->fieldIndex + $step)),
      self::CHOOSER => $this->choiceIndex = max(0, min(count($this->getChoices()) - 1, $this->choiceIndex + $step)),
    };
  }

  /** Left and right change the member's actor or level. */
  public function moveHorizontal(int $step): void
  {
    $member = $this->member;
    if ($this->focus !== self::MEMBER || $member === null) { return; }
    match ($this->fields[$this->fieldIndex] ?? '') {
      'actor' => $this->changeActor($member, $step),
      'level' => $this->stepLevel($step),
      default => null,
    };
  }

  /** Changes the member's level by a step, within 1 and the actor's highest. */
  public function stepLevel(int $step): void
  {
    $member = $this->member;
    if ($this->focus !== self::MEMBER || $member === null) { return; }
    $level = max(1, min(($this->maxLevelFor)($member->actorId), $member->level + $step));
    $this->setup = $this->setup->withMember($this->memberIndex, $member->withLevel($level));
  }

  /** @return self::FIGHT|null A fight to start, when confirm asks for one. */
  public function confirm(): ?string
  {
    switch ($this->focus) {
      case self::TROOPS:
        return $this->troopCount > 0 ? self::FIGHT : null;
      case self::PARTY:
        // An empty place becomes a new member, the first actor not yet in the party.
        if ($this->member === null) {
          $used = array_map(static fn(BattleTestMember $member): string => $member->actorId, $this->setup->members);
          $actorId = array_values(array_diff($this->actorIds, $used))[0] ?? $this->actorIds[0] ?? null;
          if ($actorId === null) { return null; }
          $this->setup = $this->setup->withMember($this->memberIndex, new BattleTestMember($actorId, 1));
          $this->memberIndex = count($this->setup->members) - 1;
        }
        $this->focus = self::MEMBER;
        $this->fieldIndex = 0;
        return null;
      case self::MEMBER:
        $field = $this->fields[$this->fieldIndex] ?? '';
        if ($field === 'remove') {
          $this->setup = $this->setup->withMember($this->memberIndex, null);
          $this->memberIndex = min($this->memberIndex, count($this->setup->members) - 1);
          $this->focus = self::PARTY;
        } elseif (str_starts_with($field, 'slot:')) {
          $current = $this->member?->equipment[substr($field, 5)] ?? null;
          $this->choiceIndex = max(0, (int) array_search($current, array_column($this->getChoices(), 'id'), true));
          $this->focus = self::CHOOSER;
        }
        return null;
      case self::CHOOSER:
        $choice = $this->getChoices()[$this->choiceIndex] ?? null;
        $member = $this->member;
        if ($choice !== null && $member !== null) {
          $this->setup = $this->setup->withMember($this->memberIndex,
            $member->withEquipment(substr($this->fields[$this->fieldIndex], 5), $choice['id']));
        }
        $this->focus = self::MEMBER;
        return null;
    }

    return null;
  }

  /** @return self::QUIT|null Leaving, when cancel on the troop list asks for it. */
  public function cancel(): ?string
  {
    switch ($this->focus) {
      case self::TROOPS:
        return self::QUIT;
      case self::PARTY:
        $this->focus = self::TROOPS;
        break;
      case self::MEMBER:
        $this->focus = self::PARTY;
        break;
      case self::CHOOSER:
        $this->focus = self::MEMBER;
        break;
    }

    return null;
  }

  /** Selects a troop by its place in the list, such as one named at launch. */
  public function selectTroop(int $index): void
  {
    $this->troopIndex = max(0, min($this->troopCount - 1, $index));
    $this->focus = self::TROOPS;
  }

  private function enterParty(): void
  {
    $this->focus = self::PARTY;
    $this->memberIndex = 0;
  }

  private function changeActor(BattleTestMember $member, int $step): void
  {
    if ($this->actorIds === []) { return; }
    $index = array_search($member->actorId, $this->actorIds, true);
    $next = $this->actorIds[(($index === false ? 0 : $index) + $step + count($this->actorIds)) % count($this->actorIds)];
    $level = min($member->level, ($this->maxLevelFor)($next));
    $this->setup = $this->setup->withMember($this->memberIndex, $member->withActor($next)->withLevel($level));
  }
}
