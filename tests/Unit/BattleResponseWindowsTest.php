<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\ActionCondition;
use Ichiloto\Engine\Entities\Enemies\ActionPattern;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

function responseWindowSkillRecord(string $name, array $effects): array
{
  return [
    'kind' => 'special', 'name' => $name, 'description' => '', 'icon' => '', 'cost' => 2, 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'All', 'status' => 'Alive'],
    'invocation' => ['message' => '$1 uses ' . $name, 'speed' => 0, 'accuracy' => 100, 'repeat' => 1, 'apGain' => 0],
    'effects' => $effects,
  ];
}

function responseWindowSkills(): array
{
  return [
    SkillRecord::readSkill(responseWindowSkillRecord('Warning', [
      ['type' => 'prepare_response_window', 'skill' => 'Impact', 'responseTurns' => 1],
    ])),
    SkillRecord::readSkill(responseWindowSkillRecord('Impact', [
      ['type' => 'hp_damage', 'formula' => '12', 'variance' => 0, 'resolutionKind' => ResolutionKind::TRUE_DAMAGE->value],
    ])),
  ];
}

it('round trips the warning and rejects invalid authoring before paying its cost', function () {
  [$warning] = responseWindowSkills();
  $record = SkillRecord::writeSkill($warning);
  expect($record['effects'])->toBe([['type' => 'prepare_response_window', 'skill' => 'Impact', 'responseTurns' => 1]]);
  $narrow = $record;
  $narrow['scope']['number'] = 'One';
  expect(fn() => SkillRecord::readSkill($narrow))->toThrow(InvalidArgumentException::class);
  $zero = $record;
  $zero['effects'][0]['responseTurns'] = 0;
  expect(fn() => SkillRecord::readSkill($zero))->toThrow(InvalidArgumentException::class);

  $caster = new Character('Caster', 1, new Stats(currentHp: 100, currentMp: 10));
  $target = new Character('Target', 1, new Stats(currentHp: 100));
  $action = new SkillBattleAction($warning);
  $reactions = new BattleReactions(SkillCatalog::fromSkills([]));
  expect(fn() => $action->executeWithReactions($caster, [$target], $reactions))
    ->toThrow(InvalidArgumentException::class, 'Impact');
  expect($caster->stats->currentMp)->toBe(10)->and($reactions->responseWindows->isPending($caster))->toBeFalse();
});

it('counts Guard and forced passes before taking the next legal action in both battle modes', function (bool $activeTime) {
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createStub(AudioManager::class));
  [$engine, $context, , $first, $second, [$caster]] = $fixture;
  [$warning, $impact] = responseWindowSkills();
  $second->stats->currentHp = 100;
  $first->stats->evasion = $second->stats->evasion = 0;
  $random = new SeededCombatRandomSource(10);
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['impact.php' => $impact]), $random);
  new ReflectionProperty(BattleConfig::class, 'reactionOwner')->setValue($engine->battleConfig, $reactions);
  new ReflectionProperty(Enemy::class, 'actionPatterns')->setValue($caster, [new ActionPattern($warning, 5, new ActionCondition())]);
  $select = fn(): array => EnemyActionEvaluator::chooseAction($caster, [$first, $second], [$caster], 1, 1,
    random: $random, responseWindows: $reactions->responseWindows);
  $execute = function (CharacterInterface $actor, BattleAction $action, array $targets) use ($fixture, $engine, $context, $delta) {
    $turn = queueTargetExecution($fixture, $action, $targets, $actor);
    $delta->setValue(null, 1 / 30);
    for ($tick = 0; $tick < 300 && $context->getCurrentTurn() !== null; $tick++) {
      $engine->actionExecutionState->update($context);
    }
    expect($context->getCurrentTurn())->toBeNull();
    return $turn;
  };

  try {
    $execute($caster, ...$select());
    expect($first->stats->currentHp)->toBe(80)->and($second->stats->currentHp)->toBe(100)
      ->and($reactions->responseWindows->isPending($caster))->toBeTrue();
    $execute($first, new GuardAction('Guard'), [$first]);
    $early = $execute($caster, ...$select());
    expect($early->action->name)->not->toBe('Warning')->not->toBe('Impact');
    $second->addState(new State('response-pass', 'Pass', durationTurns: 1, preventsAction: true));
    $execute($second, new GuardAction('Guard'), [$second]);

    $caster->addState(new State('caster-pass', 'Pass', durationTurns: 1, preventsAction: true));
    $execute($caster, ...$select());
    expect($reactions->responseWindows->isPending($caster))->toBeTrue();
    $impactTurn = $execute($caster, ...$select());
    expect($impactTurn->action->name)->toBe('Impact')->and($impactTurn->targets)->toBe([$first, $second])
      ->and($impactTurn->action->lastResult->actualHpLost())->toBeGreaterThan(0)
      ->and($reactions->responseWindows->isPending($caster))->toBeFalse();
  } finally {
    $engine->stop();
    $delta->setValue(null, $previousDelta);
  }
})->with([false, true]);

it('drops a knocked-out respondent and targets the living roster after revival', function () {
  [$warning, $impact] = responseWindowSkills();
  $caster = new Character('Caster', 1, new Stats(currentHp: 100, currentMp: 10));
  $first = new Character('First', 1, new Stats(currentHp: 100));
  $second = new Character('Second', 1, new Stats(currentHp: 100));
  $joiner = new Character('Joiner', 1, new Stats(currentHp: 100));
  $random = new SeededCombatRandomSource(3);
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['impact.php' => $impact]), $random);
  $windows = $reactions->responseWindows;
  new SkillBattleAction($warning)->executeWithReactions($caster, [$first, $second], $reactions);
  $second->stats->currentHp = 0;
  $windows->releaseInactive();
  $second->stats->currentHp = 100;
  $windows->completeTurn($first, [$caster, $first, $second, $joiner]);
  $action = $windows->getReadyAction($caster, $random);
  expect($action?->skill)->toBe($impact)
    ->and(\Ichiloto\Engine\Battle\BattleTargetPolicy::resolveTargets(
      $action->targetScope, $caster, [$caster], [$first, $second, $joiner], [], $random))->toBe([$first, $second, $joiner]);
  $windows->releaseInactive([$first, $second, $joiner]);
  expect($windows->isPending($caster))->toBeFalse();
});

it('runs the same warning and impact through the battle simulator', function () {
  [, $context, , $partyMember, , [$caster, $other]] =
    createTargetExecutionFixture(false, false, $this->createStub(AudioManager::class));
  [$warning, $impact] = responseWindowSkills();
  $caster->stats->speed = 99;
  $partyMember->stats->speed = 1;
  $partyMember->stats->evasion = 0;
  $other->stats->currentHp = 0;
  new ReflectionProperty(Enemy::class, 'actionPatterns')->setValue($caster, [new ActionPattern($warning, 5, new ActionCondition())]);
  $catalog = SkillCatalog::fromSkills(['impact.php' => $impact]);

  $warningOnly = new BattleSimulator(turnLimit: 1, skills: $catalog)->simulate($context->party, $context->troop, 1);
  $throughImpact = new BattleSimulator(turnLimit: 2, skills: $catalog)->simulate($context->party, $context->troop, 1);

  expect($warningOnly->hpLost[$partyMember->name] ?? 0.0)->toBe(0.0)
    ->and($throughImpact->hpLost[$partyMember->name])->toBe(12.0)
    ->and($partyMember->stats->currentHp)->toBe(80);
});
