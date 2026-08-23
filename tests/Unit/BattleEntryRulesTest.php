<?php

use Ichiloto\Engine\Battle\BattleClassification;
use Ichiloto\Engine\Battle\Entry\BattleEntryActorPredicate;
use Ichiloto\Engine\Battle\Entry\BattleEntryActorPresence;
use Ichiloto\Engine\Battle\Entry\BattleEntryRule;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleCatalog;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleExecutor;
use Ichiloto\Engine\Battle\Entry\BattleEntryRuleRunner;
use Ichiloto\Engine\Battle\Entry\BattleEntryStatStageEffect;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\States\BattleEndState;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;

/** @return array{0: Party, 1: array<string, Character>} */
function battleEntryTestParty(?Character $fourth = null): array
{
  $actors = [
    'actor.alpha' => new Character('Alpha', 0, new Stats(currentHp: 100), actorId: 'actor.alpha'),
    'actor.beta' => new Character('Beta', 0, new Stats(currentHp: 100), actorId: 'actor.beta'),
    'actor.gamma' => new Character('Gamma', 0, new Stats(currentHp: 100), actorId: 'actor.gamma'),
    'actor.delta' => $fourth ?? new Character('Delta', 0, new Stats(currentHp: 100), actorId: 'actor.delta'),
  ];
  $party = new Party();
  foreach ($actors as $actor) {
    $party->addMember($actor);
  }

  return [$party, $actors];
}

/** @return array<string, mixed> */
function battleEntryRule(
  string $id = 'rule.test',
  string $classification = 'ordinary',
  string $actor = 'actor.alpha',
  string $presence = 'active',
  string $stat = 'speed',
  mixed $delta = 1,
  array $conditions = [],
  array $writes = [],
  int $priority = 0,
): array
{
  return [
    'id' => $id,
    'priority' => $priority,
    'classification' => $classification,
    'actors' => [['actor' => $actor, 'presence' => $presence]],
    'conditions' => $conditions,
    'effects' => [[
      'type' => 'stat_stage',
      'actor' => $actor,
      'stat' => $stat,
      'delta' => $delta,
    ]],
    'writes' => $writes,
  ];
}

it('preserves current behaviour when the project has no battle entry rule file', function () {
  [$party, $actors] = battleEntryTestParty();
  $catalog = BattleEntryRuleCatalog::fromProject(sys_get_temp_dir() . '/missing-battle-entry-' . uniqid() . '.php');
  $config = new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.empty');

  (new BattleEntryRuleRunner($catalog))->apply($config, new GameState());

  expect($catalog->rules())->toBe([])
    ->and($config->entryRulesEvaluated())->toBeTrue()
    ->and($actors['actor.alpha']->getStatStage('speed'))->toBe(0);
});

it('runs the Engine-only project fixture for ordinary active and boss reserve entry', function () {
  $fixture = dirname(__DIR__) . '/Fixtures/Projects/BattleEntryRules/assets/Data/battle-entry-rules.php';
  $catalog = BattleEntryRuleCatalog::fromProject($fixture);

  [$ordinaryParty, $ordinaryActors] = battleEntryTestParty();
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($ordinaryParty, new Troop('Ordinary', classification: BattleClassification::ORDINARY)),
    new GameState(),
  );

  [$bossParty, $bossActors] = battleEntryTestParty();
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($bossParty, new Troop('Boss', classification: BattleClassification::BOSS)),
    new GameState(),
  );

  expect($ordinaryActors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($ordinaryActors['actor.delta']->getStatStage('grace'))->toBe(0)
    ->and($bossActors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($bossActors['actor.delta']->getStatStage('grace'))->toBe(-1);
});

it('defaults omitted troop classification to ordinary and propagates typed classifications', function () {
  [$party] = battleEntryTestParty();
  $ordinary = new Troop('Ordinary');
  $boss = new Troop('Boss', classification: BattleClassification::BOSS);

  expect($ordinary->classification)->toBe(BattleClassification::ORDINARY)
    ->and((new BattleConfig($party, $ordinary))->classification)->toBe(BattleClassification::ORDINARY)
    ->and((new BattleConfig($party, $boss))->classification)->toBe(BattleClassification::BOSS);
});

