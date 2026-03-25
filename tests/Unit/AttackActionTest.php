<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;

it('reduces the target hp when an attack executes', function () {
  $actor = new Character('Hero', 0, new Stats(currentHp: 120, currentMp: 10, attack: 20, defence: 5, magicAttack: 5, magicDefence: 5, speed: 5));
  $target = new Character('Slime', 0, new Stats(currentHp: 60, currentMp: 0, attack: 5, defence: 3, magicAttack: 0, magicDefence: 0, speed: 1));

  $action = new AttackAction('Attack');
  $beforeHp = $target->stats->currentHp;

  $action->execute($actor, [$target]);

  expect($target->stats->currentHp)->toBeLessThan($beforeHp);
});
