<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\RemoveStateEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Field\FieldConditions;

// Synthetic states and items only: a game's own conditions and cures are its authored content.

beforeEach(function () {
  $this->stateRegistry = new ReflectionProperty(StateRegistry::class, 'states')->getValue();
});

afterEach(function () {
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, $this->stateRegistry);
});

function walkingVenom(array $overrides = []): State
{
  return State::fromArray([
    'id' => 'venom',
    'name' => 'Venom',
    'tickFormula' => '-10',
    'persistsAfterBattle' => true,
    'fieldTickSteps' => 3,
    ...$overrides,
  ]);
}

function conditionsMember(string $name = 'Walker', int $hp = 100): Character
{
  return new Character($name, 0, new Stats(currentHp: $hp, totalHp: 100, currentMp: 10, totalMp: 10));
}

function cureItem(array $stateIds = ['venom'], int $quantity = 3, ?ItemScope $scope = null, Occasion $occasion = Occasion::ALWAYS): Item
{
  return new Item('Cure', 'Cures venom.', '', 10, $quantity, scope: $scope ?? new ItemScope(ItemScopeSide::ALLY),
    occasion: $occasion, effects: [new RemoveStateEffect('Cure venom', '', $stateIds)], id: 'item.cure');
}

it('hydrates field and rest behaviour with engine defaults and refuses invalid values', function () {
  $plain = State::fromArray(['id' => 'plain', 'name' => 'Plain']);

  expect($plain->fieldTickSteps)->toBeNull()
    ->and($plain->fieldTickKnocksOut)->toBeFalse()
    ->and($plain->curedByRest)->toBeTrue()
    ->and($plain->badgeLabel)->toBe('PLA')
    ->and(State::fromArray(['id' => 'x', 'name' => 'X', 'label' => 'XYZ'])->badgeLabel)->toBe('XYZ')
    ->and(walkingVenom(['curedByRest' => false, 'fieldTickKnocksOut' => true]))
    ->curedByRest->toBeFalse()->fieldTickKnocksOut->toBeTrue()->fieldTickSteps->toBe(3)
    ->and(fn() => State::fromArray(['id' => 'x', 'fieldTickSteps' => 0]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'x', 'fieldTickSteps' => '3']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'x', 'curedByRest' => 'yes']))->toThrow(InvalidArgumentException::class);
});

it('acts once per authored number of steps and never in the field without them', function () {
  $member = conditionsMember();
  $member->addState(walkingVenom());
  $member->addState(new State('battle-only', 'Battle Only', tickFormula: '-10', persistsAfterBattle: true));

  expect($member->tickFieldStep())->toBe([])
    ->and($member->tickFieldStep())->toBe([]);
  $events = $member->tickFieldStep();

  expect($events)->toHaveCount(1)
    ->and($events[0]['state']->id)->toBe('venom')
    ->and($events[0]['hpDelta'])->toBe(-10)
    ->and($member->stats->currentHp)->toBe(90)
    ->and($member->states[0]->fieldSteps)->toBe(0);
});

it('stops field ticks at 1 HP unless the state is authored to knock out', function (bool $knocksOut) {
  $member = conditionsMember(hp: 15);
  $member->addState(walkingVenom(['fieldTickSteps' => 1, 'fieldTickKnocksOut' => $knocksOut]));

  $member->tickFieldStep();
  $second = $member->tickFieldStep();

  expect($member->stats->currentHp)->toBe($knocksOut ? 0 : 1)
    ->and($second[0]['hpDelta'])->toBe($knocksOut ? -5 : -4)
    ->and($member->isKnockedOut)->toBe($knocksOut);

  if (! $knocksOut) {
    expect($member->tickFieldStep()[0]['hpDelta'])->toBe(0)
      ->and($member->stats->currentHp)->toBe(1);
  }
})->with([true, false]);