it('hydrates explicit ordinary and boss classification through troop data', function () {
  $store = (new ReflectionClass(EnemyStore::class))->newInstanceWithoutConstructor();
  ConfigStore::put(EnemyStore::class, $store);

  $ordinary = Troop::fromArray(['name' => 'Ordinary', 'enemies' => [], 'classification' => 'ordinary']);
  $boss = Troop::fromArray(['name' => 'Boss', 'enemies' => [], 'classification' => 'boss']);

  expect($ordinary->classification)->toBe(BattleClassification::ORDINARY)
    ->and($boss->classification)->toBe(BattleClassification::BOSS);
});

it('fails closed with the troop source when classification is unsupported', function () {
  expect(fn() => BattleClassification::resolve('elite', 'Data/troops.php troop "Test"'))
    ->toThrow(InvalidArgumentException::class, 'Data/troops.php troop "Test"');
});

it('keeps ordinary-only and boss-only rules in their declared classifications', function () {
  [$ordinaryParty, $ordinaryActors] = battleEntryTestParty();
  [$bossParty, $bossActors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule('rule.ordinary'),
    battleEntryRule('rule.boss', 'boss', stat: 'grace'),
  ]], 'classification fixture');

  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($ordinaryParty, new Troop('Ordinary'), entryExecutionId: 'execution.ordinary'),
    new GameState(),
  );
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig(
      $bossParty,
      new Troop('Boss', classification: BattleClassification::BOSS),
      entryExecutionId: 'execution.boss',
    ),
    new GameState(),
  );

  expect($ordinaryActors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($ordinaryActors['actor.alpha']->getStatStage('grace'))->toBe(0)
    ->and($bossActors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($bossActors['actor.alpha']->getStatStage('grace'))->toBe(1);
});

it('matches active reserve and any against the immutable entry roster', function () {
  [$party, $actors] = battleEntryTestParty();
  $config = new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.roster');
  $context = $config->entryContext(new GameState());

  // Party order changes after capture cannot rewrite the entry facts.
  $party->members[0] = $actors['actor.delta'];

  expect($context->hasActor('actor.alpha', \Ichiloto\Engine\Battle\Entry\BattleEntryActorPresence::ACTIVE))->toBeTrue()
    ->and($context->hasActor('actor.delta', \Ichiloto\Engine\Battle\Entry\BattleEntryActorPresence::RESERVE))->toBeTrue()
    ->and($context->hasActor('actor.delta', \Ichiloto\Engine\Battle\Entry\BattleEntryActorPresence::ACTIVE))->toBeFalse()
    ->and($context->hasActor('actor.delta', \Ichiloto\Engine\Battle\Entry\BattleEntryActorPresence::ANY))->toBeTrue();
});

it('applies Speed and Grace to the correct active and reserve actors', function () {
  [$party, $actors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule('rule.speed', actor: 'actor.alpha', presence: 'active', stat: 'speed', delta: 2),
    battleEntryRule('rule.grace', actor: 'actor.delta', presence: 'reserve', stat: 'grace', delta: -1),
  ]], 'stage fixture');

  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.stages'),
    new GameState(),
  );

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(2)
    ->and($actors['actor.delta']->getStatStage('grace'))->toBe(-1)
    ->and($actors['actor.beta']->getStatStage('speed'))->toBe(0);
});

it('does not apply an active rule to an actor who began in reserve', function () {
  [$party, $actors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(actor: 'actor.delta', presence: 'active'),
  ]], 'reserve predicate fixture');

  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.reserve'),
    new GameState(),
  );

  expect($actors['actor.delta']->getStatStage('speed'))->toBe(0);
});

it('does nothing when an ordinary world condition is false', function () {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(
      conditions: [['type' => 'switch', 'name' => 'entry_ready']],
      writes: [['type' => 'event', 'name' => 'entry_consumed']],
    ),
  ]], 'condition fixture');

  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.condition'),
    $state,
  );

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($state->hasStoryEvent('entry_consumed'))->toBeFalse();
});

it('applies once and commits writes only after the temporary stage exists', function () {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $observedStage = null;
  $state->onChange = function () use (&$observedStage, $actors): void {
    $observedStage = $actors['actor.alpha']->getStatStage('speed');
  };
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(writes: [['type' => 'variable', 'name' => 'queue', 'op' => 'add', 'value' => 1]]),
  ]], 'once fixture');
  $runner = new BattleEntryRuleRunner($catalog);
  $config = new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.once');

  $runner->apply($config, $state);
  $runner->apply($config, $state);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($state->getVariable('queue'))->toBe(1)
    ->and($observedStage)->toBe(1)
    ->and($config->appliedEntryRuleIds)->toBe(['rule.test']);
});

