<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;

it('passes an empty condition list', function () {
  expect(WorldConditionEvaluator::allHold([], new GameState()))->toBeTrue();
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

it('passes unknown condition types so typos never hide content', function () {
  expect(WorldConditionEvaluator::allHold([['type' => 'phase_of_moon', 'name' => 'full']], new GameState()))->toBeTrue();
});
