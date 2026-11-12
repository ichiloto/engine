<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Cutscenes\Summons\{SummonAvailability, SummonCutsceneDefinition, SummonWielderPolicy};
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

it('passes an empty condition list', function () {
  expect(WorldConditionEvaluator::allHold([], new GameState()))->toBeTrue();
});

it('evaluates explicit quest state without requiring a running scene', function () {
  $state = new GameState();
  $quests = new QuestLog();
  $active = [['type' => 'quest', 'name' => 'synthetic-quest', 'status' => 'active']];
  $completed = [['type' => 'quest', 'name' => 'synthetic-quest', 'status' => 'completed']];
  expect(WorldConditionEvaluator::allHold($active, $state, questLog: $quests))->toBeFalse();
  $quests->accept('synthetic-quest', 1);
  expect(WorldConditionEvaluator::allHold($active, $state, questLog: $quests))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold($completed, $state, questLog: $quests))->toBeFalse();
  $quests->markCompleted('synthetic-quest');
  expect(WorldConditionEvaluator::allHold($active, $state, questLog: $quests))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold($completed, $state, questLog: $quests))->toBeTrue();
});

it('publishes the complete stable condition vocabulary', function () {
  expect(WorldConditionType::values())->toBe([
    'quest',
    'switch',
    'event',
    'any',
    'map_event',
    'variable',
    'play_statistic',
    'item',
    'key_item',
    'world_ability',
    'assigned_summons',
    'present_actor',
    'present_party_size',
  ]);
});