it('runs multiple valid rules by priority then declaration order', function () {
  [$party, $actors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule('rule.later', delta: -2, priority: 20),
    battleEntryRule('rule.first', delta: 1, priority: 10),
    battleEntryRule('rule.same-priority', delta: 1, priority: 20),
  ]], 'ordering fixture');

  expect(array_map(static fn($rule): string => $rule->id, $catalog->rules()))
    ->toBe(['rule.first', 'rule.later', 'rule.same-priority']);

  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.order'),
    new GameState(),
  );

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(0);
});

it('advances a two-step queue by at most one entry-snapshot-eligible rule per battle', function () {
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(
      id: 'rule.step-one',
      stat: 'speed',
      conditions: [['type' => 'variable', 'name' => 'step', 'value' => 1]],
      writes: [['type' => 'variable', 'name' => 'step', 'value' => 2]],
      priority: 10,
    ),
    battleEntryRule(
      id: 'rule.step-two',
      stat: 'grace',
      delta: -1,
      conditions: [['type' => 'variable', 'name' => 'step', 'value' => 2]],
      writes: [['type' => 'variable', 'name' => 'step', 'value' => 'done']],
      priority: 20,
    ),
  ]], 'two-step queue fixture');
  $state = new GameState();
  $state->setVariable('step', 1);
  [$party, $actors] = battleEntryTestParty();

  $firstConfig = new BattleConfig($party, new Troop('First'), entryExecutionId: 'execution.queue.first');
  (new BattleEntryRuleRunner($catalog))->apply($firstConfig, $state);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($actors['actor.alpha']->getStatStage('grace'))->toBe(0)
    ->and($state->getVariable('step'))->toBe(2)
    ->and($firstConfig->appliedEntryRuleIds)->toBe(['rule.step-one']);

  BattleEndState::clearBattleState($party);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(0);

  $secondConfig = new BattleConfig($party, new Troop('Second'), entryExecutionId: 'execution.queue.second');
  (new BattleEntryRuleRunner($catalog))->apply($secondConfig, $state);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($actors['actor.alpha']->getStatStage('grace'))->toBe(-1)
    ->and($state->getVariable('step'))->toBe('done')
    ->and($secondConfig->appliedEntryRuleIds)->toBe(['rule.step-two']);
});

it('runs every rule eligible in the original entry snapshot in deterministic order', function () {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $state->setVariable('step', 1);
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(
      id: 'rule.first',
      conditions: [['type' => 'variable', 'name' => 'step', 'value' => 1]],
      writes: [['type' => 'variable', 'name' => 'step', 'value' => 2]],
      priority: 10,
    ),
    battleEntryRule(
      id: 'rule.second',
      stat: 'grace',
      conditions: [['type' => 'variable', 'name' => 'step', 'value' => 1]],
      writes: [['type' => 'variable', 'name' => 'result', 'value' => 'second']],
      priority: 20,
    ),
  ]], 'shared snapshot fixture');
  $config = new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.snapshot');

  (new BattleEntryRuleRunner($catalog))->apply($config, $state);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($actors['actor.alpha']->getStatStage('grace'))->toBe(1)
    ->and($state->getVariable('step'))->toBe(2)
    ->and($state->getVariable('result'))->toBe('second')
    ->and($config->appliedEntryRuleIds)->toBe(['rule.first', 'rule.second']);
});

