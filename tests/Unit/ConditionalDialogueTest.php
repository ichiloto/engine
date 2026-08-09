<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;

/**
 * Mom's demo dialogue: most advanced state first, unconditional fallback last.
 */
function momDialogue(): array
{
  return [
    [
      'conditions' => [['type' => 'event', 'name' => 'errand_done']],
      'lines' => [['name' => 'Mom', 'text' => 'You found one!']],
      'sets' => [['type' => 'event', 'name' => 'thanked_you']],
    ],
    [
      'conditions' => [['type' => 'item', 'name' => 'S-Mana', 'quantity' => 1]],
      'lines' => [['name' => 'Mom', 'text' => 'Is that an S-Mana wafer?']],
    ],
    [
      'lines' => [['name' => 'Mom', 'text' => 'Good morning, dear.']],
    ],
  ];
}

it('recognises a plain page list as unconditional', function () {
  $plain = [
    ['name' => 'Mom', 'text' => 'Good morning.'],
    ['name' => 'Mom', 'text' => 'Breakfast is ready.'],
  ];

  expect(ConditionalDialogue::isVariantList($plain))->toBeFalse();

  $selected = ConditionalDialogue::select($plain, new GameState());

  // Existing content keeps working untouched: every page is spoken.
  expect($selected['lines'])->toHaveCount(2)
    ->and($selected['sets'])->toBe([]);
});

it('speaks the first variant whose conditions hold', function () {
  $state = new GameState();
  $party = new Party();

  // Nothing done yet: the unconditional fallback.
  expect(ConditionalDialogue::select(momDialogue(), $state, $party)['lines'][0]['text'])
    ->toBe('Good morning, dear.');

  // Holding the item moves her to the middle variant.
  $party->addItems(new Item('S-Mana', 'A wafer.', 'm', 0));
  expect(ConditionalDialogue::select(momDialogue(), $state, $party)['lines'][0]['text'])
    ->toBe('Is that an S-Mana wafer?');

  // The errand being finished wins, because it is listed first.
  $state->recordStoryEvent('errand_done');
  expect(ConditionalDialogue::select(momDialogue(), $state, $party)['lines'][0]['text'])
    ->toBe('You found one!');
});

it('carries the chosen variant\'s writes', function () {
  $state = new GameState();
  $state->recordStoryEvent('errand_done');

  $selected = ConditionalDialogue::select(momDialogue(), $state, new Party());

  expect($selected['sets'])->toBe([['type' => 'event', 'name' => 'thanked_you']]);
});

it('says nothing when every variant is gated out', function () {
  $gated = [
    ['conditions' => [['type' => 'event', 'name' => 'never_happens']], 'lines' => [['text' => 'Hi']]],
  ];

  $selected = ConditionalDialogue::select($gated, new GameState());

  expect($selected['lines'])->toBe([])
    ->and($selected['script'])->toBe([]);
});

it('supports a script variant as well as lines', function () {
  $withScript = [
    [
      'conditions' => [['type' => 'switch', 'name' => 'ready']],
      'script' => [['type' => 'give_gold', 'amount' => 50]],
    ],
    ['lines' => [['text' => 'Not yet.']]],
  ];

  $state = new GameState();
  expect(ConditionalDialogue::select($withScript, $state)['lines'][0]['text'])->toBe('Not yet.');

  $state->setSwitch('ready');
  $selected = ConditionalDialogue::select($withScript, $state);

  expect($selected['script'])->toHaveCount(1)
    ->and($selected['lines'])->toBe([]);
});