it('reads persisted map-event completion without confusing maps or story flags and supports negation', function () {
  $state = new GameState();
  $condition = ['type' => 'map_event', 'name' => 'town/home', 'marker' => 'C'];
  WorldConditionEvaluator::validateAll([$condition]);
  $state->recordStoryEvent('town/home');
  $state->markEventComplete('town/inn', 'C');
  expect(WorldConditionEvaluator::allHold([$condition], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([array_replace($condition, ['negate' => true])], $state))->toBeTrue();
  $state->markEventComplete('town/home', 'C');
  $state = GameState::fromArray($state->toArray());
  expect(WorldConditionEvaluator::allHold([$condition], $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([array_replace($condition, ['negate' => true])], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([['type' => 'map_event', 'name' => 'town/home', 'negate' => true]], $state))->toBeFalse();
});

it('evaluates switch, event, and variable conditions', function () {
  $state = new GameState();

  expect(WorldConditionEvaluator::allHold([['type' => 'switch', 'name' => 'gate']], $state))->toBeFalse();
  $state->setSwitch('gate');
  expect(WorldConditionEvaluator::allHold([['type' => 'switch', 'name' => 'gate']], $state))->toBeTrue();

  expect(WorldConditionEvaluator::allHold([['type' => 'event', 'name' => 'met_king']], $state))->toBeFalse();
  $state->recordStoryEvent('met_king');
  expect(WorldConditionEvaluator::allHold([['type' => 'event', 'name' => 'met_king']], $state))->toBeTrue();

  $state->setVariable('donations', 120);
  expect(WorldConditionEvaluator::allHold([['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100]], $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([['type' => 'variable', 'name' => 'donations', 'op' => '<', 'value' => 100]], $state))->toBeFalse();
});

it('requires every condition in the list to hold', function () {
  $state = new GameState();
  $state->setSwitch('a');

  expect(WorldConditionEvaluator::allHold([
    ['type' => 'switch', 'name' => 'a'],
    ['type' => 'switch', 'name' => 'b'],
  ], $state))->toBeFalse();

  $state->setSwitch('b');

  expect(WorldConditionEvaluator::allHold([
    ['type' => 'switch', 'name' => 'a'],
    ['type' => 'switch', 'name' => 'b'],
  ], $state))->toBeTrue();
});

it('inverts negated conditions', function () {
  $state = new GameState();
  $state->setSwitch('bridge_out');

  expect(WorldConditionEvaluator::allHold([['type' => 'switch', 'name' => 'bridge_out', 'negate' => true]], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([['type' => 'switch', 'name' => 'clear_road', 'negate' => true]], $state))->toBeTrue();
});

it('separates plain item conditions from key-item conditions', function () {
  $state = new GameState();
  $party = new Party();
  $party->addItems(new Item('Rusty Key', 'Opens something.', 'k', 0));

  // A plain item satisfies `item` but never `key_item`.
  expect(WorldConditionEvaluator::allHold([['type' => 'item', 'name' => 'Rusty Key']], $state, $party))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([['type' => 'key_item', 'name' => 'Rusty Key']], $state, $party))->toBeFalse();

  $party->addItems(new Item('Sky Sigil', 'A quest token.', 's', 0, isKeyItem: true));

  expect(WorldConditionEvaluator::allHold([['type' => 'key_item', 'name' => 'Sky Sigil']], $state, $party))->toBeTrue();
});

it('honours item quantity thresholds', function () {
  $state = new GameState();
  $party = new Party();
  $party->addItems(new Item('Potion', 'Heals.', 'p', 0));

  expect(WorldConditionEvaluator::allHold([['type' => 'item', 'name' => 'Potion', 'quantity' => 2]], $state, $party))->toBeFalse();

  $party->addItems(new Item('Potion', 'Heals.', 'p', 0));

  expect(WorldConditionEvaluator::allHold([['type' => 'item', 'name' => 'Potion', 'quantity' => 2]], $state, $party))->toBeTrue();
});

it('counts distinct assigned contracts and observes successful mutations from Party', function () {
  $state = new GameState();
  $party = new Party();
  $first = new Character('First', 0, new Stats());
  $second = new Character('Second', 0, new Stats());
  $party->addMember($first);
  $party->addMember($second);
  $firstContract = new SummonCutsceneDefinition('first', 'First', wielders: new SummonWielderPolicy());
  $secondContract = new SummonCutsceneDefinition('second', 'Second', wielders: new SummonWielderPolicy());
  $condition = ['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 2];
  WorldConditionEvaluator::validateAll([$condition]);
  $observed = [];
  $party->setSummonAssignmentListener(static function (Party $owner) use (&$observed, $condition, $state): void {
    $observed[] = WorldConditionEvaluator::allHold([$condition], $state, $owner);
  });

  $party->assignSummon($firstContract, $first);
  $party->assignSummon($firstContract, $first);
  $party->assignSummon($firstContract, $second);
  $party->assignSummon($secondContract, $first);
  $party->unassignSummon('second', $first);
  expect($observed)->toBe([false, false, true, false])
    ->and($party->getAssignedSummonIds())->toBe(['first'])
    ->and(unserialize(serialize($party)))->toBeInstanceOf(Party::class);

  $bad = [...$condition, 'quantity' => '2', 'negate' => true];
  expect(fn() => WorldConditionEvaluator::validateAll([$bad]))->toThrow(InvalidArgumentException::class)
    ->and(WorldConditionEvaluator::allHold([$bad], $state, $party))->toBeFalse();
  $party->setSummonAssignmentListener(null);
});

it('checks current participants by stable actor identity and exact present count', function () {
  $state = new GameState();
  $party = new Party();
  foreach (['alpha', 'beta', 'gamma', 'delta'] as $id) {
    $party->addMember(new Character('Same display name', 0, new Stats(), actorId: $id));
  }
  $actor = ['type' => 'present_actor', 'name' => 'delta'];
  $four = ['type' => 'present_party_size', 'name' => 'party', 'quantity' => 4];
  WorldConditionEvaluator::validateAll([$actor, $four]);
  expect(WorldConditionEvaluator::allHold([$actor, $four], $state, $party))->toBeTrue();

  $party->selectParticipants(['alpha']);
  expect(WorldConditionEvaluator::allHold([$actor], $state, $party))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([[...$actor, 'negate' => true]], $state, $party))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([$four], $state, $party))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([[...$four, 'quantity' => 1]], $state, $party))->toBeTrue();

  $party->regroupParticipants();
  expect(WorldConditionEvaluator::allHold([$actor, $four], $state, $party))->toBeTrue();
  $malformed = [...$four, 'quantity' => '4', 'negate' => true];
  expect(fn() => WorldConditionEvaluator::validateAll([$malformed]))->toThrow(InvalidArgumentException::class)
    ->and(WorldConditionEvaluator::allHold([$malformed], $state, $party))->toBeFalse();
});

it('keeps item and key-item conditions stable across display-name changes', function () {
  $previous = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;
  $store = (new ReflectionClass(ItemStore::class))->newInstanceWithoutConstructor();
  $tonic = new Item(
    'Current Tonic',
    'A renamed consumable.',
    '!',
    10,
    id: 'item.tonic',
    aliases: ['Old Tonic'],
  );
  $sigil = new Item(
    'Current Sigil',
    'A renamed key item.',
    'i',
    0,
    id: 'item.sigil',
    isKeyItem: true,
    aliases: ['Old Sigil'],
  );
  $store->set($tonic->id, $tonic);
  $store->set($sigil->id, $sigil);
  ConfigStore::put(ItemStore::class, $store);

  try {
    $state = new GameState();
    $party = new Party();
    $party->addItems(clone $tonic, clone $sigil);

    expect(WorldConditionEvaluator::allHold([['type' => 'item', 'name' => 'item.tonic']], $state, $party))->toBeTrue()
      ->and(WorldConditionEvaluator::allHold([['type' => 'item', 'name' => 'Old Tonic']], $state, $party))->toBeTrue()
      ->and(WorldConditionEvaluator::allHold([['type' => 'key_item', 'name' => 'item.sigil']], $state, $party))->toBeTrue()
      ->and(WorldConditionEvaluator::allHold([['type' => 'key_item', 'name' => 'Old Sigil']], $state, $party))->toBeTrue();
  } finally {
    ConfigStore::remove(ItemStore::class);

    if ($previous instanceof ItemStore) {
      ConfigStore::put(ItemStore::class, $previous);
    }
  }
});

it('fails closed for unknown condition types even when they are negated', function () {
  $state = new GameState();

  expect(WorldConditionEvaluator::allHold([['type' => 'phase_of_moon', 'name' => 'full']], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([['type' => 'phase_of_moon', 'name' => 'full', 'negate' => true]], $state))->toBeFalse();
});

it('refuses malformed typed condition values at the shared admission boundary', function (array $condition) {
  expect(fn() => WorldConditionEvaluator::validateAll([$condition], 'synthetic conditions'))->toThrow(InvalidArgumentException::class);
})->with([
  'switch coercion' => [['type' => 'switch', 'name' => 'gate', 'value' => 'false']],
  'null negate' => [['type' => 'event', 'name' => 'activated', 'negate' => null]],
  'null quantity' => [['type' => 'item', 'name' => 'token', 'quantity' => null]],
  'null operator' => [['type' => 'variable', 'name' => 'phase', 'op' => null]],
  'array operator' => [['type' => 'variable', 'name' => 'phase', 'op' => []]],
  'array value' => [['type' => 'variable', 'name' => 'phase', 'value' => []]],
  'unknown quest status' => [['type' => 'quest', 'name' => 'quest', 'status' => 'unknown']],
]);

it('admits explicit false switches and typed shared selectors without changing their meaning', function () {
  $conditions = [['type' => 'switch', 'name' => 'gate', 'value' => false],
    ['type' => 'variable', 'name' => 'phase', 'op' => '==', 'value' => 'ready'],
    ['type' => 'event', 'name' => 'activated', 'negate' => true]];
  WorldConditionEvaluator::validateAll($conditions);
  $state = new GameState();
  $state->setVariable('phase', 'ready');
  $before = $state->toArray();
  expect(WorldConditionEvaluator::allHold($conditions, $state))->toBeTrue()->and($state->toArray())->toBe($before);
});

it('keeps unvalidated runtime coercion separate from narrowed condition admission', function () {
  $conditions = [['type' => 'switch', 'name' => 'gate', 'value' => 'false']];
  $state = new GameState();
  $state->setSwitch('gate');
  expect(fn() => WorldConditionEvaluator::validateAll($conditions))->toThrow(InvalidArgumentException::class)
    ->and(WorldConditionEvaluator::allHold($conditions, $state))->toBeTrue();
});

it('evaluates nested any groups with negation across saved world phases', function () {
  $visited = ['type' => 'event', 'name' => 'visited'];
  $returned = ['type' => 'event', 'name' => 'returned'];
  $street = ['type' => 'any', 'conditions' => [
    [...$visited, 'negate' => true],
    ['type' => 'any', 'conditions' => [$returned]],
  ]];
  $house = [$visited, [...$returned, 'negate' => true]];
  $state = new GameState();
  WorldConditionEvaluator::validateAll([$street]);
  expect(WorldConditionEvaluator::allHold([$street], $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold($house, $state))->toBeFalse();
  $state->recordStoryEvent('visited');
  $state = GameState::fromArray($state->toArray());
  expect(WorldConditionEvaluator::allHold([$street], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold($house, $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([[...$street, 'negate' => true]], $state))->toBeTrue();
  $state->recordStoryEvent('returned');
  expect(WorldConditionEvaluator::allHold([$street, $visited], $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold($house, $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([[...$street, 'negate' => true]], $state))->toBeFalse();
});

it('validates the complete any tree before evaluating or applying its negation', function () {
  $valid = ['type' => 'event', 'name' => 'visited'];
  $state = new GameState();
  $state->recordStoryEvent('visited');
  $invalid = [
    ['type' => 'any', 'conditions' => []],
    ['type' => 'any', 'conditions' => [$valid], 'name' => 'unexpected'],
    ['type' => 'any', 'conditions' => [$valid, ['type' => 'event']]],
    ['type' => 'any', 'conditions' => [$valid, ['type' => 'unknown', 'name' => 'bad']]],
    ['type' => 'any', 'conditions' => array_fill(0, 33, $valid)],
    ['type' => 'any', 'conditions' => array_fill(0, 8, ['type' => 'any', 'conditions' => array_fill(0, 32, $valid)])],
  ];
  $deep = $valid;
  for ($level = 0; $level < 5; $level++) {
    $deep = ['type' => 'any', 'conditions' => [$deep]];
  }
  $invalid[] = $deep;
  foreach ($invalid as $group) {
    expect(fn() => WorldConditionEvaluator::validateAll([$group]))->toThrow(InvalidArgumentException::class)
      ->and(WorldConditionEvaluator::allHold([$group], $state))->toBeFalse()
      ->and(WorldConditionEvaluator::allHold([[...$group, 'negate' => true]], $state))->toBeFalse();
  }
});

it('shares any group validation and evaluation with summon availability', function () {
  $state = new GameState();
  $group = ['type' => 'any', 'conditions' => [
    ['type' => 'event', 'name' => 'first'],
    ['type' => 'event', 'name' => 'second'],
  ]];
  $availability = SummonAvailability::fromAuthored(['conditions' => [$group]]);
  expect($availability->isValid())->toBeTrue()
    ->and($availability->isSatisfied($state))->toBeFalse();
  $state->recordStoryEvent('second');
  expect($availability->isSatisfied($state))->toBeTrue();
});