it('rejects a manually constructed non-stageable effect before any mutation', function () {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $state->setVariable('step', 1);
  $rule = new BattleEntryRule(
    id: 'rule.manual-invalid',
    priority: 0,
    declarationOrder: 0,
    classification: BattleClassification::ORDINARY,
    actors: [new BattleEntryActorPredicate('actor.alpha', BattleEntryActorPresence::ACTIVE)],
    conditions: [],
    effects: [
      new BattleEntryStatStageEffect('actor.alpha', StatKey::SPEED, 1),
      new BattleEntryStatStageEffect('actor.alpha', StatKey::MAX_HP, 1),
    ],
    writes: [['type' => 'variable', 'name' => 'step', 'value' => 2]],
    source: 'manual effect fixture rule "rule.manual-invalid"',
  );
  $context = (new BattleConfig(
    $party,
    new Troop('Encounter'),
    entryExecutionId: 'execution.manual-invalid',
  ))->entryContext($state);

  expect(fn() => (new BattleEntryRuleExecutor())->apply($rule, $context, $state))
    ->toThrow(RuntimeException::class, 'effects[1].stat')
    ->and($actors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($actors['actor.alpha']->getStatStage('maxHp'))->toBe(0)
    ->and($state->getVariable('step'))->toBe(1);
});

it('reports unknown actors unknown stats and malformed deltas without mutation', function () {
  [, $actors] = battleEntryTestParty();
  $state = new GameState();
  $actorStore = new ActorStore(dirname(__DIR__) . '/Fixtures/Actors');

  expect(fn() => new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(actor: 'actor.missing'),
  ]], 'unknown actor fixture', $actorStore))->toThrow(InvalidArgumentException::class, 'actor.missing')
    ->and(fn() => new BattleEntryRuleCatalog(['rules' => [battleEntryRule(stat: 'luck')]], 'unknown stat fixture'))
    ->toThrow(InvalidArgumentException::class, 'field "stat"')
    ->and(fn() => new BattleEntryRuleCatalog(['rules' => [battleEntryRule(delta: '1')]], 'delta fixture'))
    ->toThrow(InvalidArgumentException::class, 'field "delta"')
    ->and($state->toArray())->toBe((new GameState())->toArray());
});

it('fails atomically when a matched rule targets an actor absent from the entry roster', function () {
  [$party, $actors] = battleEntryTestParty();
  $data = battleEntryRule();
  $data['effects'][] = [
    'type' => 'stat_stage',
    'actor' => 'actor.missing',
    'stat' => 'grace',
    'delta' => 1,
  ];
  $catalog = new BattleEntryRuleCatalog(['rules' => [$data]], 'missing effect actor fixture');

  expect(fn() => (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.missing-effect-actor'),
    new GameState(),
  ))->toThrow(RuntimeException::class, 'actor.missing')
    ->and($actors['actor.alpha']->getStatStage('speed'))->toBe(0);
});

it('rolls back an earlier effect when a later effect fails', function () {
  $throwing = new class('Delta', 0, new Stats(currentHp: 100), actorId: 'actor.delta') extends Character {
    public function addStatStage(string $stat, int $delta): int
    {
      throw new RuntimeException('Synthetic stage failure.');
    }
  };
  [$party, $actors] = battleEntryTestParty($throwing);
  $data = battleEntryRule();
  $data['actors'][] = ['actor' => 'actor.delta', 'presence' => 'reserve'];
  $data['effects'][] = [
    'type' => 'stat_stage',
    'actor' => 'actor.delta',
    'stat' => 'grace',
    'delta' => 1,
  ];
  $catalog = new BattleEntryRuleCatalog(['rules' => [$data]], 'effect rollback fixture');

  expect(fn() => (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.effect-rollback'),
    new GameState(),
  ))->toThrow(RuntimeException::class, 'rolled back')
    ->and($actors['actor.alpha']->getStatStage('speed'))->toBe(0);
});

it('does not reapply an earlier successful rule when a later rule interrupts entry', function () {
  $throwing = new class('Delta', 0, new Stats(currentHp: 100), actorId: 'actor.delta') extends Character {
    public function addStatStage(string $stat, int $delta): int
    {
      throw new RuntimeException('Synthetic stage failure.');
    }
  };
  [$party, $actors] = battleEntryTestParty($throwing);
  $failing = battleEntryRule('rule.failing', actor: 'actor.delta', stat: 'grace', priority: 20);
  $failing['actors'][0]['presence'] = 'reserve';
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule('rule.success', priority: 10),
    $failing,
  ]], 'interrupted entry fixture');
  $runner = new BattleEntryRuleRunner($catalog);
  $config = new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.interrupted');

  foreach ([1, 2] as $_attempt) {
    try {
      $runner->apply($config, new GameState());
    } catch (RuntimeException) {
    }
  }

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($config->appliedEntryRuleIds)->toBe(['rule.success']);
});

