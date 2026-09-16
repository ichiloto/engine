<?php

use Ichiloto\Engine\Battle\EncounterAdvantage;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleLoader;

class OpeningTestRandom implements CombatRandomSource
{
  public int $calls = 0;
  public function __construct(private int $roll) {}
  public function nextInt(int $minimum, int $maximum): int
  {
    $this->calls++;
    return $this->roll;
  }
}

function openingTestBattle(array $speeds = [12, 7, 7], array $settings = ['firstStrike' => 'normal'], int $seed = 17): array
{
  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  $party = new Party();
  foreach ($speeds as $index => $speed) {
    $party->addMember(new Character('Actor' . $index, 1, new Stats(currentHp: 100, speed: $speed)));
  }
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();
  new ReflectionProperty($enemy, 'name')->setValue($enemy, 'Enemy');
  new ReflectionProperty($enemy, 'stats')->setValue($enemy, new Stats(currentHp: 100, speed: 99));
  $troop = new Troop('Opening test', [$enemy]);
  $ui = (new ReflectionClass(BattleScreen::class))->newInstanceWithoutConstructor();
  $context = new TurnStateExecutionContext($game, $party, $troop, $ui, []);
  $engine = new class($game, new SeededCombatRandomSource($seed)) extends ActiveTimeBattleEngine {
    public function resetOpening(TurnStateExecutionContext $context): void { $this->resetBattleState($context); }
    public function spent(CharacterInterface $actor): void { $this->resetGauge($actor); }
    public function fillRate(CharacterInterface $actor): float { return $this->getFillRate($actor); }
    public function gauge(CharacterInterface $actor): float { return $this->gaugeValues[spl_object_id($actor)]; }
    public function setGauge(CharacterInterface $actor, float $value): void { $this->gaugeValues[spl_object_id($actor)] = $value; }
  };
  $engine->configure(new ActiveTimeBattleConfig($party, $troop, $ui, settings: $settings));
  $engine->resetOpening($context);
  return [$engine, $context, $party->battlers->toArray(), $enemy];
}

beforeEach(function () {
  $this->priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
});

afterEach(function () {
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->priorDelta);
});

it('resolves the normal preemptive and ambush intervals with exactly one roll', function () {
  $counts = ['normal' => 0, 'party' => 0, 'troop' => 0];
  foreach (range(1, 100) as $roll) {
    $random = new OpeningTestRandom($roll);
    $counts[EncounterAdvantage::forBattle([], $random)->value]++;
    expect($random->calls)->toBe(1);
  }
  expect($counts)->toBe(['normal' => 86, 'party' => 8, 'troop' => 6]);
});

it('honors explicit opening overrides without a roll', function (mixed $value, EncounterAdvantage $expected) {
  $random = new OpeningTestRandom(1);
  expect(EncounterAdvantage::forBattle(['firstStrike' => $value], $random))->toBe($expected)
    ->and($random->calls)->toBe(0);
})->with([
  ['party', EncounterAdvantage::PARTY], ['troop', EncounterAdvantage::TROOP],
  ['normal', EncounterAdvantage::NORMAL], [null, EncounterAdvantage::NORMAL],
]);

