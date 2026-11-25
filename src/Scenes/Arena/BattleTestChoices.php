<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * What a battle test member may be given, for every interface that sets one
 * up: the in-game arena and the editors. An actor's equipment slots and
 * highest level, what each slot accepts that the actor can wear, and the
 * commands, skills, spells and summons a test loadout may grant, the HP, MP
 * and states a member may start with, and the items the party may carry,
 * each a choice the setup would accept ({@see BattleTestSetup::getProblems()}).
 */
final class BattleTestChoices
{
  /** @var array<string, list<array{world: BattleTestWorld, technique: string}>> Each actor's forms, read once: they depend on its authored crisis ability alone. */
  private array $forms = [];

  public function __construct(
    private readonly ActorStore $actors,
    private readonly ItemStore $items,
    private readonly BattleTestLoadoutCatalog $loadouts,
    private readonly ?string $assetRoot = null,
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

      return $candidate->getProblems($this->actors, $this->items, assetRoot: $this->assetRoot) === [];
    }));
  }

  /**
   * What a member's starting condition may be set to: `states` (none first,
   * then every state the project defines), or a starting resource. HP and MP offer
   * full first; for HP, where the actor's crisis ability activates below an
   * HP threshold, a starting amount below it; then none
   * (knocked out for HP, empty for MP); then the amount the member starts
   * with counted the other way, when rounding can preserve a positive amount.
   * Each choice's id is
   * the amount as typed ({@see BattleTestVital::parse()}). Null keeps full HP/MP or the authored AP.
   *
   * @return list<array{id: ?string, name: string}>
   */
  public function getConditionChoices(BattleTestSetup $setup, int $memberIndex, string $field): array
  {
    $member = $setup->members[$memberIndex] ?? null;
    if ($member === null) {
      return [];
    }
    if ($field === 'form') {
      return $this->getFormChoices($member->actorId);
    }
    if ($field === 'states') {
      return [['id' => null, 'name' => '(None)'], ...array_values(array_map(
        static fn(State $state): array => ['id' => $state->id, 'name' => $state->name], StateRegistry::all($this->assetRoot)))];
    }
    $resource = BattleTestResource::tryFrom($field);
    if ($resource === null || ($resource === BattleTestResource::AP && !$this->offersActionPoints())) {
      return [];
    }
    $character = $setup->createMemberCharacter($member, $this->actors, $this->items, $this->assetRoot);
    return $this->getVitalChoices($member, $character, $resource);
  }

  /**
   * Starting resource limits and choices in project data format.
   *
   * @return array<string, array{maximum: int, default: array<string, int>, choices: list<array{name: string, value: ?array<string, int>}>}>
   */
  public function describeStartingVitals(BattleTestSetup $setup, int $memberIndex): array
  {
    $member = $setup->members[$memberIndex] ?? null;
    if ($member === null) {
      return [];
    }
    $character = $setup->createMemberCharacter($member, $this->actors, $this->items, $this->assetRoot);
    $vitals = [];
    foreach (BattleTestResource::cases() as $resource) {
      if ($resource === BattleTestResource::AP && !$this->offersActionPoints()) {
        continue;
      }
      $vitals[$resource->value] = [
        'maximum' => $resource->getMaximum($character->stats),
        'default' => $this->getDefaultVital($character, $resource)->toArray(),
        'choices' => array_map(
          static fn(array $choice): array => ['name' => $choice['name'],
            'value' => $choice['id'] === null ? null : BattleTestVital::parse($choice['id'])->toArray()],
          $this->getVitalChoices($member, $character, $resource),
        ),
      ];
    }

    return $vitals;
  }

  /** @return list<array{id: ?string, name: string}> */
  private function getVitalChoices(BattleTestMember $member, Character $character, BattleTestResource $resource): array
  {
    $maximum = $resource->getMaximum($character->stats);
    $ap = $resource === BattleTestResource::AP;
    $choices = $ap ? [
      ['id' => null, 'name' => sprintf('Authored balance (%d)', $character->stats->currentAp)],
      ['id' => '100%', 'name' => 'Full'],
    ] : [['id' => null, 'name' => 'Full']];
    $crisis = $resource === BattleTestResource::HP ? $this->getCrisisThresholdVital($character) : null;
    if ($crisis !== null) {
      $choices[] = ['id' => $crisis->format(), 'name' => sprintf('Below %s: %s%s', $character->crisisAbility?->name, $crisis->format(),
        $crisis->unit === BattleTestVitalUnit::POINTS ? ' HP' : '')];
    }
    $choices[] = ['id' => '0%', 'name' => $resource === BattleTestResource::HP ? 'Knocked out' : 'Empty'];
    $current = $member->getVital($resource) ?? $this->getDefaultVital($character, $resource);
    $other = $current->convert($maximum);
    $duplicate = $ap && in_array($other->format(), array_column($choices, 'id'), true);
    if (!$duplicate && ($current->resolvePoints($maximum) === 0 || $other->resolvePoints($maximum) > 0)) {
      $choices[] = ['id' => $other->format(), 'name' => $other->unit === BattleTestVitalUnit::POINTS
        ? sprintf('In points: %d of %d', $other->amount, $maximum) : sprintf('In percent: %s', $other->format())];
    }

    return $choices;
  }

  private function getDefaultVital(Character $character, BattleTestResource $resource): BattleTestVital
  {
    return $resource === BattleTestResource::AP
      ? new BattleTestVital($character->stats->currentAp, BattleTestVitalUnit::POINTS)
      : BattleTestVital::createFull();
  }

  /**
   * The forms an actor's techniques may take in a test fight, by the story
   * facts its crisis ability's forms depend on ({@see BattleTestWorld::listCharacterForms()}):
   * none first, then each form, named by the technique it offers and the
   * facts that choose it. Each choice's id is the facts as project data
   * writes them, and `world` holds them. No choices for an actor whose
   * techniques depend on no such facts.
   *
   * @return list<array{id: ?string, name: string, world: ?BattleTestWorld, technique: ?string}>
   */
  public function getFormChoices(string $actorId): array
  {
    $forms = $this->listForms($actorId);

    return $forms === [] ? [] : [['id' => null, 'name' => 'None', 'world' => null, 'technique' => null], ...array_map(static fn(array $form): array => [
      'id' => self::getWorldKey($form['world']), 'name' => sprintf('%s (%s)', $form['technique'], $form['world']->describe()),
      'world' => $form['world'], 'technique' => $form['technique']], $forms)];
  }

  /**
   * One of an actor's forms, by the technique it offers or a value of the
   * facts that choose it, ignoring case; none when no form matches.
   */
  public function findForm(string $actorId, string $reference): ?BattleTestWorld
  {
    $reference = mb_strtolower(trim($reference));
    $form = array_find($this->listForms($actorId), static fn(array $form): bool => mb_strtolower($form['technique']) === $reference
      || in_array($reference, array_map(static fn(int|float|string $value): string => mb_strtolower((string) $value), $form['world']->variables), true));

    return $form['world'] ?? null;
  }

  /** @return list<array{world: BattleTestWorld, technique: string}> */
  private function listForms(string $actorId): array
  {
    return $this->forms[$actorId] ??= BattleTestWorld::listCharacterForms($this->createProbe($actorId));
  }

  /** The id a choice of story facts goes by: the facts as project data writes them. */
  public static function getWorldKey(?BattleTestWorld $world): ?string
  {
    return $world === null ? null : json_encode($world->toArray(), JSON_THROW_ON_ERROR);
  }

  public function offersActionPoints(): bool
  {
    return $this->actors->actionPointPolicy !== null;
  }

  public function getActorStartingAp(string $actorId): int
  {
    return $this->createProbe($actorId)->stats->currentAp;
  }

  /** A member's resource maximum as it fights: at its level and in its equipment. */
  public function getVitalMaximum(BattleTestSetup $setup, int $memberIndex, string $field): int
  {
    $member = $setup->members[$memberIndex] ?? null;
    $resource = BattleTestResource::tryFrom($field);
    if ($member === null || $resource === null || ($resource === BattleTestResource::AP && !$this->offersActionPoints())) {
      return 0;
    }

    return $resource->getMaximum($setup->createMemberCharacter($member, $this->actors, $this->items, $this->assetRoot)->stats);
  }

  /**
   * Every item a battle test party may carry, in authored order, with the
   * most of it a party can hold.
   *
   * @return list<array{id: string, name: string, maxQuantity: int}>
   */
  public function getItemChoices(): array
  {
    return array_values(array_filter(array_map(function (string $id): ?array {
      $item = $this->items->get($id);
      return $item === null ? null : ['id' => $id, 'name' => $item->name, 'maxQuantity' => $item->maxQuantity];
    }, BattleTestSetup::listCarriedItemIds($this->items))));
  }

  /**
   * The items a party holds when none are set ({@see BattleTestSetup::getDefaultStock()}).
   *
   * @return list<BattleTestItem>
   */
  public function getDefaultStock(): array
  {
    return BattleTestSetup::getDefaultStock($this->items);
  }

  /** The highest whole percentage below the HP threshold, or one HP for a sub-percent threshold. */
  private function getCrisisThresholdVital(Character $character): ?BattleTestVital
  {
    $ability = $character->crisisAbility;
    $threshold = $ability?->hpBelowPercent;
    if ($ability === null || $threshold === null) {
      return null;
    }

    $maximum = $character->stats->totalHp;
    if (!$ability->isHpTriggerMet(1, $maximum)) {
      return null;
    }

    $percent = (int) ceil($threshold) - 1;
    return $percent > 0 ? new BattleTestVital($percent) : new BattleTestVital(1, BattleTestVitalUnit::POINTS);
  }

  /** The actor as authored, to read its slots, level and role from. */
  private function createProbe(string $actorId): Character
  {
    return $this->actors->require($actorId, 'the battle test setup')->createCharacter(assetRoot: $this->assetRoot, items: $this->items);
  }
}
