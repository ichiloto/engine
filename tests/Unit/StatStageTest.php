<?php

use Ichiloto\Engine\Battle\BattlerBattleView;
use Ichiloto\Engine\Entities\Effects\SkillEffects\ModifyStatStageSkillEffect;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\States\HasStatStages;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\StatKey;

function makeStagedBattler(int $attack = 40): object
{
  return new class($attack) {
    use HasStatStages;

    public Stats $stats;
    public string $name = 'Stagey';
    public bool $isKnockedOut = false;

    public function __construct(int $attack)
    {
      $this->stats = new Stats(currentHp: 100, totalHp: 100, currentMp: 10, attack: $attack, defence: 20, speed: 30);
    }
  };
}

it('clamps stages to plus and minus four', function () {
  $battler = makeStagedBattler();

  expect($battler->addStatStage('attack', 3))->toBe(3)
    ->and($battler->addStatStage('attack', 5))->toBe(4)
    ->and($battler->addStatStage('attack', -20))->toBe(-4)
    ->and($battler->addStatStage('luckiness', 2))->toBe(0); // unknown stat is a no-op
});

it('applies twenty-five percent per stage', function () {
  $battler = makeStagedBattler();

  expect($battler->getStatStageMultiplier('attack'))->toBe(1.0);

  $battler->addStatStage('attack', 2);
  expect($battler->getStatStageMultiplier('attack'))->toBe(1.5);

  $battler->addStatStage('attack', -6);
  expect($battler->getStatStageMultiplier('attack'))->toBe(0.25);
});

it('feeds staged stats into the battle view', function () {
  $battler = makeStagedBattler(40);
  $battler->addStatStage('attack', 1);

  $view = new BattlerBattleView($battler);

  expect($view->stats->attack)->toBe(50)  // 40 * 1.25
    ->and($view->stats->defence)->toBe(20) // untouched stat passes through
    ->and($view->name)->toBe('Stagey')     // non-stat reads reach the battler
    ->and($battler->stats->attack)->toBe(40); // the real stats never mutate
});

it('applies player stages before the player cap and exposes final metadata', function () {
  $character = new Ichiloto\Engine\Entities\Character(
    'Capped',
    0,
    new Stats(currentHp: 100, attack: 900, defence: 20, speed: 30),
  );
  $character->addStatStage('attack', 1);

  $view = new BattlerBattleView($character);
  $resolution = $view->resolveStat(StatKey::ATTACK);

  expect($view->stats->attack)->toBe(999)
    ->and($resolution->uncappedValue)->toBe(1_125)
    ->and($resolution->effectiveValue)->toBe(999)
    ->and($resolution->temporary)->toBe(225)
    ->and($resolution->capLoss)->toBe(126)
    ->and($resolution->remainingHeadroom)->toBe(0)
    ->and($character->stats->attack)->toBe(900);
});

it('restores persistent values after a debuff is removed', function () {
  $character = new Ichiloto\Engine\Entities\Character(
    'Debuffed',
    0,
    new Stats(currentHp: 100, attack: 120, defence: 20, speed: 30),
  );
  $character->addStatStage('attack', -2);

  expect(new BattlerBattleView($character)->stats->attack)->toBe(60);

  $character->resetStatStages();

  expect(new BattlerBattleView($character)->stats->attack)->toBe(120)
    ->and($character->stats->currentHp)->toBe(100);
});

it('uses the explicit enemy cap after enemy stages', function () {
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(Enemy::class, 'stats')->setValue(
    $enemy,
    new Stats(currentHp: 100, attack: 9_000, defence: 20, speed: 30),
  );
  $enemy->addStatStage('attack', 1);

  $resolution = new BattlerBattleView($enemy)->resolveStat(StatKey::ATTACK);

  expect($resolution->uncappedValue)->toBe(11_250)
    ->and($resolution->effectiveValue)->toBe(9_999)
    ->and($resolution->capLoss)->toBe(1_251);
});

it('describes its stage shift', function () {
  $effect = new ModifyStatStageSkillEffect('defence', -1);

  expect($effect->stat)->toBe('defence')
    ->and($effect->delta)->toBe(-1)
    ->and($effect->affectsUser)->toBeFalse()
    ->and(new ModifyStatStageSkillEffect('attack', 2, affectsUser: true)->affectsUser)->toBeTrue();
});

it('resets every stage at once', function () {
  $battler = makeStagedBattler();
  $battler->addStatStage('attack', 2);
  $battler->addStatStage('speed', -1);

  $battler->resetStatStages();

  expect($battler->getStatStage('attack'))->toBe(0)
    ->and($battler->getStatStage('speed'))->toBe(0);
});
