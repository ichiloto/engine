<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Closure;
use Ichiloto\Engine\Battle\BattleCommandType;

/**
 * Where the arena's setup is and what each move does, apart from drawing and
 * input devices: the arena reads semantic moves (up, down, left, right, a
 * page step, confirm, cancel) and passes them here, and draws what this
 * says. Focus moves from the troop list down into the party, into one
 * member's actor, level, starting HP, MP, states and form, and equipment, and into
 * a list of what one of them can be, and back out again with cancel. A page
 * step switches between the troop list, the party and, where items can be
 * set, the party's items ({@see BattleTestInventoryEditor}). Where arenas
 * are offered (a graphical renderer draws them), left and right on the troop
 * list, and in the composer, choose the one fights take place in. After the
 * troops, a row composes a test troop ({@see TestTroopComposer}), to fight
 * and, where someone keeps troops, keep.
 */
final class ArenaSetupEditor
{
  public const string TROOPS = 'troops';
  public const string PARTY = 'party';
  public const string MEMBER = 'member';
  public const string CHOOSER = 'chooser';
  public const string COMPOSER = 'composer';
  public const string ITEMS = 'items';
  /** Confirm on the troop list asks for a fight; cancel there asks to leave. */
  public const string FIGHT = 'fight';
  public const string QUIT = 'quit';
  /** The composer asks to fight its test troop, or to keep it as a troop. */
  public const string FIGHT_TEST = 'fight-test';
  public const string KEEP = 'keep';
  /** A member's fields that step by one with left and right and by ten with a page step. */
  public const array VITAL_FIELDS = ['hp', 'mp'];
  /** A member's fields whose choices are toggled, several at once. */
  private const array TOGGLED_FIELDS = ['commands', 'skills', 'magic', 'summons', 'states'];

  public private(set) string $focus = self::TROOPS;
  public private(set) int $troopIndex = 0;
  public private(set) int $memberIndex = 0;
  public private(set) int $fieldIndex = 0;
  public private(set) int $choiceIndex = 0;
  private string $partyReturnFocus = self::TROOPS;

  /** The left pane keeps its list while the party pane has focus. */
  public string $listFocus {
    get => $this->focus === self::PARTY ? $this->partyReturnFocus : $this->focus;
  }

  /**
   * @param list<string> $actorIds Every actor a member can be, in order.
   * @param Closure(string): list<string> $slotsFor The equipment slot names of an actor.
   * @param Closure(string, string): list<array{id: ?string, name: string}> $choicesFor What an actor can wear in a slot, None first.
   * @param Closure(string): int $maxLevelFor An actor's highest level.
   * @param Closure(BattleTestMember, string): list<array{id: ?string, name: string}>|null $loadoutChoicesFor Shared resource pickers.
   * @param list<string> $arenaKeys The arenas fights may take place in, by key; none when nothing draws them.
   * @param Closure(BattleTestMember, string): list<array{id: ?string, name: string}>|null $conditionChoicesFor What a member's
   *   `hp`, `mp`, `states` and `form` may be ({@see BattleTestChoices::getConditionChoices()}); none leaves the starting condition unset.
   * @param Closure(BattleTestMember, string): int|null $vitalMaximumFor A member's maximum `hp` or `mp`, to count points within.
   * @param BattleTestInventoryEditor|null $inventoryEditor Setting the party's items; none where they cannot be set.
   */
  public function __construct(
    public private(set) BattleTestSetup $setup,
    private int $troopCount,
    private readonly array $actorIds,
    private readonly Closure $slotsFor,
    private readonly Closure $choicesFor,
    private readonly Closure $maxLevelFor,
    private readonly ?Closure $loadoutChoicesFor = null,
    private readonly array $arenaKeys = [],
    public readonly ?TestTroopComposer $composer = null,
    private readonly ?Closure $conditionChoicesFor = null,
    private readonly ?Closure $vitalMaximumFor = null,
    public readonly ?BattleTestInventoryEditor $inventoryEditor = null,
  ) {
    $this->inventoryEditor?->load($setup->inventory);
  }

  /** The rows of the troop list: the troops, then composing a test troop where it can be. */
  public int $troopRows {
    get => $this->troopCount + ($this->composer === null ? 0 : 1);
  }

  /** Whether the cursor is on the troop list's row that composes a test troop. */
  public bool $onComposeRow {
    get => $this->composer !== null && $this->troopIndex === $this->troopCount;
  }

