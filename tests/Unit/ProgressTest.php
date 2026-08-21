<?php

use Ichiloto\Engine\Progress\Achievement;
use Ichiloto\Engine\Progress\Bestiary;

/* Achievements */

it('hydrates achievements from data entries', function () {
  $achievement = Achievement::fromArray([
    'id' => 'first-blood',
    'name' => 'First Blood',
    'description' => 'Win a battle.',
    'icon' => '⚔️',
    'points' => 10,
    'secret' => true,
    'conditions' => [['type' => 'event', 'name' => 'won_a_battle'], 'not-an-array'],
  ]);

  expect($achievement->id)->toBe('first-blood')
    ->and($achievement->points)->toBe(10)
    ->and($achievement->isSecret)->toBeTrue()
    ->and($achievement->conditions)->toHaveCount(1);
});

it('requires an achievement id', function () {
  expect(fn() => Achievement::fromArray(['name' => 'Nameless']))->toThrow(InvalidArgumentException::class);
});

it('defaults achievement fields', function () {
  $achievement = Achievement::fromArray(['id' => 'plain']);

  expect($achievement->name)->toBe('plain')
    ->and($achievement->icon)->toBe('🏆')
    ->and($achievement->isSecret)->toBeFalse()
    ->and($achievement->points)->toBe(0);
});

/* Bestiary */

it('records sightings and defeats', function () {
  $bestiary = new Bestiary();

  expect($bestiary->hasSeen('Sewer Rat'))->toBeFalse()
    ->and($bestiary->recordSeen('Sewer Rat'))->toBeTrue()   // first sighting
    ->and($bestiary->recordSeen('Sewer Rat'))->toBeFalse()  // subsequent
    ->and($bestiary->timesSeen('Sewer Rat'))->toBe(2)
    ->and($bestiary->timesDefeated('Sewer Rat'))->toBe(0);

  // Defeating an already-seen enemy does not double-count the sighting;
  // battle start is what records those.
  expect($bestiary->recordDefeated('Sewer Rat'))->toBeTrue()
    ->and($bestiary->timesDefeated('Sewer Rat'))->toBe(1)
    ->and($bestiary->timesSeen('Sewer Rat'))->toBe(2);
});

it('counts a defeat as a sighting for unseen enemies', function () {
  $bestiary = new Bestiary();
  $bestiary->recordDefeated('Loch Ness');

  expect($bestiary->hasSeen('Loch Ness'))->toBeTrue()
    ->and($bestiary->timesSeen('Loch Ness'))->toBe(1)
    ->and($bestiary->discoveredCount())->toBe(1);
});

it('ignores blank enemy names', function () {
  $bestiary = new Bestiary();

  expect($bestiary->recordSeen('  '))->toBeFalse()
    ->and($bestiary->recordDefeated(''))->toBeFalse()
    ->and($bestiary->discoveredCount())->toBe(0);
});

it('round-trips the bestiary through toArray and fromArray', function () {
  $bestiary = new Bestiary();
  $bestiary->recordSeen('Regular Bat');
  $bestiary->recordDefeated('Sewer Rat');
  $bestiary->recordDefeated('Sewer Rat');

  $restored = Bestiary::fromArray($bestiary->toArray());

  expect($restored->timesSeen('Regular Bat'))->toBe(1)
    ->and($restored->timesDefeated('Sewer Rat'))->toBe(2)
    ->and($restored->discoveredCount())->toBe(2);
});

it('ignores malformed persisted bestiary entries', function () {
  $restored = Bestiary::fromArray([
    'seen' => ['' => 3, 'Great Wolf' => 2],
    'defeated' => ['Great Wolf' => 1],
  ]);

  expect($restored->discoveredCount())->toBe(1)
    ->and($restored->timesSeen('Great Wolf'))->toBe(2);
});
