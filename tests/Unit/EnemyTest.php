<?php

use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Stats;

it('can create an enemy', function() {
  $enemyName = 'Regular Bat';
  $enemyLevel = 14;
  $enemyHp = 200;
  $enemyMp = 0;
  $enemyAttack = 27;
  $enemyDefence = 17;
  $enemyMagicAttack = 27;
  $enemyMagicDefence = 17;
  $enemySpeed = 30;
  $enemyGrace = 30;
  $enemyEvasion = 30;

  $enemyStats = new Stats(
    currentHp: $enemyHp,
    currentMp: $enemyMp,
    attack: $enemyAttack,
    defence: $enemyDefence,
    magicAttack: $enemyMagicAttack,
    magicDefence: $enemyMagicDefence,
    speed: $enemySpeed,
    grace: $enemyGrace,
    evasion: $enemyEvasion
  );
  $rewards = new BattleRewards(380, 100, []);

  $enemy = new Enemy($enemyName, $enemyLevel, $enemyStats, '', $rewards, []);

  expect($enemy)
    ->toBeInstanceOf(Enemy::class)
    ->toHaveProperties(['name','level','stats','rewards','image', 'imagePath']);
})->skip();