<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

it('passes an empty condition list', function () {
  expect(WorldConditionEvaluator::allHold([], new GameState()))->toBeTrue();
});

it('publishes the complete stable condition vocabulary', function () {
  expect(WorldConditionType::values())->toBe([
    'quest',
    'switch',
    'event',
    'variable',
    'item',
    'key_item',
  ]);
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