it('rolls back temporary effects and earlier writes when a durable write callback fails', function () {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $state->setVariable('queue', 4);
  $state->onChange = static function (): void {
    throw new RuntimeException('Synthetic write failure.');
  };
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(writes: [
      ['type' => 'variable', 'name' => 'queue', 'op' => 'add', 'value' => 1],
      ['type' => 'event', 'name' => 'entry_consumed'],
    ]),
  ]], 'write rollback fixture');

  expect(fn() => (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.write-rollback'),
    $state,
  ))->toThrow(RuntimeException::class, 'rolled back')
    ->and($actors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($state->getVariable('queue'))->toBe(4)
    ->and($state->hasStoryEvent('entry_consumed'))->toBeFalse();
});

it('rejects unsupported transactional writes with rule file and field diagnostics', function () {
  expect(fn() => new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(writes: [['type' => 'quest', 'name' => 'quest.sample']]),
  ]], '/project/assets/Data/battle-entry-rules.php'))
    ->toThrow(InvalidArgumentException::class, 'battle-entry-rules.php rule "rule.test" field "writes"');
});

it('never serializes temporary entry stages and every terminal outcome cleans active and reserve stages', function (string $outcome) {
  [$party, $actors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule('rule.active', actor: 'actor.alpha'),
    battleEntryRule('rule.reserve', actor: 'actor.delta', presence: 'reserve', stat: 'grace'),
  ]], 'cleanup fixture');
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($party, new Troop('Encounter'), entryExecutionId: 'execution.cleanup'),
    new GameState(),
  );

  expect($actors['actor.alpha']->toArray())->not->toHaveKey('statStages')
    ->and($actors['actor.delta']->toArray())->not->toHaveKey('statStages');

  BattleEndState::clearBattleState($party);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(0)
    ->and($actors['actor.delta']->getStatStage('grace'))->toBe(0);
})->with(['victory', 'defeat', 'retreat']);

it('round trips only durable queue state between eligible battles', function () {
  [$firstParty, $firstActors] = battleEntryTestParty();
  $firstState = new GameState();
  $firstState->setSwitch('entry_ready');
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(
      conditions: [['type' => 'switch', 'name' => 'entry_ready']],
      writes: [
        ['type' => 'switch', 'name' => 'entry_ready', 'value' => false],
        ['type' => 'variable', 'name' => 'queue', 'op' => 'add', 'value' => 1],
      ],
    ),
  ]], 'save fixture');
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($firstParty, new Troop('Encounter'), entryExecutionId: 'execution.save.first'),
    $firstState,
  );

  $loadedState = GameState::fromArray($firstState->toArray());
  [$secondParty, $secondActors] = battleEntryTestParty();
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig($secondParty, new Troop('Encounter'), entryExecutionId: 'execution.save.second'),
    $loadedState,
  );

  expect($firstActors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($loadedState->getVariable('queue'))->toBe(1)
    ->and($loadedState->getSwitch('entry_ready'))->toBeFalse()
    ->and($secondActors['actor.alpha']->getStatStage('speed'))->toBe(0);
});

it('uses the same entry evaluator for random and scripted battle configurations', function (string $origin) {
  [$party, $actors] = battleEntryTestParty();
  $state = new GameState();
  $catalog = new BattleEntryRuleCatalog(['rules' => [
    battleEntryRule(writes: [['type' => 'event', 'name' => 'entry_applied']]),
  ]], $origin . ' battle fixture');
  $config = new BattleConfig(
    $party,
    new Troop('Encounter'),
    settings: ['entryOrigin' => $origin],
    entryExecutionId: 'execution.' . $origin,
  );

  (new BattleEntryRuleRunner($catalog))->apply($config, $state);

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1)
    ->and($state->hasStoryEvent('entry_applied'))->toBeTrue();
})->with(['random', 'scripted']);

it('uses the same entry result for active-time and traditional battle settings', function (string $engine) {
  [$party, $actors] = battleEntryTestParty();
  $catalog = new BattleEntryRuleCatalog(['rules' => [battleEntryRule()]], 'engine fixture');
  (new BattleEntryRuleRunner($catalog))->apply(
    new BattleConfig(
      $party,
      new Troop('Encounter'),
      settings: ['engine' => $engine],
      entryExecutionId: 'execution.' . $engine,
    ),
    new GameState(),
  );

  expect($actors['actor.alpha']->getStatStage('speed'))->toBe(1);
})->with(['active-time', 'traditional']);
