<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Battle\CounterAttackResolver;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\PrepareCounterSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;
use function Tests\Support\Battle\writeQueuedAttackEffects;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

function createPreparedResponse(int $damage = 7): BasicSkill
{
  return new BasicSkill('Synthetic reply', '', '', 2, 0, effects: [
    new HPDamageSkillEffect((string)$damage, variance: 0), new AddStateSkillEffect('synthetic-stun', guaranteed: true),
  ]);
}

function createPreparedBait(): BasicSkill
{
  return new BasicSkill('Synthetic bait', '', '', 3, 0, occasion: Occasion::BATTLE_SCREEN,
    effects: [new PrepareCounterSkillEffect('Synthetic reply', 'synthetic-decoy')]);
}

beforeEach(function () {
  $this->preparedPriorStates = new ReflectionProperty(StateRegistry::class, 'states')->getValue();
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, [
    'synthetic-decoy' => new State('synthetic-decoy', 'Decoy'),
    'synthetic-stun' => new State('synthetic-stun', 'Stun', durationTurns: 1, preventsAction: true),
  ]);
  $this->preparedCwd = getcwd();
  $this->preparedRoot = sys_get_temp_dir() . '/prepared-counter-' . uniqid();
  mkdir($this->preparedRoot);
  writeQueuedAttackEffects($this->preparedRoot);
  chdir($this->preparedRoot);
  $this->preparedDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
});

afterEach(function () {
  chdir($this->preparedCwd);
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, $this->preparedPriorStates);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->preparedDelta);
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->preparedRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->preparedRoot);
});

it('round trips a battle-only selected-opponent preparation and refuses an invalid menu scope', function () {
  $record = SkillRecord::writeSkill(createPreparedBait());
  expect(SkillRecord::writeSkill(SkillRecord::readSkill($record)))->toBe($record);
  $record['occasion'] = Occasion::ALWAYS->value;
  expect(fn() => SkillRecord::readSkill($record))->toThrow(InvalidArgumentException::class);
});

it('draws only the chosen foes attack, skips its whole repeated invocation and returns one live counter lease', function (bool $magical) {
  $catalog = SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]);
  $random = new SeededCombatRandomSource(1);
  $reactions = new BattleReactions($catalog, $random);
  $owner = new Character('Owner', 0, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $ally = new Character('Ally', 0, new Stats(currentHp: 100, totalHp: 100));
  $foe = new Character('Foe', 0, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20));
  $other = new Character('Other', 0, new Stats(currentHp: 100, totalHp: 100));
  new ReflectionProperty(Character::class, 'stateResistances')->setValue($foe, ['synthetic-stun' => 0]);
  $bait = new SkillBattleAction(createPreparedBait());
  $bait->executeWithReactions($owner, [$foe], $reactions);
  expect($owner->hasState('synthetic-decoy'))->toBeTrue()->and($owner->stats->currentMp)->toBe(17);
  $attackType = $magical ? \Ichiloto\Engine\Entities\Skills\MagicSkill::class : BasicSkill::class;
  $attack = new SkillBattleAction(new $attackType('Repeated blow', '', '', 4, 0,
    invocation: new SkillInvocation(repeat: 4), effects: [new HPDamageSkillEffect('99', variance: 0),
      new AddStateSkillEffect('synthetic-stun', guaranteed: true)]));
  expect($reactions->resolveTargets($attack, $other, [$ally], [$owner, $ally], [$foe, $other]))->toBe([$ally]);
  $targets = $reactions->resolveTargets($attack, $foe, [$ally], [$owner, $ally], [$foe, $other]);
  expect($targets)->toBe([$owner]);
  $attack->executeWithReactions($foe, $targets, $reactions);
  expect($attack->lastResult->hits())->toHaveCount(1)
    ->and($attack->lastResult->hits()[0]->missReason)->toBe('decoy')
    ->and($owner->stats->currentHp)->toBe(100)->and($ally->stats->currentHp)->toBe(100)
    ->and($owner->hasState('synthetic-stun'))->toBeFalse()->and($owner->hasState('synthetic-decoy'))->toBeFalse()
    ->and($foe->stats->currentMp)->toBe(16);
  $responses = $reactions->takeResponses($foe);
  expect($responses)->toHaveCount(1)->and($reactions->takeResponses($foe))->toBe([]);
  $resolver = new CounterAttackResolver($catalog, $random);
  expect($resolver->canExecute($responses[0], [$owner, $ally], [$foe, $other]))->toBeTrue();
  $responses[0]->action->executeWithReactions($owner, [$foe], $reactions, allowInterception: false);
  expect($foe->hasState('synthetic-stun'))->toBeTrue()->and($owner->stats->currentMp)->toBe(15)
    ->and($reactions->resolveTargets($attack, $foe, [$ally], [$owner, $ally], [$foe, $other]))->toBe([$ally]);
  $reactions->clear();
})->with([false, true]);

