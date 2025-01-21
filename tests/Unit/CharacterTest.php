<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;

it('can create a character', function () {
  $characterName = 'John Doe';
  $currentExp = 500;
  $stats = new Stats();
  $characterBio = 'A simple character.';

  $character = new Character($characterName, $currentExp, $stats, bio: $characterBio);

  expect($character)
    ->toBeInstanceOf(Character::class)
    ->toHaveProperties(['name', 'currentExp', 'stats', 'images', 'nickname', 'maxLevel', 'bio', 'note', 'equipment', 'role'])
    ->and($character->name)
    ->toBe($characterName)
    ->and($character->currentExp)
    ->toBe($currentExp)
    ->and($character->stats)
    ->toBe($stats)
    ->and($character->bio)
    ->toBe($characterBio);
});