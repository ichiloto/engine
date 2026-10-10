<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\CounterAttackResolver;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Abilities\AbilitySortOrder;
use Ichiloto\Engine\Entities\Abilities\LearnableAbility;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\EnemyRecord;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\createQueuedBattleFixture;
use function Tests\Support\Battle\queueTargetExecution;
use function Tests\Support\Battle\writeQueuedAttackEffects;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

function createCounterTestSkill(string $name = 'Riposte', int $damage = 9, int $cost = 3,
  ?CounterAttackRule $grant = null): SpecialSkill
{
  return new SpecialSkill($name, '', '', $cost, 0, invocation: new SkillInvocation(accuracy: 100),
    effects: [new HPDamageSkillEffect((string)$damage, variance: 0)], counterAttack: $grant);
}

beforeEach(function () {
  $this->counterPriorConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  $this->counterPriorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  $this->counterPriorCwd = getcwd();
  $this->counterRoot = sys_get_temp_dir() . '/counter-command-' . uniqid();
  mkdir($this->counterRoot);
  writeQueuedAttackEffects($this->counterRoot);
  chdir($this->counterRoot);
});

afterEach(function () {
  chdir($this->counterPriorCwd);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->counterPriorDelta);
  $this->counterPriorConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->counterPriorConfig);
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->counterRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->counterRoot);
});

