<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;

function makeTestTrigger(
  array $conditions = [],
  array $sets = [],
  ?string $mapId = null,
  ?string $marker = null,
  array $data = ['reusable' => false]
): EventTrigger {
  return new class(new Rect(0, 0, 1, 1), $data, $conditions, $sets, $mapId, $marker) extends EventTrigger {
    public function configure(): void
    {
      $this->isReusable = (bool) ($this->data->reusable ?? false);
    }
  };
}

it('stores and reads switches with a false default', function () {
  $state = new GameState();

  expect($state->getSwitch('drawbridge'))->toBeFalse();

  $state->setSwitch('drawbridge');
  expect($state->getSwitch('drawbridge'))->toBeTrue();

  $state->setSwitch('drawbridge', false);
  expect($state->getSwitch('drawbridge'))->toBeFalse();
});

it('stores and reads variables with defaults and addition', function () {
  $state = new GameState();

  expect($state->getVariable('donations'))->toBe(0)
    ->and($state->getVariable('title', 'none'))->toBe('none');

  $state->setVariable('donations', 40);
  $state->addToVariable('donations', 10);
  expect($state->getVariable('donations'))->toBe(50);

  $state->setVariable('title', 'Champion');
  expect($state->getVariable('title'))->toBe('Champion');
});

it('records story events idempotently', function () {
  $state = new GameState();

  expect($state->hasStoryEvent('sunsteel_trial'))->toBeFalse();

  $state->recordStoryEvent('sunsteel_trial');
  $state->recordStoryEvent('sunsteel_trial');

  expect($state->hasStoryEvent('sunsteel_trial'))->toBeTrue()
    ->and($state->storyEvents)->toBe(['sunsteel_trial']);
});

it('tracks one-shot event completion per map and marker', function () {
  $state = new GameState();

  expect($state->isEventComplete('happyville/home', 'B'))->toBeFalse();

  $state->markEventComplete('happyville/home', 'B');

  expect($state->isEventComplete('happyville/home', 'B'))->toBeTrue()
    ->and($state->isEventComplete('happyville/home', 'C'))->toBeFalse()
    ->and($state->isEventComplete('castle/throne-room', 'B'))->toBeFalse();
});

it('round-trips through toArray and fromArray', function () {
  $state = new GameState();
  $state->setSwitch('gate_open');
  $state->setVariable('chests_opened', 3);
  $state->recordStoryEvent('met_the_king');
  $state->markEventComplete('happyville/home', 'B');

  $restored = GameState::fromArray($state->toArray());

  expect($restored->getSwitch('gate_open'))->toBeTrue()
    ->and($restored->getVariable('chests_opened'))->toBe(3)
    ->and($restored->hasStoryEvent('met_the_king'))->toBeTrue()
    ->and($restored->isEventComplete('happyville/home', 'B'))->toBeTrue();
});

/* Trigger conditions */

it('treats triggers without conditions or binding as available', function () {
  expect(makeTestTrigger()->isAvailable())->toBeTrue();

  $conditional = makeTestTrigger([['type' => 'switch', 'name' => 'gate_open']]);
  expect($conditional->isAvailable())->toBeTrue(); // unbound → available
});

it('gates triggers on switches, events, and variables', function () {
  $state = new GameState();

  $bySwitch = makeTestTrigger([['type' => 'switch', 'name' => 'gate_open']]);
  $bySwitch->bind($state);
  expect($bySwitch->isAvailable())->toBeFalse();
  $state->setSwitch('gate_open');
  expect($bySwitch->isAvailable())->toBeTrue();

  $byEvent = makeTestTrigger([['type' => 'event', 'name' => 'met_the_king']]);
  $byEvent->bind($state);
  expect($byEvent->isAvailable())->toBeFalse();
  $state->recordStoryEvent('met_the_king');
  expect($byEvent->isAvailable())->toBeTrue();

  $byVariable = makeTestTrigger([['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100]]);
  $byVariable->bind($state);
  expect($byVariable->isAvailable())->toBeFalse();
  $state->setVariable('donations', 150);
  expect($byVariable->isAvailable())->toBeTrue();
});

it('supports negated conditions', function () {
  $state = new GameState();
  $state->recordStoryEvent('bridge_destroyed');

  $trigger = makeTestTrigger([['type' => 'event', 'name' => 'bridge_destroyed', 'negate' => true]]);
  $trigger->bind($state);

  expect($trigger->isAvailable())->toBeFalse();
});

it('gates triggers on party items', function () {
  $state = new GameState();
  $party = new Party();
  $trigger = makeTestTrigger([['type' => 'item', 'name' => 'Rusty Key']]);
  $trigger->bind($state, $party);

  expect($trigger->isAvailable())->toBeFalse();

  $party->addItems(new Item('Rusty Key', 'Opens something, probably.', 'k', 0));

  expect($trigger->isAvailable())->toBeTrue();
});

/* Completion writes and persistence */

it('applies completion writes to the world state', function () {
  $state = new GameState();
  $trigger = makeTestTrigger(
    sets: [
      ['type' => 'switch', 'name' => 'chest_opened'],
      ['type' => 'event', 'name' => 'first_loot'],
      ['type' => 'variable', 'name' => 'chests_opened', 'op' => 'add', 'value' => 1],
    ],
  );
  $trigger->bind($state);
  $trigger->complete();

  expect($state->getSwitch('chest_opened'))->toBeTrue()
    ->and($state->hasStoryEvent('first_loot'))->toBeTrue()
    ->and($state->getVariable('chests_opened'))->toBe(1);
});

it('persists one-shot completion by map and marker', function () {
  $state = new GameState();
  $trigger = makeTestTrigger(mapId: 'happyville/home', marker: 'B', data: ['reusable' => false]);
  $trigger->bind($state);

  $trigger->complete();

  expect($trigger->isComplete)->toBeTrue()
    ->and($state->isEventComplete('happyville/home', 'B'))->toBeTrue();
});

it('does not persist completion for reusable triggers', function () {
  $state = new GameState();
  $trigger = makeTestTrigger(mapId: 'happyville/home', marker: 'D', data: ['reusable' => true]);
  $trigger->bind($state);

  $trigger->complete();

  expect($state->isEventComplete('happyville/home', 'D'))->toBeFalse();
});

it('restores completion without re-applying writes', function () {
  $state = new GameState();
  $trigger = makeTestTrigger(
    sets: [['type' => 'variable', 'name' => 'chests_opened', 'op' => 'add', 'value' => 1]],
    mapId: 'happyville/home',
    marker: 'B',
    data: ['reusable' => false],
  );
  $trigger->bind($state);

  $trigger->restoreCompleted();

  expect($trigger->isComplete)->toBeTrue()
    ->and($state->getVariable('chests_opened'))->toBe(0);
});

it('builds triggers with conditions, sets, and identity through the factory', function () {
  $trigger = EventTriggerFactory::create([
    'class' => Ichiloto\Engine\Events\Triggers\DialogueEventTrigger::class,
    'area' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1],
    'marker' => 'C',
    'conditions' => [['type' => 'switch', 'name' => 'gate_open']],
    'sets' => [['type' => 'event', 'name' => 'talked_to_mom']],
    'data' => ['dialogue' => [['name' => 'Mom', 'text' => 'Hi.']]],
  ], 'happyville/home');

  expect($trigger->mapId)->toBe('happyville/home')
    ->and($trigger->marker)->toBe('C')
    ->and($trigger->conditions)->toBe([['type' => 'switch', 'name' => 'gate_open']])
    ->and($trigger->sets)->toBe([['type' => 'event', 'name' => 'talked_to_mom']]);
});