  /** Whether a member's starting HP, MP and states can be set here. */
  public bool $offersCondition {
    get => $this->conditionChoicesFor !== null && $this->vitalMaximumFor !== null;
  }

  /** The troops changed (one was kept); the cursor stays on the same row, or the last. */
  public function setTroopCount(int $count): void
  {
    $composing = $this->onComposeRow;
    $this->troopCount = max(0, $count);
    $this->troopIndex = $composing ? $this->troopRows - 1 : max(0, min($this->troopRows - 1, $this->troopIndex));
  }

  /** Whether fights here may be given an arena. */
  public bool $offersArenas {
    get => $this->arenaKeys !== [];
  }

  /** The member being edited, or null outside a member. */
  public ?BattleTestMember $member {
    get => $this->setup->members[$this->memberIndex] ?? null;
  }

  /**
   * The member's fields: actor, level, the starting HP, MP and states where they can be set, its form where
   * its techniques depend on story facts a test fight can set, each
   * equipment slot, the test loadout, and removal when the party has more than one member.
   */
  public array $fields {
    get {
      $member = $this->member;
      if ($member === null) { return []; }

      return ['actor', 'level', ...($this->offersCondition ? ['hp', 'mp', 'states'] : []),
        ...($this->offersCondition && ($this->conditionChoicesFor)($member, 'form') !== [] ? ['form'] : []),
        ...array_map(static fn(string $slot): string => "slot:{$slot}", ($this->slotsFor)($member->actorId)),
        ...($this->loadoutChoicesFor === null ? [] : ['commands', 'skills', 'magic', 'summons']),
        ...(count($this->setup->members) > 1 ? ['remove'] : [])];
    }
  }

  /** The member's field the cursor is on, or an empty string outside a member. */
  public string $field {
    get => $this->fields[$this->fieldIndex] ?? '';
  }

  /** Whether the field being chosen for takes several choices, each toggled, rather than one. */
  public bool $choosesSeveral {
    get => in_array($this->field, self::TOGGLED_FIELDS, true);
  }

  /** @return list<array{id: ?string, name: string}> What the field being chosen for can be. */
  public function getChoices(): array
  {
    $field = $this->field;

    if ($this->member === null) { return []; }
    return match (true) {
      str_starts_with($field, 'slot:') => ($this->choicesFor)($this->member->actorId, substr($field, 5)),
      in_array($field, [...self::VITAL_FIELDS, 'states', 'form'], true) => $this->conditionChoicesFor === null ? []
        : ($this->conditionChoicesFor)($this->member, $field),
      default => $this->loadoutChoicesFor === null ? [] : ($this->loadoutChoicesFor)($this->member, $field),
    };
  }

  public function isChoiceSelected(?string $id): bool
  {
    $member = $this->member;
    $field = $this->field;
    if ($member === null) { return false; }
    return match ($field) {
      'commands' => $id === null ? $member->commands === null
        : in_array(BattleCommandType::tryFrom($id), $member->commands ?? BattleCommandType::cases(), true),
      'skills', 'magic' => $id !== null && in_array($id, $member->skills, true),
      'summons' => $id !== null && in_array($id, $member->summons, true),
      'states' => $id === null ? $member->states === [] : in_array($id, $member->states, true),
      'hp' => $id === $member->hp?->format(),
      'mp' => $id === $member->mp?->format(),
      'form' => $id === BattleTestChoices::getWorldKey($member->world),
      default => $id === ($member->equipment[substr($field, 5)] ?? null),
    };
  }

  public function moveVertical(int $step): void
  {
    match ($this->focus) {
      // Each pane keeps its own cursor; only Tab and Shift+Tab move between them.
      self::TROOPS => $this->troopIndex = max(0, min(max(0, $this->troopRows - 1), $this->troopIndex + $step)),
      self::COMPOSER => $this->composer?->moveVertical($step),
      self::ITEMS => $this->inventoryEditor?->moveVertical($step),
      self::PARTY => $this->memberIndex = max(0, min(BattleTestSetup::MAX_MEMBERS - 1, $this->memberIndex + $step)),
      self::MEMBER => $this->fieldIndex = max(0, min(count($this->fields) - 1, $this->fieldIndex + $step)),
      self::CHOOSER => $this->choiceIndex = max(0, min(count($this->getChoices()) - 1, $this->choiceIndex + $step)),
    };
  }