it('requires an explicit catalogue response and rejects malformed opt-ins', function (mixed $data) {
  expect(fn() => CounterAttackRule::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  'true' => [true], 'false' => [false], 'empty' => [[]], 'blank skill' => [['skill' => '']],
  'numeric skill' => [['skill' => 2]], 'whitespace' => [['skill' => ' Riposte']],
  'implicit chance' => [['skill' => 'Riposte', 'chance' => 100]],
]);

it('shares validated responses and record round trips without storing innate actor rules in saves', function () {
  $rule = new CounterAttackRule('Riposte');
  $skill = createCounterTestSkill(grant: $rule);
  $catalog = SkillCatalog::fromSkills(['Skills/riposte.php' => $skill]);
  expect($rule->resolveSkill($catalog))->toBe($skill)
    ->and(CounterAttackRule::fromArray(null))->toBeNull()
    ->and(CounterAttackRule::fromArray($rule->toArray())?->skill)->toBe('Riposte');
  $record = SkillRecord::writeSkill($skill);
  expect(SkillRecord::readSkill($record)->counterAttack?->toArray())->toBe($rule->toArray());
  $state = State::fromArray(['id' => 'riposte', 'counterAttack' => $rule->toArray()]);
  expect($state->counterAttack?->toArray())->toBe($rule->toArray());
  $actor = ActorDefinition::fromArray(['id' => 'synthetic', 'name' => 'Synthetic',
    'currentExp' => 0, 'stats' => new Stats(currentHp: 100)->jsonSerialize(),
    'counterAttack' => $rule->toArray()])->createCharacter();
  expect($actor->counterAttack?->toArray())->toBe($rule->toArray())
    ->and($actor->toArray())->not->toHaveKey('counterAttack');
  $actor->restoreMutableState(['counterAttack' => ['skill' => 'Rejected saved policy']]);
  expect($actor->counterAttack?->skill)->toBe('Riposte');
  $data = ['name' => 'Synthetic enemy', 'level' => 1, 'imagePath' => 'missing',
    'stats' => array_fill_keys(array_map(static fn(StatKey $stat): string => $stat->value, StatKey::cases()), 20),
    'rewards' => ['experience' => 0, 'gold' => 0], 'counterAttack' => $rule->toArray()];
  mkdir($this->counterRoot . '/assets/Graphics/Enemies', 0777, true);
  file_put_contents($this->counterRoot . '/assets/Graphics/Enemies/missing.txt', '@');
  $enemy = EnemyRecord::readEnemy($data, $catalog);
  expect(EnemyRecord::writeEnemy($enemy)['counterAttack'])->toBe($rule->toArray());
});

it('rejects absent, magical, non-battle, multi-target and non-opponent response references', function (string $kind) {
  $skill = match ($kind) {
    'missing' => null,
    'magic' => new MagicSkill('Riposte', '', '', 0, 0),
    'menu' => new BasicSkill('Riposte', '', '', 0, 0, occasion: Occasion::MENU_SCREEN),
    'all' => new BasicSkill('Riposte', '', '', 0, 0, new ItemScope(number: ItemScopeNumber::ALL)),
    'ally' => new BasicSkill('Riposte', '', '', 0, 0, new ItemScope(side: ItemScopeSide::ALLY)),
    'dead' => new BasicSkill('Riposte', '', '', 0, 0, new ItemScope(status: ItemScopeStatus::DEAD)),
    'summon' => new SpecialSkill('Guardian', '', '', 0, 0),
  };
  $rule = new CounterAttackRule($skill?->name ?? 'Riposte');
  $catalog = SkillCatalog::fromSkills($skill === null ? [] : ['Skills/response.php' => $skill],
    summons: $kind === 'summon' ? [new SummonCutsceneDefinition('guardian', 'Guardian', linkedActionId: 'Guardian')] : []);
  expect(fn() => $rule->resolveSkill($catalog))->toThrow(InvalidArgumentException::class);
})->with(['missing', 'magic', 'menu', 'all', 'ally', 'dead', 'summon']);

it('grants only owned active states and learned abilities with stable priority regardless of UI sorting', function () {
  $a = createCounterTestSkill('A grant', grant: new CounterAttackRule('A response'));
  $z = createCounterTestSkill('Z grant', grant: new CounterAttackRule('Z response'));
  $actor = new Character('Synthetic', 0, new Stats(currentHp: 100),
    abilityBook: new AbilityBook([$z, $a], [new LearnableAbility(createCounterTestSkill('Not learned',
      grant: new CounterAttackRule('Unowned response')))], AbilitySortOrder::Z_TO_A),
    counterAttack: new CounterAttackRule('Innate response'));
  $actor->addState(new State('riposte', 'Riposte', durationTurns: 2, counterAttack: new CounterAttackRule('State response')));
  expect(array_map(static fn($rule): string => $rule->skill, $actor->getCounterAttackRules()))
    ->toBe(['Innate response', 'State response', 'A response', 'Z response']);
  $actor->abilityBook->sortLearnedAbilities(AbilitySortOrder::A_TO_Z);
  $actor->removeState('riposte');
  expect(array_map(static fn($rule): string => $rule->skill, $actor->getCounterAttackRules()))
    ->toBe(['Innate response', 'A response', 'Z response']);
});

it('responds only to typed landed opposing physical hits, not misses, magic, healing, unrelated outcomes or unowned grants',
  function (string $case) {
    $fixture = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
    [, $context, , $actor, , $enemies] = $fixture;
    $recipient = $enemies[0];
    $rule = new CounterAttackRule('Riposte');
    if ($case !== 'no grant') { new ReflectionProperty(Enemy::class, 'counterAttack')->setValue($recipient, $rule); }
    $skill = $case === 'magic' ? new MagicSkill('Magic', '', '', 0, 0,
      invocation: new SkillInvocation(accuracy: 100), effects: [new HPDamageSkillEffect('7', variance: 0)])
      : new BasicSkill('Strike', '', '', 0, 0, invocation: new SkillInvocation(accuracy: 100, repeat: 2),
        effects: [$case === 'heal' ? new HPRecoverSkillEffect('7', variance: 0)
          : new HPDamageSkillEffect('7', variance: 0)]);
    if ($case === 'miss') { $recipient->stats->evasion = 1000; }
    if ($case === 'blocked') { $recipient->addState(new State('sleep', 'Sleep', preventsAction: true)); }
    if ($case === 'unaffordable') { $recipient->stats->currentMp = 0; }
    $action = new SkillBattleAction($skill, random: new SeededCombatRandomSource(1));
    $action->execute($actor, [$recipient]);
    if ($case === 'ko') { $recipient->stats->currentHp = 0; }
    $resolver = new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill()]));
    $responses = $resolver->resolveResponses($case === 'stale' ? new CombatActionResult('x', 'x', 'other', [])
      : $action->lastResult, $actor, $case === 'unrelated' ? [$enemies[1]] : [$recipient, $recipient],
      $case === 'same side' ? [$actor, $recipient] : [$actor], $case === 'same side' ? [] : $enemies);
    expect($responses)->toHaveCount($case === 'landed' ? 1 : 0);
    if ($responses !== []) {
      expect($responses[0]->actor)->toBe($recipient)->and($responses[0]->target)->toBe($actor);
      $recipient->stats->currentHp = 0;
      expect($resolver->canExecute($responses[0], [$actor], $enemies))->toBeFalse();
    }
  })->with(['landed', 'miss', 'magic', 'heal', 'blocked', 'unaffordable', 'ko', 'stale', 'unrelated', 'same side', 'no grant']);

