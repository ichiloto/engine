<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\ResetAtbSkillEffect;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;
use function Tests\Support\Battle\writeQueuedAttackEffects;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

beforeEach(function () {
  $this->atbCwd = getcwd();
  $this->atbRoot = sys_get_temp_dir() . '/atb-effects-' . uniqid();
  mkdir($this->atbRoot);
  writeQueuedAttackEffects($this->atbRoot);
  chdir($this->atbRoot);
  $this->atbDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
});

afterEach(function () {
  chdir($this->atbCwd);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->atbDelta);
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->atbRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->atbRoot);
});

it('resets a real ready gauge and removes its waiting queue entry while preserving other gauges', function () {
  [$engine, $context, , $actor, , $foes] = createTargetExecutionFixture(true, false, $this->createStub(AudioManager::class));
  $target = $foes[0];
  new ReflectionProperty(ActiveTimeBattleEngine::class, 'gaugeValues')->setValue($engine,
    [spl_object_id($target) => 132.0, spl_object_id($actor) => 61.0]);
  new ReflectionProperty(ActiveTimeBattleEngine::class, 'readyBattlers')->setValue($engine, [$target]);
  new ReflectionProperty(ActiveTimeBattleEngine::class, 'readyTimes')->setValue($engine, [spl_object_id($target) => 2.0]);
  $action = new SkillBattleAction(new BasicSkill('Timing blow', '', '', 4, 0, effects: [
    new HPDamageSkillEffect('5', variance: 0), new ResetAtbSkillEffect(),
  ]), random: new SeededCombatRandomSource(1));
  $action->executeWithReactions($actor, [$target], $engine->battleConfig->reactions, gauges: $engine);
  expect($engine->getGaugePercentage($target))->toBe(0.0)
    ->and($engine->getGaugePercentage($actor))->toBe(.61)
    ->and($engine->claimNextReadyBattler())->toBeNull()
    ->and(new ReflectionProperty(ActiveTimeBattleEngine::class, 'readyTimes')->getValue($engine))->toBe([])
    ->and($actor->stats->currentMp)->toBe(46)
    ->and($action->lastResult->targets[0]->secondaryOutcomes)->toContain(['type'=>'atbReset','id'=>'gauge','applied'=>true]);
  $stranger = new Character('Outside battle', 0, new Stats(currentHp: 100));
  expect($engine->resetBattlerGauge($stranger))->toBeFalse();
  $target->stats->currentHp = 0;
  expect($engine->resetBattlerGauge($target))->toBeFalse();
});

it('preserves a target gauge when the preceding damage misses and records unavailable timing in traditional combat', function () {
  [$engine, , , $actor, , $foes] = createTargetExecutionFixture(true, false, $this->createStub(AudioManager::class));
  $target = $foes[0];
  $target->stats->evasion = 1000;
  new ReflectionProperty(ActiveTimeBattleEngine::class, 'gaugeValues')->setValue($engine, [spl_object_id($target) => 78.0]);
  $action = new SkillBattleAction(new BasicSkill('Timing blow', '', '', 0, 0, effects: [
    new HPDamageSkillEffect('5', variance: 0), new ResetAtbSkillEffect(),
  ]), random: new SeededCombatRandomSource(1));
  // Basic invocation accuracy 0 means guaranteed, so explicitly author an accuracy roll.
  $skill = SkillRecord::writeSkill($action->skill);
  $skill['invocation']['accuracy'] = 95;
  $action = new SkillBattleAction(SkillRecord::readSkill($skill), random: new SeededCombatRandomSource(1));
  $action->executeWithReactions($actor, [$target], $engine->battleConfig->reactions, gauges: $engine);
  expect($engine->getGaugePercentage($target))->toBe(.78)
    ->and($action->lastResult->targets[0]->secondaryOutcomes[0]['applied'])->toBeFalse();
  $reset = new SkillBattleAction(new BasicSkill('Reset', '', '', 0, 0, effects: [new ResetAtbSkillEffect()]));
  $reset->executeWithReactions($actor, [$target], new BattleReactions());
  expect($reset->lastResult->targets[0]->secondaryOutcomes[0]['applied'])->toBeFalse();
});

it('routes an authored reset through live turn execution and shows the resulting gauge', function (bool $graphical) {
  $fixture = createTargetExecutionFixture(true, $graphical, $this->createStub(AudioManager::class));
  [$engine, $context, , $actor, , $foes] = $fixture;
  new ReflectionProperty(ActiveTimeBattleEngine::class, 'gaugeValues')->setValue($engine, [spl_object_id($foes[0]) => 82.0]);
  queueTargetExecution($fixture, new SkillBattleAction(new BasicSkill('Reset', '', '', 0, 0,
    effects: [new ResetAtbSkillEffect()])), [$foes[0]], $actor);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1/60);
  for ($tick = 0; $tick < 900 && $context->getCurrentTurn() !== null; $tick++) { $engine->actionExecutionState->update($context); }
  expect($context->getCurrentTurn())->toBeNull()->and($engine->getGaugePercentage($foes[0]))->toBe(0.0);
})->with([false, true]);

it('applies current state pace to real gauge progression, pauses at zero and restores pace when cured', function () {
  [$engine, $context, , $actor] = createTargetExecutionFixture(true, false, $this->createStub(AudioManager::class));
  $gauge = new ReflectionProperty(ActiveTimeBattleEngine::class, 'gaugeValues');
  $gauge->setValue($engine, [spl_object_id($actor) => 0.0]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, .1);
  $engine->progressGauges($context);
  $ordinary = $engine->getGaugePercentage($actor);
  $gauge->setValue($engine, [spl_object_id($actor) => 0.0]);
  $actor->addState(new State('slow', 'Slow', atbRate: .5));
  $engine->progressGauges($context);
  expect($engine->getGaugePercentage($actor))->toBe($ordinary / 2);
  $actor->addState(new State('pause', 'Pause', atbRate: 0));
  $prior = $engine->getGaugePercentage($actor);
  $engine->progressGauges($context);
  expect($engine->getGaugePercentage($actor))->toBe($prior);
  $actor->removeState('pause');
  $actor->removeState('slow');
  $engine->progressGauges($context);
  expect($engine->getGaugePercentage($actor))->toBe($prior + $ordinary);
});

it('round trips reset effects and reads older serialized states with ordinary pace', function () {
  $record = SkillRecord::writeSkill(new BasicSkill('Reset', '', '', 0, 0, effects: [new ResetAtbSkillEffect()]));
  expect(SkillRecord::writeSkill(SkillRecord::readSkill($record)))->toBe($record);
  $legacy = (array) new State('legacy', 'Legacy');
  foreach (array_keys($legacy) as $key) {
    if ((str_contains($key, "\0") ? substr($key, strrpos($key, "\0") + 1) : $key) === 'atbRate') { unset($legacy[$key]); }
  }
  $saved = 'O:' . strlen(State::class) . ':"' . State::class . '":' . substr(serialize($legacy), 2);
  expect(unserialize($saved)->atbRate)->toBe(1.0);
  foreach ([-1, INF, NAN, 'fast'] as $bad) {
    expect(fn() => State::fromArray(['id'=>'invalid', 'atbRate'=>$bad]))->toThrow(InvalidArgumentException::class);
  }
});