it('rejects invalid opening data rather than silently rerolling', function () {
  foreach (['typo', '', false, [], 1] as $value) {
    expect(fn() => EncounterAdvantage::fromSetting($value))->toThrow(InvalidArgumentException::class);
  }
  foreach ([[-1, 6], [8, -1], [80, 30]] as [$party, $troop]) {
    expect(fn() => EncounterAdvantage::forBattle([], new OpeningTestRandom(1), $party, $troop))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('normalizes shared opening settings with legacy project compatibility', function () {
  $data = ['title' => 'Test', 'currency' => [], 'startingPositions' => ['player' => []]];
  $legacy = ['activeTime' => ['surpriseAttackChancePercent' => 12, 'backAttackChancePercent' => 3]];
  $system = SystemData::fromArray($data + ['battle' => $legacy]);
  expect((array) $system->getOpeningSettings())->toBe(['preemptiveChancePercent' => 12, 'ambushChancePercent' => 3]);
  $system = SystemData::fromArray($data + ['battle' => $legacy + [
    'opening' => ['preemptiveChancePercent' => 20, 'ambushChancePercent' => 0],
  ]]);
  expect((array) $system->getOpeningSettings())->toBe(['preemptiveChancePercent' => 20, 'ambushChancePercent' => 0])
    ->and($system->getActiveTimeSettings()->surpriseAttackChancePercent)->toBe(20)
    ->and($system->getActiveTimeSettings()->openingVariance)->toBe(70)
    ->and($system->getActiveTimeSettings()->openingSpeedFactorPercent)->toBe(50);
  expect(EncounterAdvantage::forBattle(['opening' => (array) $system->getOpeningSettings()], new OpeningTestRandom(20)))
    ->toBe(EncounterAdvantage::PARTY);
});

it('uses the shared configured chances in the actual ATB opening', function (array $chances, string $message) {
  [$engine] = openingTestBattle(settings: ['opening' => $chances]);
  expect($engine->consumeEncounterAlert())->toBe($message);
})->with([
  [['preemptiveChancePercent' => 100, 'ambushChancePercent' => 0], 'Preemptive strike! The party moves first.'],
  [['preemptiveChancePercent' => 0, 'ambushChancePercent' => 100], 'Ambushed! The enemy strikes first.'],
]);

it('preserves runtime chance overrides over project settings including legacy callers', function () {
  $root = sys_get_temp_dir() . '/ichiloto-opening-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $data = ['title' => 'Test', 'currency' => [], 'startingPositions' => ['player' => []],
    'battle' => ['opening' => ['preemptiveChancePercent' => 8, 'ambushChancePercent' => 6]]];
  file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export($data, true) . ';');
  $cwd = getcwd();
  chdir($root);
  try {
    // newConfig reads project data but does not launch the game or use the loader's Game.
    $loader = (new ReflectionClass(BattleLoader::class))->newInstanceWithoutConstructor();
    $party = new Party();
    $troop = new Troop('Test');
    $legacy = ['activeTime' => ['surpriseAttackChancePercent' => 12, 'backAttackChancePercent' => 3]];
    expect($loader->newConfig($party, $troop, [], $legacy)->settings['opening'])
      ->toBe(['preemptiveChancePercent' => 12, 'ambushChancePercent' => 3]);
    $config = $loader->newConfig($party, $troop, [], $legacy + [
      'opening' => ['preemptiveChancePercent' => 20], 'firstStrike' => null,
    ]);
    expect($config->settings['opening'])->toBe(['preemptiveChancePercent' => 20, 'ambushChancePercent' => 3])
      ->and(EncounterAdvantage::forBattle($config->settings, new OpeningTestRandom(1)))
      ->toBe(EncounterAdvantage::NORMAL);
  } finally {
    chdir($cwd);
    unlink($root . '/assets/Data/system.php');
    rmdir($root . '/assets/Data'); rmdir($root . '/assets'); rmdir($root);
  }
});

it('gives every favored battler an opening turn before the surprised side', function (string $opening) {
  [$engine, $context, $party, $enemy] = openingTestBattle(settings: ['firstStrike' => $opening]);
  $favored = $opening === 'party' ? $party : [$enemy];
  $surprised = $opening === 'party' ? [$enemy] : $party;
  foreach ($favored as $actor) { expect($engine->gauge($actor))->toBe(100.0); }
  foreach ($surprised as $actor) { expect($engine->gauge($actor))->toBe(0.0); }
  // Even a delayed frame cannot let newly filled gauges overtake the opening queue.
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->progressGauges($context);
  foreach ($favored as $_) { expect($engine->claimNextReadyBattler())->toBeIn($favored); }
  expect($engine->claimNextReadyBattler())->toBeIn($surprised);
  expect($engine->consumeEncounterAlert())->not->toBeNull()
    ->and($engine->consumeEncounterAlert())->toBeNull();
})->with(['party', 'troop']);

it('keeps normal opening gauges bounded and varied even at maximum speed', function () {
  [$engine, $context, $party] = openingTestBattle([9999, 9999, 9999]);
  $observed = [];
  foreach (range(1, 100) as $_) {
    $engine->resetOpening($context);
    foreach ($party as $actor) {
      $gauge = $engine->gauge($actor);
      expect($gauge)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(90);
      $observed[] = $gauge;
    }
    expect($engine->claimNextReadyBattler())->toBeNull();
  }
  expect(count(array_unique($observed)))->toBeGreaterThan(100);
});

it('allows every party member to lead while retaining a speed advantage', function () {
  [$engine, $context, $party, $enemy] = openingTestBattle(seed: 16092026);
  $enemy->stats->currentHp = 0;
  $counts = array_fill_keys(array_map(fn($actor) => $actor->name, $party), 0);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  foreach (range(1, 1000) as $_) {
    $engine->resetOpening($context);
    $engine->progressGauges($context);
    $counts[$engine->claimNextReadyBattler()->name]++;
  }
  expect($counts['Actor0'])->toBeGreaterThan($counts['Actor1'])->toBeGreaterThan($counts['Actor2'])
    ->toBeLessThan(700);
  expect(min($counts))->toBeGreaterThan(100);
});

it('orders readiness by threshold crossing rather than capped gauge or frame size', function () {
  $orders = [];
  foreach ([0.01, 10.0] as $delta) {
    [$engine, $context, $party, $enemy] = openingTestBattle([99, 7, 7]);
    $enemy->stats->currentHp = 0;
    $engine->setGauge($party[0], 0.0);
    $engine->setGauge($party[1], 90.0);
    $engine->setGauge($party[2], 80.0);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $delta);
    // Readiness is retained while commands execute; drain only after all have filled.
    for ($elapsed = 0.0; $elapsed < 2.0; $elapsed += $delta) { $engine->progressGauges($context); }
    $order = [];
    while ($actor = $engine->claimNextReadyBattler()) { $order[] = $actor->name; }
    $orders[] = $order;
  }
  expect($orders)->toBe([['Actor1', 'Actor2', 'Actor0'], ['Actor1', 'Actor2', 'Actor0']]);
});