it('queues a counter after return with normal poses and results while preserving reactor turns, ticks, guard and gauge',
  function (bool $activeTime, bool $graphical, bool $reduced, bool $partyResponds) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $attacker = $partyResponds ? $enemies[0] : $actor;
    $recipient = $partyResponds ? $actor : $enemies[0];
    foreach ([$attacker, $recipient] as $battler) {
      new ReflectionProperty($battler, 'counterAttack')->setValue($battler, new CounterAttackRule('Riposte'));
      $battler->addState(new State('poison', 'Poison', durationTurns: 3, tickFormula: '-1'));
    }
    $recipient->beginGuarding();
    $hp = $attacker->stats->currentHp;
    $mp = $recipient->stats->currentMp;
    if ($activeTime) {
      new ReflectionProperty(ActiveTimeBattleEngine::class, 'gaugeValues')->setValue($engine,
        [spl_object_id($recipient) => 73.0]);
    }
    new ReflectionProperty(ActionExecutionState::class, 'counterAttacks')->setValue($engine->actionExecutionState,
      new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill()]),
        new SeededCombatRandomSource(1)));
    $action = new SkillBattleAction(new BasicSkill('Twin strike', '', '', 0, 0,
      invocation: new SkillInvocation(accuracy: 100, repeat: 2),
      effects: [new HPDamageSkillEffect('7', variance: 0)], animationId: 3), random: new SeededCombatRandomSource(1));
    $turn = queueTargetExecution($fixture, $action, [$recipient], $attacker);
    $original = $screen->fieldWindow->getCommandPlayback();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / 60);
    $response = null;
    $returned = false;
    $responsePhases = [];
    for ($tick = 0; $tick < 900 && $context->getCurrentTurn() !== null; $tick++) {
      $playback = $screen->fieldWindow->getCommandPlayback();
      if ($playback === $original && $playback->phase === 'return') {
        $returned = true;
        expect($attacker->stats->currentHp)->toBe($hp)->and($recipient->stats->currentMp)->toBe($mp);
      }
      if ($playback !== null && $playback->actor === $recipient) {
        $response ??= $playback;
        expect($returned)->toBeTrue()->and($original->isCompleted)->toBeTrue()
          ->and($original->getAdvanceFraction())->toBe(0.0)->and($context->getCurrentTurn())->toBe($turn);
        $responsePhases[$playback->phase] = true;
        if ($playback->phase === 'source') { expect($playback->getPoseRole($recipient))->toBe(BattlePoseRole::SKILL); }
      }
      $engine->actionExecutionState->update($context);
    }
    expect($context->getCurrentTurn())->toBeNull()->and($response)->not->toBeNull()
      ->and($responsePhases)->toHaveKeys(['advance', 'announce', 'source', 'target', 'reaction', 'return'])
      ->and($recipient->stats->currentMp)->toBe($mp - 3)
      ->and($attacker->stats->currentHp)->toBe($hp - 10)
      ->and($attacker->states[0]->remainingTurns)->toBe(2)
      ->and($recipient->states[0]->remainingTurns)->toBe(3)
      ->and($recipient->isGuarding)->toBeTrue()
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($screen->fieldWindow->results)->toHaveCount(3);
    if ($activeTime) {
      expect($engine->getGaugePercentage($recipient))->toBe(.73)
        ->and(new ReflectionProperty(ActiveTimeBattleEngine::class, 'roundParticipants')->getValue($engine))
        ->toBe([spl_object_id($attacker) => true]);
    }
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('uses the same counter outcomes in simulation without enabling default retaliation or changing caller resources', function () {
  $fixture = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
  [, $context, , $actor, , $enemies] = $fixture;
  $actor->stats->speed = 1;
  $actor->stats->attack = 0;
  $actor->stats->defence = 0;
  $enemies[0]->stats->attack = 1;
  $enemies[0]->stats->speed = 100;
  $enemies[1]->stats->currentHp = 0;
  $hp = $actor->stats->currentHp;
  $mp = $actor->stats->currentMp;
  $enemyHp = $enemies[0]->stats->currentHp;
  $baseline = new BattleSimulator(turnLimit: 1)->simulate($context->party, $context->troop, 1);
  new ReflectionProperty(Character::class, 'counterAttack')->setValue($actor, new CounterAttackRule('Riposte'));
  $catalog = SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill(damage: 200)]);
  $report = new BattleSimulator(turnLimit: 1, skills: $catalog)->simulate($context->party, $context->troop, 1);
  expect($baseline->stalemates)->toBe(1)->and($report->victories)->toBe(1)
    ->and($actor->stats->currentHp)->toBe($hp)->and($actor->stats->currentMp)->toBe($mp)
    ->and($enemies[0]->stats->currentHp)->toBe($enemyHp)
    ->and($report->damageDealt['Caster'])->toBe(100.0);
});

