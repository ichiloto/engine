<?php

use Ichiloto\Engine\Battle\Engines\BattleEngineContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Clocks\RuntimeClock;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Tests\Support\ManualRuntimeClock;

require_once __DIR__ . '/../Support/ManualRuntimeClock.php';

it('counts only running battle seconds and refreshes or upgrades one live state in a group', function (): void {
  $clock = new ManualRuntimeClock();
  $lease = RuntimeClock::useClock($clock);
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  $engine = new TraditionalTurnBasedBattleEngine($game);
  try {
    $screen = new class extends BattleScreen {
      public function __construct() {}
      public function refreshField(): void {}
    };
    $party = new Party();
    $bearer = new Character('Synthetic combatant', 1, new Stats(currentHp: 100, totalHp: 100, defence: 0, magicDefence: 0));
    $party->addMember($bearer);
    $troop = new Troop('Synthetic example');
    $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen));
    $engine->start();
    $context = new BattleEngineContext($game, $party, $troop, $screen);
    $running = new class($engine) extends TurnState {
      public function update(TurnStateExecutionContext $context): void {}
    };
    $choosing = new class($engine) extends PlayerActionState {
      public function enter(TurnStateExecutionContext $context): void { $this->activeCharacterIndex = 0; }
      public function exit(TurnStateExecutionContext $context): void {}
      public function update(TurnStateExecutionContext $context): void {}
    };
    $engine->setState($running);
    $half = new State('half-shield', 'Half Shield', damageTakenRate: 0.5, durationSeconds: 8,
      stackGroup: 'shield', stackPriority: 1);
    $full = new State('whole-shield', 'Whole Shield', damageTakenRate: 0, durationSeconds: 8,
      stackGroup: 'shield', stackPriority: 2);
    $attacker = new Character('Synthetic attacker', 1, new Stats(currentHp: 100));
    $hit = static function (ResolutionKind $kind, bool $blockable = true) use ($attacker, $bearer): int {
      $bearer->stats->currentHp = 100;
      new CombatResolver()->resolve(new CombatResolutionRequest(
        'test.hit', 'test.hit#1', $attacker, $bearer, 40, $kind,
        baseAccuracy: null, criticalEligible: false, guardEligible: $blockable,
      ));
      return 100 - $bearer->stats->currentHp;
    };
    expect($bearer->addState($half, guaranteed: true))->toBeTrue();
    expect($hit(ResolutionKind::PHYSICAL_DAMAGE))->toBe(20)
      ->and($hit(ResolutionKind::MAGICAL_DAMAGE))->toBe(20)
      ->and($hit(ResolutionKind::MAGICAL_DAMAGE, blockable: false))->toBe(40);
    $clock->seconds += 3;
    $engine->setState($choosing);
    $clock->seconds += 25;
    $engine->run($context);
    expect($bearer->hasState('half-shield'))->toBeTrue();
    $engine->setState($running);
    $clock->seconds += 3;
    $engine->setBattleStateClockSuspended(true);
    $clock->seconds += 25;
    $engine->setBattleStateClockSuspended(false);
    $clock->seconds += 1;
    $engine->run($context);
    expect($bearer->hasState('half-shield'))->toBeTrue();
    expect($bearer->addState($half, guaranteed: true))->toBeTrue()->and($bearer->states)->toHaveCount(1);
    $clock->seconds += 3;
    $engine->run($context);
    expect($bearer->hasState('half-shield'))->toBeTrue();
    expect($bearer->addState($full, guaranteed: true))->toBeTrue();
    expect($bearer->addState($half, guaranteed: true))->toBeFalse();
    expect($bearer->hasState('half-shield'))->toBeFalse()->and($bearer->states)->toHaveCount(1);
    expect($hit(ResolutionKind::PHYSICAL_DAMAGE))->toBe(0)
      ->and($hit(ResolutionKind::MAGICAL_DAMAGE))->toBe(0)
      ->and($hit(ResolutionKind::PHYSICAL_DAMAGE, blockable: false))->toBe(40);
    $bearer->tickStates();
    $clock->seconds += 7.75;
    expect($bearer->hasState('whole-shield'))->toBeTrue()
      ->and(BattlerConditions::getEntries($bearer))->toHaveCount(1);
    $clock->seconds += 0.25;
    expect($bearer->hasState('whole-shield'))->toBeFalse()
      ->and(BattlerConditions::getEntries($bearer))->toBeEmpty();
    expect($hit(ResolutionKind::PHYSICAL_DAMAGE))->toBe(40);
    $engine->run($context);
    expect($bearer->states)->toBeEmpty();
    $bearer->addState($half, guaranteed: true);
    expect($bearer->toArray()['states'])->toBeEmpty();
    $engine->stop();
    expect($bearer->states)->toBeEmpty();
  } finally {
    $lease->close();
  }
});

it('validates authored duration and grouping without changing legacy turn states', function (): void {
  expect(State::fromArray(['id' => 'legacy', 'durationTurns' => 2])->durationTurns)->toBe(2)
    ->and(State::fromArray(['id' => 'timed', 'durationSeconds' => 2.5])->durationSeconds)->toBe(2.5);
  foreach ([
    ['durationSeconds' => 0], ['durationSeconds' => '8'], ['durationSeconds' => INF],
    ['durationSeconds' => 8, 'durationTurns' => 2], ['durationSeconds' => 8, 'fieldTickSteps' => 2],
    ['durationSeconds' => 8, 'persistsAfterBattle' => true], ['stackPriority' => 2],
  ] as $invalid) {
    expect(fn() => State::fromArray(['id' => 'invalid', ...$invalid]))->toThrow(InvalidArgumentException::class);
  }
});

it('releases the configured battle when its screen stops after startup fails', function (bool $activeTime): void {
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  $owner = new class extends BattleScreen {
    public int $released = 0;

    public function __construct() {}

    public function refreshField(): void { throw new RuntimeException('Battle screen unavailable.'); }

    public function releaseActionPrefetch(): void { $this->released++; }
  };
  $staleScreen = new class extends BattleScreen {
    public function __construct() {}
  };
  $party = new Party();
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $party->addMember($actor);
  $troop = new Troop('Synthetic troop');
  $config = $activeTime
    ? new \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig($party, $troop, $owner)
    : new TurnBasedBattleConfig($party, $troop, $owner);
  $engine = $activeTime
    ? new \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine($game)
    : new TraditionalTurnBasedBattleEngine($game);

  $actor->addState(new State('elapsed-test', 'Elapsed', durationSeconds: 8), guaranteed: true);
  $actor->addState(new State('persistent-test', 'Persistent', persistsAfterBattle: true), guaranteed: true);
  $engine->configure($config);
  expect(fn() => $engine->start())->toThrow(RuntimeException::class, 'Battle screen unavailable.');

  $engine->stopForScreen($staleScreen);
  expect($engine->battleConfig)->toBe($config)
    ->and($actor->hasState('elapsed-test'))->toBeTrue()
    ->and($owner->released)->toBe(0);

  $engine->stopForScreen($owner);
  $engine->stopForScreen($owner);
  expect($engine->battleConfig)->toBeNull()
    ->and($actor->hasState('elapsed-test'))->toBeFalse()
    ->and($actor->hasState('persistent-test'))->toBeTrue()
    ->and($owner->released)->toBe(1);
})->with([false, true]);