it('advances durations on field turns and leaves a knocked-out bearer waiting', function () {
  $member = conditionsMember();
  $member->addState(walkingVenom(['fieldTickSteps' => 1, 'durationTurns' => 2]));
  $member->tickFieldStep();

  expect($member->states[0]->remainingTurns)->toBe(1);
  $member->stats->currentHp = 0;
  expect($member->tickFieldStep())->toBe([])
    ->and($member->states[0]->remainingTurns)->toBe(1);
  $member->stats->currentHp = 50;
  expect($member->tickFieldStep()[0]['expired'])->toBeTrue()
    ->and($member->hasState('venom'))->toBeFalse();
});

it('cures only the states authored as cured by rest and restores HP and MP', function () {
  $member = conditionsMember(hp: 20);
  $member->stats->currentMp = 2;
  $member->addState(walkingVenom());
  $member->addState(walkingVenom(['id' => 'curse', 'name' => 'Curse', 'curedByRest' => false]));
  $member->recoverFromRest();

  expect($member->hasState('venom'))->toBeFalse()
    ->and($member->hasState('curse'))->toBeTrue()
    ->and([$member->stats->currentHp, $member->stats->currentMp])->toBe([100, 10]);
});

it('keeps field step progress through saves and restarts it when re-authored with fewer steps', function () {
  $venom = walkingVenom(['fieldTickSteps' => 5]);
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, ['venom' => $venom]);
  $member = conditionsMember();
  $member->addState($venom);
  $member->tickFieldStep();
  $member->tickFieldStep();
  $member->tickFieldStep();

  $restored = unserialize(serialize($member));
  expect($restored->states[0]->fieldSteps)->toBe(3);

  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, ['venom' => walkingVenom(['fieldTickSteps' => 2])]);
  $shortened = unserialize(serialize($member));
  expect($shortened->states[0]->fieldSteps)->toBe(1)
    ->and($shortened->tickFieldStep())->toHaveCount(1);
});

it('reads and writes a remove-state item effect and refuses malformed ones', function () {
  $data = ['kind' => 'item', 'id' => 'item.cure', 'name' => 'Cure', 'description' => '', 'icon' => '', 'price' => 10,
    'effects' => [['type' => 'remove_state', 'name' => 'Cure venom', 'description' => 'Cures venom.', 'stateIds' => ['venom', 'curse']]]];
  $item = ItemRecord::readItem($data);

  expect($item->effects[0])->toBeInstanceOf(RemoveStateEffect::class)
    ->and($item->effects[0]->stateIds)->toBe(['venom', 'curse'])
    ->and(ItemRecord::writeItem($item))->toBe($data)
    ->and(fn() => ItemRecord::readItem([...$data, 'effects' => [[...$data['effects'][0], 'stateIds' => []]]]))
    ->toThrow(InvalidArgumentException::class, 'stateIds')
    ->and(fn() => ItemRecord::readItem([...$data, 'effects' => [[...$data['effects'][0], 'value' => 5]]]))
    ->toThrow(InvalidArgumentException::class, 'Unknown key')
    ->and(fn() => ItemRecord::readItem([...$data, 'effects' => [['type' => 'hp_recovery', 'name' => '', 'description' => '',
      'value' => 5, 'stateIds' => ['venom']]]]))->toThrow(InvalidArgumentException::class, 'Unknown key');
});

it('uses a cure from the field only on a bearer, consuming one unit and nothing else', function () {
  $bearer = conditionsMember('Bearer', 40);
  $healthy = conditionsMember('Healthy', 40);
  $bearer->addState(walkingVenom());
  $item = cureItem();

  expect($healthy->getItemUseRefusal($item))->toContain('no effect')
    ->and($healthy->applyItem($item, 2))->toBe(0)
    ->and($item->quantity)->toBe(3)
    ->and($bearer->getItemUseRefusal($item))->toBeNull()
    ->and($bearer->applyItem($item, 3))->toBe(1)
    ->and($bearer->hasState('venom'))->toBeFalse()
    ->and($item->quantity)->toBe(2)
    ->and([$bearer->stats->currentHp, $bearer->stats->currentMp])->toBe([40, 10]);
});