it('resets spent gauges to zero and preserves speed based refill without rerolling the opener', function () {
  [$engine, $context, $party] = openingTestBattle(settings: ['firstStrike' => 'party']);
  $actor = $engine->claimNextReadyBattler();
  $engine->spent($actor);
  expect($engine->gauge($actor))->toBe(0.0)
    ->and($engine->fillRate($party[0]))->toBe(47.0)
    ->and($engine->fillRate($party[1]))->toBe(42.0);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.5);
  $engine->progressGauges($context);
  expect($engine->gauge($actor))->toBe($engine->fillRate($actor) * 0.5);
});

it('applies battle speed stages to both sides and never queues knocked out actors', function () {
  [$engine, $context, $party, $enemy] = openingTestBattle([12, 7, 7]);
  $party[0]->setStatStage('speed', 1);
  $enemy->setStatStage('speed', -1);
  expect($engine->fillRate($party[0]))->toBeGreaterThan(47.0)
    ->and($engine->fillRate($enemy))->toBeLessThan(134.0);
  $party[1]->stats->currentHp = 0;
  $engine->resetOpening($context);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->progressGauges($context);
  while ($actor = $engine->claimNextReadyBattler()) { expect($actor)->not->toBe($party[1]); }
  expect($engine->gauge($party[1]))->toBe(0.0);
});

it('clears the prior opening and readiness state when a battle stops', function () {
  [$engine, $context, $party] = openingTestBattle(settings: ['firstStrike' => 'party']);
  $engine->stop();
  expect($engine->claimNextReadyBattler())->toBeNull()
    ->and($engine->consumeEncounterAlert())->toBeNull()
    ->and($engine->getGaugePercentage($party[0]))->toBe(0.0);
  $engine->configure(new ActiveTimeBattleConfig($context->party, $context->troop, $context->ui, settings: ['firstStrike' => 'normal']));
  $engine->resetOpening($context);
  expect($engine->claimNextReadyBattler())->toBeNull()
    ->and($engine->consumeEncounterAlert())->toBeNull();
});
