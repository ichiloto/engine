<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;

it('reduces the target hp when an attack lands', function () {
  // Basic attacks roll to hit at 95% + grace - evasion (clamped 5-100), so
  // an attacker with any grace against a target with no evasion always
  // connects. Without that the test misses roughly one run in twenty.
  $actor = new Character('Hero', 0, new Stats(currentHp: 120, currentMp: 10, attack: 20, defence: 5, magicAttack: 5, magicDefence: 5, speed: 5, grace: 10, evasion: 0));
  $target = new Character('Slime', 0, new Stats(currentHp: 60, currentMp: 0, attack: 5, defence: 3, magicAttack: 0, magicDefence: 0, speed: 1, grace: 0, evasion: 0));

  $action = new AttackAction('Attack');
  $beforeHp = $target->stats->currentHp;

  $action->execute($actor, [$target]);

  expect($target->stats->currentHp)->toBeLessThan($beforeHp);
});

it('can miss when the target evades well', function () {
  // The mirror case: overwhelming evasion floors the hit chance at 5%, so
  // across many swings some must miss. This pins the roll's existence, so
  // removing it would fail loudly instead of silently making combat
  // deterministic.
  $misses = 0;

  for ($attempt = 0; $attempt < 200; $attempt++) {
    $actor = new Character('Hero', 0, new Stats(currentHp: 120, currentMp: 10, attack: 20, grace: 0, evasion: 0));
    $target = new Character('Wisp', 0, new Stats(currentHp: 500, currentMp: 0, defence: 0, grace: 0, evasion: 200));

    $beforeHp = $target->stats->currentHp;
    new AttackAction('Attack')->execute($actor, [$target]);

    if ($target->stats->currentHp === $beforeHp) {
      $misses++;
    }
  }

  expect($misses)->toBeGreaterThan(0);
});