it('refuses field items for the wrong life status, occasion or a full target', function () {
  $fallen = conditionsMember('Fallen', 0);
  $fallen->addState(walkingVenom());
  $full = conditionsMember('Full');
  $potion = new Item('Potion', '', '', 10, 5, effects: [new HPRecoveryEffect('Heal', '', 30, 1.0, ValueBasis::ACTUAL)]);
  $revive = new Item('Revive', '', '', 10, 5, effects: [new ResurrectionEffect('Revive', '', 10, 1.0, ValueBasis::PERCENTAGE)]);

  expect($fallen->getItemUseRefusal(cureItem()))->toContain('no effect')
    ->and($fallen->getItemUseRefusal(cureItem(scope: new ItemScope(ItemScopeSide::ALLY, status: ItemScopeStatus::ANY))))->toBeNull()
    ->and($full->getItemUseRefusal(cureItem(occasion: Occasion::BATTLE_SCREEN)))->toContain('cannot be used here')
    ->and($full->getItemUseRefusal($potion))->toContain('no effect')
    ->and($full->getItemUseRefusal($revive))->toContain('no effect')
    ->and($fallen->getItemUseRefusal($revive))->toBeNull()
    ->and($fallen->applyItem($revive, 5))->toBe(1)
    ->and($fallen->isKnockedOut)->toBeFalse();
});

it('stops using a stack once another unit would change nothing', function () {
  $member = conditionsMember(hp: 30);
  $potion = new Item('Potion', '', '', 10, 5, effects: [new HPRecoveryEffect('Heal', '', 30, 1.0, ValueBasis::ACTUAL)]);

  expect($member->applyItem($potion, 5))->toBe(3)
    ->and($member->stats->currentHp)->toBe(100)
    ->and($potion->quantity)->toBe(2);
});

it('lets every member walk with their conditions and reports only the floor and knockouts', function () {
  $party = new Party();
  $front = conditionsMember('Front', 21);
  $reserve = conditionsMember('Reserve', 50);
  $party->addMember($front);
  for ($index = 1; $index < Party::BATTLE_SIZE; $index++) { $party->addMember(conditionsMember('Healthy ' . $index)); }
  $party->addMember($reserve);
  $front->addState(walkingVenom(['fieldTickSteps' => 1]));
  $reserve->addState(walkingVenom(['fieldTickSteps' => 1]));

  $first = FieldConditions::advanceParty($party);
  $second = FieldConditions::advanceParty($party);

  expect($first['notices'])->toBe([])
    ->and($first['defeated'])->toBeFalse()
    ->and(array_map(static fn(array $notice): string => $notice['member']->name, $second['notices']))->toBe(['Front'])
    ->and($second['notices'][0]['knockedOut'])->toBeFalse()
    ->and([$front->stats->currentHp, $reserve->stats->currentHp])->toBe([1, 30])
    ->and(FieldConditions::advanceParty($party)['notices'])->toBe([]);
});

it('ends the game only when field conditions knock out every battle member', function () {
  $party = new Party();
  $members = [];
  for ($index = 0; $index < Party::BATTLE_SIZE; $index++) {
    $party->addMember($members[] = conditionsMember('Member ' . $index, 5));
  }
  $party->addMember(conditionsMember('Reserve'));
  $members[0]->addState(walkingVenom(['fieldTickSteps' => 1, 'fieldTickKnocksOut' => true]));

  $outcome = FieldConditions::advanceParty($party);
  expect($outcome['notices'][0]['knockedOut'])->toBeTrue()
    ->and($outcome['defeated'])->toBeFalse();

  foreach (array_slice($members, 1) as $member) {
    $member->addState(walkingVenom(['fieldTickSteps' => 1, 'fieldTickKnocksOut' => true]));
  }
  expect(FieldConditions::advanceParty($party)['defeated'])->toBeTrue();
});