it('cancels a removed stance, an absent participant and a defeated owner without diverting unrelated commands', function (string $case) {
  $owner = new Character('Owner', 0, new Stats(currentHp: 100));
  $foe = new Character('Foe', 0, new Stats(currentHp: 100));
  $ally = new Character('Ally', 0, new Stats(currentHp: 100));
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]));
  $reactions->prepareCounter($owner, $foe, new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  if ($case === 'removed') { $owner->removeState('synthetic-decoy'); }
  if ($case === 'defeated') { $owner->stats->currentHp = 0; }
  expect($reactions->resolveTargets(new AttackAction('Attack'), $foe, [$ally],
    $case === 'absent' ? [$ally] : [$owner, $ally], [$foe]))->toBe([$ally]);
  $reactions->clear();
  expect($owner->hasState('synthetic-decoy'))->toBeFalse();
})->with(['removed', 'defeated', 'absent']);

it('releases a prepared stance when another real command defeats its selected opponent', function () {
  $owner = new Character('Owner', 0, new Stats(currentHp: 100));
  $ally = new Character('Ally', 0, new Stats(currentHp: 100));
  $foe = new Character('Foe', 0, new Stats(currentHp: 100, evasion: 0));
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]));
  $reactions->prepareCounter($owner, $foe, new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  $finish = new SkillBattleAction(new BasicSkill('Finisher', '', '', 0, 0,
    invocation: new SkillInvocation(accuracy: 100), effects: [new HPDamageSkillEffect('10000', variance: 0,
      resolutionKind: \Ichiloto\Engine\Battle\Resolution\ResolutionKind::TRUE_DAMAGE)]), random: new SeededCombatRandomSource(4));
  $finish->executeWithReactions($ally, [$foe], $reactions);
  expect($foe->isFighting)->toBeFalse()->and($owner->hasState('synthetic-decoy'))->toBeFalse()
    ->and($reactions->takeResponses($foe))->toBe([]);
});

it('releases inactive or unrostered leases while preserving a later unrelated grant of the same state', function () {
  $owner = new Character('Owner', 0, new Stats(currentHp: 100));
  $foe = new Character('Foe', 0, new Stats(currentHp: 100));
  $ally = new Character('Ally', 0, new Stats(currentHp: 100));
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]));
  $reactions->prepareCounter($owner, $foe, new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  $reactions->resolveTargets(new AttackAction('Attack'), $foe, [$ally], [$ally], [$foe]);
  expect($owner->hasState('synthetic-decoy'))->toBeFalse();
  $reactions->prepareCounter($owner, $foe, new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  $owner->removeState('synthetic-decoy');
  $owner->addState(new State('synthetic-decoy', 'Unrelated grant'));
  $replacement = $owner->states[0];
  $reactions->beginTurn($ally);
  $reactions->clear();
  expect($owner->states)->toContain($replacement)->and($owner->hasState('synthetic-decoy'))->toBeTrue();
});

it('rejects preparation outside a battle before paying MP and releases the injected context after failure', function () {
  $owner = new Character('Owner', 0, new Stats(currentHp: 100, currentMp: 10));
  $foe = new Character('Foe', 0, new Stats(currentHp: 100));
  $bait = new SkillBattleAction(createPreparedBait());
  expect(fn() => $bait->execute($owner, [$foe]))->toThrow(InvalidArgumentException::class)
    ->and($owner->stats->currentMp)->toBe(10);
  $reactions = new BattleReactions(SkillCatalog::fromSkills([]));
  expect(fn() => $bait->executeWithReactions($owner, [$foe], $reactions))->toThrow(InvalidArgumentException::class)
    ->and($owner->stats->currentMp)->toBe(10);
  expect(fn() => $bait->execute($owner, [$foe]))->toThrow(InvalidArgumentException::class);
});