  /**
   * Left and right change the arena on the troop list, a quantity of the
   * party's items by ten, and the member's actor, level, HP, MP or form.
   */
  public function moveHorizontal(int $step): void
  {
    if ($this->focus === self::ITEMS) {
      $this->inventoryEditor?->moveHorizontal($step);

      return;
    }
    if ($this->focus === self::TROOPS || ($this->focus === self::COMPOSER && !($this->composer?->moveHorizontal($step) ?? false))) {
      $this->stepArena($step);

      return;
    }

    $member = $this->member;
    if ($this->focus !== self::MEMBER || $member === null) { return; }
    match ($this->field) {
      'actor' => $this->changeActor($member, $step),
      'level' => $this->stepLevel($step),
      'hp', 'mp' => $this->stepVital($this->field, $step),
      'form' => $this->stepForm($member, $step),
      default => null,
    };
  }

  /**
   * Tab and Shift+Tab (the menus' shared next and previous actions) switch
   * the top-level panes: troops, party and items.
   */
  public function movePane(int $direction): void
  {
    if ($direction > 0 && ($this->focus === self::TROOPS
      || ($this->focus === self::COMPOSER && $this->composer?->focus === TestTroopComposer::MEMBERS))) {
      $this->enterParty();
    } elseif ($direction > 0 && $this->focus === self::PARTY && $this->inventoryEditor !== null) {
      $this->focus = self::ITEMS;
    } elseif ($direction < 0 && $this->focus === self::ITEMS && $this->inventoryEditor?->focus === BattleTestInventoryFocus::ENTRIES) {
      $this->focus = self::PARTY;
    } elseif ($direction < 0 && $this->focus === self::PARTY) {
      $this->focus = $this->partyReturnFocus;
    }
  }

  /**
   * Page actions turn the troop list a page of rows at a time and, inside a
   * member, step its HP or MP by ten on their rows and its level by ten
   * elsewhere.
   */
  public function movePage(int $direction, int $pageRows): void
  {
    if ($this->focus === self::MEMBER) {
      in_array($this->field, self::VITAL_FIELDS, true) ? $this->stepVital($this->field, 10 * $direction) : $this->stepLevel(10 * $direction);
    } elseif ($this->focus === self::TROOPS && $this->troopRows > 0) {
      $this->troopIndex = max(0, min($this->troopRows - 1, $this->troopIndex + $direction * max(1, $pageRows)));
    }
  }

  /** Changes the member's level by a step, within 1 and the actor's highest. */
  public function stepLevel(int $step): void
  {
    $member = $this->member;
    if ($this->focus !== self::MEMBER || $member === null) { return; }
    $level = max(1, min(($this->maxLevelFor)($member->actorId), $member->level + $step));
    $this->setup = $this->setup->withMember($this->memberIndex, $member->withLevel($level));
  }

  /**
   * Changes the HP or MP the member starts with by a step in the unit it is
   * counted in: percent within 0 and 100, points within 0 and the member's
   * maximum. Full is 100 percent.
   */
  public function stepVital(string $field, int $step): void
  {
    $member = $this->member;
    if ($this->focus !== self::MEMBER || $member === null || $this->vitalMaximumFor === null || !in_array($field, self::VITAL_FIELDS, true)) {
      return;
    }
    $vital = ($field === 'hp' ? $member->hp : $member->mp) ?? BattleTestVital::createFull();
    $next = $vital->withAmount($vital->amount + $step, $vital->unit === BattleTestVitalUnit::POINTS
      ? ($this->vitalMaximumFor)($member, $field) : 100);
    $this->setup = $this->setup->withMember($this->memberIndex, $field === 'hp' ? $member->withHp($next) : $member->withMp($next));
  }

  /** Steps the member's form through its choices, none first, round to the start. */
  private function stepForm(BattleTestMember $member, int $step): void
  {
    $choices = $this->getChoices();
    if ($choices === []) { return; }
    $index = array_find_key($choices, fn(array $choice): bool => $this->isChoiceSelected($choice['id'])) ?? 0;
    $next = $choices[(($index + $step) % count($choices) + count($choices)) % count($choices)];
    $this->setup = $this->setup->withMember($this->memberIndex, $member->withWorld($next['world'] ?? null));
  }

