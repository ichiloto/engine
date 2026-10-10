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

  $root = createTestDirectory('enemy-creation-');
  mkdir($root . '/assets/Graphics/Enemies', 0700, true);
  $previousDirectory = getcwd();

  try {
    chdir($root);

    foreach ([['E'], ['EEE', ' E ', 'EEE']] as $imageRows) {
      file_put_contents($root . '/assets/Graphics/Enemies/synthetic.txt', implode("\n", $imageRows) . "\n");
      $enemy = new Enemy($enemyName, $enemyLevel, $enemyStats, 'synthetic', $rewards, []);

      expect($enemy)
        ->toBeInstanceOf(Enemy::class)
        ->toHaveProperties(['name','level','stats','rewards','image', 'imagePath'])
        ->and($enemy->name)->toBe($enemyName)
        ->and($enemy->level)->toBe($enemyLevel)
        ->and($enemy->stats)->toBe($enemyStats)
        ->and($enemy->rewards)->toBe($rewards)
        ->and($enemy->imagePath)->toBe('synthetic')
        ->and($enemy->image)->toBe($imageRows);
    }
  } finally {
    chdir($previousDirectory);
  }

  expect(getcwd())->toBe($previousDirectory);
});