it('keeps selected-opponent leases out of actor and battle configuration saves', function () {
  $owner = new Character('Owner', 0, new Stats(currentHp: 100));
  $foe = new Character('Unserialized opponent', 0, new Stats(currentHp: 100));
  $party = new \Ichiloto\Engine\Entities\Party();
  $party->addMember($owner);
  $config = new \Ichiloto\Engine\Scenes\Battle\BattleConfig($party, new \Ichiloto\Engine\Entities\Troop('Synthetic', []));
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]));
  new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleConfig::class, 'reactionOwner')->setValue($config, $reactions);
  $reactions->prepareCounter($owner, $foe, new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  $saved = serialize($config);
  expect($saved)->not->toContain('Unserialized opponent')->not->toContain(BattleReactions::class);
  $restored = unserialize($saved);
  expect($restored->reactions)->not->toBe($reactions);
  $reactions->clear();
  expect($owner->hasState('synthetic-decoy'))->toBeFalse();
});

it('releases the prior reaction owner when the active-time engine is reconfigured', function () {
  [$engine, $context, $screen, $owner, , $foes] = createTargetExecutionFixture(true, false, $this->createStub(AudioManager::class));
  $reactions = new BattleReactions(SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]));
  new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleConfig::class, 'reactionOwner')->setValue($engine->battleConfig, $reactions);
  $reactions->prepareCounter($owner, $foes[0], new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
  expect($owner->hasState('synthetic-decoy'))->toBeTrue();
  $engine->configure(new \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig(
    $context->party, $context->troop, $screen));
  expect($owner->hasState('synthetic-decoy'))->toBeFalse()->and($reactions->turnOwner)->toBeNull();
});

it('runs the diverted miss and counter through the shared live turn playback without consuming the reactors turn',
  function (bool $activeTime, bool $graphical) {
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createStub(AudioManager::class));
    [$engine, $context, $screen, $owner, $ally, $enemies] = $fixture;
    $ally->stats->currentHp = 100;
    $catalog = SkillCatalog::fromSkills(['r.php' => createPreparedResponse()]);
    $reactions = new BattleReactions($catalog, new SeededCombatRandomSource(1));
    new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleConfig::class, 'reactionOwner')->setValue($engine->battleConfig, $reactions);
    $reactions->prepareCounter($owner, $enemies[0], new CounterAttackRule('Synthetic reply'), StateRegistry::get('synthetic-decoy'));
    $hp = $owner->stats->currentHp;
    $turn = queueTargetExecution($fixture, new AttackAction('Attack'), [$ally], $enemies[0]);
    expect($turn->targets)->toBe([$owner]);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / 60);
    $responded = false;
    for ($tick = 0; $tick < 900 && $context->getCurrentTurn() !== null; $tick++) {
      if ($screen->fieldWindow->getCommandPlayback()?->actor === $owner) { $responded = true; }
      $engine->actionExecutionState->update($context);
    }
    expect($responded)->toBeTrue()->and($owner->stats->currentHp)->toBe($hp)
      ->and($owner->hasState('synthetic-decoy'))->toBeFalse()
      ->and($owner->stats->currentMp)->toBe(48)->and($context->getCurrentTurn())->toBeNull();
    // A response received during the attacker's own turn must survive to block its next turn.
    expect($enemies[0]->hasState('synthetic-stun'))->toBeTrue();
    $engine->stop();
    expect($reactions->turnOwner)->toBeNull();
    $reactions->clear();
  })->with([false, true])->with([false, true]);

it('shares preparation with simulation and preserves caller resources and independent runs', function () {
  $fixture = createTargetExecutionFixture(false, false, $this->createStub(AudioManager::class));
  [, $context, , $owner, , $enemies] = $fixture;
  new ReflectionProperty(Character::class, 'attackSkill')->setValue($owner, createPreparedBait());
  $owner->stats->speed = 100;
  $enemies[0]->stats->speed = 1;
  $enemies[1]->stats->currentHp = 0;
  $catalog = SkillCatalog::fromSkills(['r.php' => createPreparedResponse(999), 'b.php' => createPreparedBait()]);
  $before = [$owner->stats->currentHp, $owner->stats->currentMp, $enemies[0]->stats->currentHp];
  $report = new BattleSimulator(turnLimit: 2, skills: $catalog)->simulate($context->party, $context->troop, 2);
  expect($report->victories)->toBe(2)->and($owner->hasState('synthetic-decoy'))->toBeFalse()
    ->and([$owner->stats->currentHp, $owner->stats->currentMp, $enemies[0]->stats->currentHp])->toBe($before);
});