  /** @return self::FIGHT|self::FIGHT_TEST|self::KEEP|null A fight to start or a troop to keep, when confirm asks for one. */
  public function confirm(): ?string
  {
    switch ($this->focus) {
      case self::TROOPS:
        if ($this->onComposeRow) {
          $this->focus = self::COMPOSER;
          return null;
        }
        return $this->troopCount > 0 ? self::FIGHT : null;
      case self::COMPOSER:
        return match ($this->composer?->confirm()) {
          TestTroopComposer::FIGHT => self::FIGHT_TEST,
          TestTroopComposer::KEEP => self::KEEP,
          default => null,
        };
      case self::ITEMS:
        $this->inventoryEditor?->confirm();
        $this->setup = $this->setup->withInventory($this->inventoryEditor?->items);
        return null;
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
        $field = $this->field;
        if ($field === 'remove') {
          $this->setup = $this->setup->withMember($this->memberIndex, null);
          $this->memberIndex = min($this->memberIndex, count($this->setup->members) - 1);
          $this->focus = self::PARTY;
        } elseif ($this->choosesSeveral) {
          $this->choiceIndex = 0;
          $this->focus = self::CHOOSER;
        } elseif (str_starts_with($field, 'slot:') || in_array($field, [...self::VITAL_FIELDS, 'form'], true)) {
          // One choice: the cursor starts on what is chosen now.
          $choices = $this->getChoices();
          $this->choiceIndex = max(0, (int) array_find_key($choices, fn(array $choice): bool => $this->isChoiceSelected($choice['id'])));
          $this->focus = self::CHOOSER;
        }
        return null;
      case self::CHOOSER:
        $choice = $this->getChoices()[$this->choiceIndex] ?? null;
        $member = $this->member;
        if ($choice !== null && $member !== null) {
          $field = $this->field;
          if ($this->choosesSeveral) {
            $this->toggleChoice($member, $field, $choice['id']);
            return null;
          }
          $this->setup = $this->setup->withMember($this->memberIndex, match ($field) {
            'hp' => $member->withHp($choice['id'] === null ? null : BattleTestVital::parse($choice['id'])),
            'mp' => $member->withMp($choice['id'] === null ? null : BattleTestVital::parse($choice['id'])),
            'form' => $member->withWorld($choice['world'] ?? null),
            default => $member->withEquipment(substr($field, 5), $choice['id']),
          });
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
      case self::COMPOSER:
        if ($this->composer?->cancel() === TestTroopComposer::LEAVE) { $this->focus = self::TROOPS; }
        break;
      case self::ITEMS:
        if ($this->inventoryEditor?->cancel() === BattleTestInventoryEditor::LEAVE) { $this->focus = self::PARTY; }
        break;
      case self::PARTY:
        $this->focus = $this->partyReturnFocus;
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
    $this->troopIndex = max(0, min($this->troopRows - 1, $index));
    $this->focus = self::TROOPS;
  }

  /** Steps through the arenas, the presentation's default first. */
  private function stepArena(int $step): void
  {
    if ($this->arenaKeys === []) { return; }
    $choices = [null, ...$this->arenaKeys];
    $index = array_search($this->setup->arena, $choices, true);
    $next = $choices[((($index === false ? 0 : $index) + $step) % count($choices) + count($choices)) % count($choices)];
    $this->setup = $this->setup->withArena($next);
  }

  private function enterParty(): void
  {
    $this->partyReturnFocus = $this->focus;
    $this->focus = self::PARTY;
    $this->memberIndex = 0;
  }

  /** Toggles one of several choices; the first choice, with no id, clears them or, for commands, restores the normal menu. */
  private function toggleChoice(BattleTestMember $member, string $field, ?string $id): void
  {
    if ($field === 'commands') {
      $commands = $member->commands ?? BattleCommandType::cases();
      $type = $id === null ? null : BattleCommandType::tryFrom($id);
      if ($type !== null) {
        $commands = in_array($type, $commands, true)
          ? array_values(array_filter($commands, static fn($command): bool => $command !== $type)) : [...$commands, $type];
        if ($commands === []) { return; }
      }
      $member = $member->withCommands($id === null ? null : $commands);
    } else {
      $selected = match ($field) { 'summons' => $member->summons, 'states' => $member->states, default => $member->skills };
      if ($id === null) {
        $selected = $field === 'skills' || $field === 'magic' ? array_values(array_diff($selected, array_column($this->getChoices(), 'id'))) : [];
      } else {
        $selected = in_array($id, $selected, true)
          ? array_values(array_diff($selected, [$id])) : [...$selected, $id];
      }
      $member = match ($field) {
        'summons' => $member->withSummons($selected),
        'states' => $member->withStates($selected),
        default => $member->withSkills($selected),
      };
    }
    $this->setup = $this->setup->withMember($this->memberIndex, $member);
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
