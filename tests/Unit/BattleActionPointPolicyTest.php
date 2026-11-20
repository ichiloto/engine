<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

it('gains authored AP once for a multi-target repeated miss in either battle engine', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createStub(AudioManager::class),
    actorStats: new Stats(currentHp: 80, totalHp: 100, currentAp: 49, totalAp: 60),
    settings: ['actionPoints' => ['basicAttackGain' => 10]]);
  [$engine, $context, , $actor, , $enemies] = $fixture;
  $skill = new SpecialSkill('Training swing', '', '', 0, 0,
    new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL), Occasion::BATTLE_SCREEN,
    new SkillInvocation(accuracy: 1, repeat: 3, apGain: 7), [new HPDamageSkillEffect('10', variance: 0)]);
  $random = new class implements CombatRandomSource {
    public function nextInt(int $min, int $max): int { return $max; }
  };
  $action = new SkillBattleAction($skill, random: $random);
  $turn = queueTargetExecution($fixture, $action, $enemies);
  expect($actor->stats->currentAp)->toBe(49);

  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->actionExecutionState->update($context);
  expect($turn->isCompleted)->toBeTrue()
    ->and($action->lastResult?->targetCount())->toBe(2)
    ->and($action->lastResult?->hitCount())->toBe(0)
    ->and($actor->stats->currentAp)->toBe(56);
})->with([false, true]);

it('caps a basic attack award and spends AP and MP once for a valid priced miss', function (bool $activeTime) {
  $stats = new Stats(currentHp: 80, totalHp: 100, currentMp: 25, totalMp: 25, currentAp: 57, totalAp: 60);
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createStub(AudioManager::class),
    actorStats: $stats, settings: ['actionPoints' => ['basicAttackGain' => 10]]);
  [$engine, $context, , $actor, , $enemies] = $fixture;
  $random = new class implements CombatRandomSource {
    public function nextInt(int $min, int $max): int { return $max; }
  };
  $attack = new AttackAction('Attack', random: $random);
  queueTargetExecution($fixture, $attack, [$enemies[0]]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->actionExecutionState->update($context);
  expect($actor->stats->currentAp)->toBe(60);

  $skill = new SpecialSkill('Paid technique', '', '', 4, 0, occasion: Occasion::BATTLE_SCREEN,
    invocation: new SkillInvocation(accuracy: 1, apGain: 10), effects: [new HPDamageSkillEffect('10', variance: 0)],
    apCost: 30);
  $action = new SkillBattleAction($skill, random: $random);
  queueTargetExecution($fixture, $action, [$enemies[0]]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->actionExecutionState->update($context);
  expect($action->lastResult?->targetCount())->toBe(1)
    ->and($action->lastResult?->hitCount())->toBe(0)
    ->and($actor->stats->currentAp)->toBe(30)
    ->and($actor->stats->currentMp)->toBe(21);

  $actor->stats->currentAp = 29;
  $action->executeWithReactions($actor, [$enemies[0]], $engine->battleConfig->reactions);
  expect($actor->stats->currentAp)->toBe(29)->and($actor->stats->currentMp)->toBe(21)
    ->and($action->lastResult)->toBeNull();
  $actor->stats->currentAp = 30;
  $enemies[0]->stats->currentHp = 0;
  $action->executeWithReactions($actor, [$enemies[0]], $engine->battleConfig->reactions);
  expect($actor->stats->currentAp)->toBe(30)->and($actor->stats->currentMp)->toBe(21)
    ->and($action->lastResult)->toBeNull();
})->with([false, true]);