it('rejects summons from an explicit project root even when another project is the working directory', function () {
  $root = $this->counterRoot . '/authored';
  mkdir($root . '/assets/Data/Skills', 0777, true);
  mkdir($root . '/assets/Cutscenes/Summons/guardian', 0777, true);
  foreach (['Guardian', 'Riposte'] as $name) {
    $payload = ['class' => \Ichiloto\Engine\Entities\Skills\Skill::class,
      'data' => SkillRecord::writeSkill(createCounterTestSkill($name))];
    file_put_contents($root . '/assets/Data/Skills/' . $name . '.php', '<?php return ' . var_export($payload, true) . ';');
  }
  $summon = new SummonCutsceneDefinition('guardian', 'Guardian', linkedActionId: 'Guardian');
  file_put_contents($root . '/assets/Cutscenes/Summons/guardian/guardian.data.php',
    '<?php return ' . var_export($summon->toDataArray(), true) . ';');
  file_put_contents($root . '/assets/Cutscenes/Summons/guardian/guardian.timeline.php',
    '<?php return ' . var_export($summon->toTimelineArray(), true) . ';');
  $catalog = SkillCatalog::load($root . '/assets');
  expect($catalog->isSummonAction('Guardian'))->toBeTrue()
    ->and(fn() => new CounterAttackRule('Guardian')->resolveSkill($catalog))->toThrow(InvalidArgumentException::class)
    ->and(new CounterAttackRule('Riposte')->resolveSkill($catalog)->name)->toBe('Riposte')
    ->and(new CounterAttackRule('Guardian')->resolveSkill(SkillCatalog::fromSkills([
      'Skills/guardian.php' => createCounterTestSkill('Guardian')]))->name)->toBe('Guardian');
});

it('shows a lethal counter and knockout before ending battle, without promoting an unapproved reserve',
  function (bool $activeTime, bool $graphical, bool $reduced, bool $partyResponds) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
    $audio = $this->createMock(AudioManager::class);
    $base = createTargetExecutionFixture($activeTime, $graphical, $audio);
    [, $baseContext, , $actor, $ally, $enemies] = $base;
    $third = new Character('Third', 0, new Stats(currentHp: 0, totalHp: 100));
    $reserve = new Character('Reserve', 0, new Stats(currentHp: 100, totalHp: 100),
      counterAttack: new CounterAttackRule('Riposte'));
    $baseContext->party->addMember($third);
    $baseContext->party->addMember($reserve);
    $fixture = [...createQueuedBattleFixture($activeTime, $graphical, $audio, $baseContext->party, $baseContext->troop),
      $actor, $ally, $enemies];
    [$engine, $context, $screen] = $fixture;
    $enemies[1]->stats->currentHp = 0;
    $attacker = $partyResponds ? $enemies[0] : $actor;
    $recipient = $partyResponds ? $actor : $enemies[0];
    new ReflectionProperty($recipient, 'counterAttack')->setValue($recipient, new CounterAttackRule('Riposte'));
    new ReflectionProperty(ActionExecutionState::class, 'counterAttacks')->setValue($engine->actionExecutionState,
      new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill(damage: 200)])));
    $action = new SkillBattleAction(new BasicSkill('Strike', '', '', 0, 0,
      invocation: new SkillInvocation(accuracy: 100), effects: [new HPDamageSkillEffect('1', variance: 0)]));
    $turn = queueTargetExecution($fixture, $action, [$recipient], $attacker);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / 60);
    $koPresented = $responseReturned = false;
    for ($tick = 0; $tick < 900 && $context->getCurrentTurn() !== null; $tick++) {
      $playback = $screen->fieldWindow->getCommandPlayback();
      if ($playback?->actor === $recipient && $playback->phase === 'reaction') {
        $koPresented = true;
        expect($attacker->isKnockedOut)->toBeTrue()
          ->and($playback->getPoseRole($attacker))->toBe(BattlePoseRole::KNOCKOUT)
          ->and($context->getCurrentTurn())->toBe($turn)
          ->and($screen->fieldWindow->results)->toHaveCount(2);
      }
      if ($playback?->actor === $recipient && $playback->phase === 'return') { $responseReturned = true; }
      $engine->actionExecutionState->update($context);
    }
    expect($koPresented)->toBeTrue()->and($responseReturned)->toBeTrue()
      ->and($context->getCurrentTurn())->toBeNull()
      ->and($context->partyRoster->battlers)->not->toContain($reserve)
      ->and($reserve->stats->currentHp)->toBe(100);
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('does not retaliate after cancellation and does not execute an interrupted response',
  function (bool $activeTime, bool $graphical, bool $cancelResponse) {
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $recipient = $enemies[0];
    new ReflectionProperty($recipient, 'counterAttack')->setValue($recipient, new CounterAttackRule('Riposte'));
    new ReflectionProperty(ActionExecutionState::class, 'counterAttacks')->setValue($engine->actionExecutionState,
      new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill()])));
    $hp = $actor->stats->currentHp;
    $mp = $recipient->stats->currentMp;
    $action = new SkillBattleAction(new BasicSkill('Strike', '', '', 0, 0,
      invocation: new SkillInvocation(accuracy: 100), effects: [new HPDamageSkillEffect('1', variance: 0)]));
    queueTargetExecution($fixture, $action, [$recipient]);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / 60);
    $cancelled = false;
    for ($tick = 0; $tick < 900 && $context->getCurrentTurn() !== null; $tick++) {
      $playback = $screen->fieldWindow->getCommandPlayback();
      if (!$cancelled && $playback !== null
        && ($cancelResponse ? $playback->actor === $recipient : $playback->actor === $actor && $playback->phase === 'reaction')) {
        $playback->cancel();
        $cancelled = true;
      }
      $engine->actionExecutionState->update($context);
    }
    expect($cancelled)->toBeTrue()->and($actor->stats->currentHp)->toBe($hp)
      ->and($recipient->stats->currentMp)->toBe($mp)
      ->and($screen->fieldWindow->results)->toHaveCount(1)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and(new ReflectionProperty(ActionExecutionState::class, 'counterQueue')->getValue($engine->actionExecutionState))->toBe([]);
  })->with([false, true])->with([false, true])->with([false, true]);

it('deduplicates recipients and rechecks queued counters after lethal damage, grant removal, blocking and costs', function (string $change) {
  $fixture = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
  [, $context, , $actor, , $enemies] = $fixture;
  foreach ($enemies as $enemy) {
    $enemy->addState(new State('counter', 'Counter', counterAttack: new CounterAttackRule('Riposte')));
  }
  $action = new SkillBattleAction(new BasicSkill('Sweep', '', '', 0, 0,
    scope: new ItemScope(number: ItemScopeNumber::ALL), invocation: new SkillInvocation(accuracy: 100, repeat: 2),
    effects: [new HPDamageSkillEffect('1', variance: 0)]));
  $action->execute($actor, $enemies);
  $resolver = new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill(damage: 200)]));
  $responses = $resolver->resolveResponses($action->lastResult, $actor, [...$enemies, $enemies[0]], [$actor], $enemies);
  expect($responses)->toHaveCount(2)->and($resolver->canExecute($responses[1], [$actor], $enemies))->toBeTrue();
  match ($change) {
    'lethal' => $responses[0]->action->execute($responses[0]->actor, [$actor]),
    'grant' => $enemies[1]->removeState('counter'),
    'block' => $enemies[1]->addState(new State('blocked', 'Blocked', preventsAction: true)),
    'cost' => $enemies[1]->stats->currentMp = 0,
  };
  expect($resolver->canExecute($responses[1], [$actor], $enemies))->toBeFalse();
})->with(['lethal', 'grant', 'block', 'cost']);

it('uses learned-ability grants for basic physical attacks without inferring counters for unrelated outcomes', function () {
  $fixture = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
  [, , , $actor, , $enemies] = $fixture;
  $grant = createCounterTestSkill('Counter training', grant: new CounterAttackRule('Riposte'));
  $actor->learnSkill($grant);
  $resolver = new CounterAttackResolver(SkillCatalog::fromSkills(['Skills/r.php' => createCounterTestSkill()]));
  $attack = new AttackAction('Attack', random: new SeededCombatRandomSource(1));
  $attack->execute($enemies[0], [$actor]);
  expect($resolver->resolveResponses($attack->lastResult, $enemies[0], [$actor], [$actor], $enemies))->toHaveCount(1)
    ->and($resolver->resolveResponses(null, $enemies[0], [$actor], [$actor], $enemies))->toBe([]);
});
