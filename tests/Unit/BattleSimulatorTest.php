<?php

use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;

/**
 * Builds an enemy without the asset loading a real one does.
 *
 * @param string $name The enemy's name.
 * @param Stats $stats Its stats.
 * @return Enemy The enemy.
 */
function makeSimulationEnemy(string $name, Stats $stats): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();

  foreach (['name' => $name, 'level' => 1, 'stats' => $stats, 'imagePath' => '', 'image' => ['@'],
    'position' => new \Ichiloto\Engine\Core\Vector2(), 'rewards' => new \Ichiloto\Engine\Battle\BattleRewards(0, 0, [])] as $property => $value) {
    new ReflectionProperty(Enemy::class, $property)->setValue($enemy, $value);
  }

  return $enemy;
}

/**
 * Builds a party of one.
 *
 * @param string $name The member's name.
 * @param Stats $stats Their stats.
 * @return Party The party.
 */
function makeSimulationParty(string $name, Stats $stats): Party
{
  $party = new Party();
  $party->addMember(new Character($name, 0, $stats));

  return $party;
}

it('reports a fight the party always wins', function () {
  $party = makeSimulationParty('Champion', new Stats(currentHp: 500, attack: 80, defence: 40, speed: 20, grace: 20));
  $troop = new Troop('Fodder', [makeSimulationEnemy('Rat', new Stats(currentHp: 10, attack: 1, defence: 0, speed: 1))]);

  $report = new BattleSimulator()->simulate($party, $troop, 30);

  expect($report->runs)->toBe(30)
    ->and($report->victories)->toBe(30)
    ->and($report->defeats)->toBe(0)
    ->and($report->winRate())->toBe(1.0)
    ->and($report->verdict())->toBe('trivial');
});

it('reports a fight the party always loses', function () {
  $party = makeSimulationParty('Novice', new Stats(currentHp: 10, attack: 1, defence: 0, speed: 1, grace: 0));
  $troop = new Troop('Dragon', [makeSimulationEnemy('Dragon', new Stats(currentHp: 900, attack: 90, defence: 60, speed: 30, grace: 30))]);

  $report = new BattleSimulator()->simulate($party, $troop, 20);

  expect($report->defeats)->toBe(20)
    ->and($report->winRate())->toBe(0.0)
    ->and($report->verdict())->toContain('wall')
    ->and($report->deaths['Novice'])->toBe(20);
});

it('uses the same default wipeout and explicit reserve rule across repeated simulations', function () {
  $party = new Party();
  for ($index = 0; $index < 3; $index++) {
    $party->addMember(new Character('Novice' . $index, 1, new Stats(currentHp: 1, attack: 0, defence: 0, speed: 1)));
  }
  $reserve = new Character('Strong reserve', 1, new Stats(currentHp: 1000, attack: 1000, defence: 1000, speed: 1000));
  $party->addMember($reserve);
  $troop = new Troop('Synthetic threat', [makeSimulationEnemy('Threat', new Stats(currentHp: 100, attack: 100, speed: 100))]);
  $default = new BattleSimulator()->simulate($party, $troop, 3);
  $optedIn = new BattleSimulator()->simulate($party, $troop, 3, ['reservePolicy' => 'replace_after_wipeout']);
  expect($default->defeats)->toBe(3)->and($default->damageDealt)->not->toHaveKey('Strong reserve')
    ->and($optedIn->victories)->toBe(3)->and($reserve->stats->currentHp)->toBe(1000)
    ->and($party->battlers->toArray())->toBe(array_slice($party->members->toArray(), 0, 3));
});

it('calls a fight neither side can finish a stalemate', function () {
  // Nobody can hurt anybody: the fight would run forever in a real battle,
  // which is worth knowing before a player finds out.
  $stats = fn(): Stats => new Stats(currentHp: 300, attack: 0, defence: 999, speed: 5, grace: 5);
  $party = makeSimulationParty('Wall', $stats());
  $troop = new Troop('Other Wall', [makeSimulationEnemy('Wall', $stats())]);

  $report = new BattleSimulator(turnLimit: 12)->simulate($party, $troop, 5);

  expect($report->stalemates)->toBe(5)
    ->and($report->averageTurns)->toBe(12.0)
    ->and($report->verdict())->toContain('slog');
});

it('leaves the party as it found it', function () {
  $party = makeSimulationParty('Hero', new Stats(currentHp: 120, currentMp: 30, attack: 15, defence: 5, speed: 6, grace: 5));
  $troop = new Troop('Rats', [makeSimulationEnemy('Rat', new Stats(currentHp: 30, attack: 6, defence: 2, speed: 4))]);

  new BattleSimulator()->simulate($party, $troop, 25);

  $hero = $party->battlers->toArray()[0];

  // The party is the project's, not the simulation's, and a run must not
  // leave it beaten up.
  expect($hero->stats->currentHp)->toBe(120)
    ->and($hero->stats->currentMp)->toBe(30);
});

it('fights every run from full health', function () {
  $party = makeSimulationParty('Hero', new Stats(currentHp: 100, attack: 12, defence: 4, speed: 6, grace: 5));
  $troop = new Troop('Rats', [makeSimulationEnemy('Rat', new Stats(currentHp: 25, attack: 5, defence: 1, speed: 3))]);

  $first = new BattleSimulator()->simulate($party, $troop, 40);

  // Without a reset between runs the party would arrive at later battles half
  // dead, and the win rate would slide with every run.
  expect($first->victories)->toBe(40);
});

it('reports what each member contributed', function () {
  $party = new Party();
  $party->addMember(new Character('Striker', 0, new Stats(currentHp: 150, attack: 40, defence: 10, speed: 10, grace: 10)));
  $party->addMember(new Character('Weakling', 0, new Stats(currentHp: 150, attack: 2, defence: 10, speed: 9, grace: 10)));

  $troop = new Troop('Sack', [makeSimulationEnemy('Sack', new Stats(currentHp: 400, attack: 1, defence: 0, speed: 1))]);

  $report = new BattleSimulator()->simulate($party, $troop, 20);

  expect($report->damageDealt)->toHaveKeys(['Striker', 'Weakling'])
    ->and($report->damageDealt['Striker'])->toBeGreaterThan($report->damageDealt['Weakling']);
});

it('describes a fight worth having as a real fight', function () {
  $report = new ReflectionClass(Ichiloto\Engine\Battle\Simulation\SimulationReport::class)
    ->newInstance('Bandits', 100, 75, 25, 0, 8.0, 0.4);

  expect($report->winRate())->toBe(0.75)
    ->and($report->verdict())->toBe('a real fight');
});

it('runs at least one battle however few it is asked for', function () {
  $party = makeSimulationParty('Hero', new Stats(currentHp: 80, attack: 10, defence: 3, speed: 5, grace: 5));
  $troop = new Troop('Rat', [makeSimulationEnemy('Rat', new Stats(currentHp: 20, attack: 4, defence: 1, speed: 3))]);

  expect(new BattleSimulator()->simulate($party, $troop, 0)->runs)->toBe(1);
});

it('uses the live resolver and final staged stat values for seeded previews', function () {
  $actor = new Character('Actor', 0, new Stats(currentHp: 100, attack: 900, defence: 10, speed: 8, grace: 4));
  $actor->addStatStage('attack', 2);
  $liveTarget = makeSimulationEnemy('Target', new Stats(currentHp: 500, attack: 5, defence: 20, speed: 3, grace: 2));
  $simulationTarget = makeSimulationEnemy('Target', new Stats(currentHp: 500, attack: 5, defence: 20, speed: 3, grace: 2));

  $live = new AttackAction('Attack', new CombatResolver(), new SeededCombatRandomSource(77));
  $live->execute($actor, [$liveTarget]);
  $preview = new BattleSimulator(seed: 77)->previewAttack($actor, $simulationTarget);

  expect($live->lastResult?->targets[0]->hits[0]->rawMagnitude)->toBe($preview->targets[0]->hits[0]->rawMagnitude)
    ->and($live->lastResult?->targets[0]->actualHpLost())->toBe($preview->targets[0]->actualHpLost())
    ->and($liveTarget->stats->currentHp)->toBe($simulationTarget->stats->currentHp);
});

it('isolates every run and exceptions from caller afflictions, stages, guarding and resources', function (bool $fail) {
  $party = makeSimulationParty('Hero', new Stats(currentHp: 80, currentMp: 7, attack: 12));
  $enemy = makeSimulationEnemy('Enemy', new Stats(currentHp: 90, currentMp: 8, attack: 10));
  $hero = $party->battlers->toArray()[0];
  $state = new \Ichiloto\Engine\Entities\States\State('poison', 'Poison', durationTurns: 4, tickFormula: '-3');
  foreach ([$hero, $enemy] as $battler) {
    $battler->addState($state);
    $battler->addStatStage('attack', 2);
    $battler->beginGuarding();
  }
  $before = [serialize($hero), serialize($enemy)];
  $simulator = new class($fail) extends BattleSimulator {
    public int $checkedRuns = 0;
    public function __construct(private bool $fail) { parent::__construct(); }
    protected function fight(\Ichiloto\Engine\Battle\BattlePartyRoster $roster, array $enemies,
      array &$damage, array &$hpLoss, array &$healing, array &$mitigation,
      SeededCombatRandomSource $random): array
    {
      foreach ([...$roster->battlers, ...$enemies] as $battler) {
        expect($battler->states[0]->remainingTurns)->toBe(4)
          ->and($battler->getStatStage('attack'))->toBe(2)->and($battler->isGuarding)->toBeTrue();
        $battler->tickStates();
        $battler->stats->currentMp = 0;
        $battler->resetStatStages();
        $battler->stopGuarding();
        $battler->lastHitWasCritical = true;
      }
      $this->checkedRuns++;
      if ($this->fail) { throw new RuntimeException('Synthetic interruption'); }
      return ['result' => 'stalemate', 'turns' => 1];
    }
  };
  $run = fn() => $simulator->simulate($party, new Troop('Synthetic', [$enemy]), 3);
  if ($fail) { expect($run)->toThrow(RuntimeException::class, 'Synthetic interruption'); }
  else { expect($run()->stalemates)->toBe(3); }
  expect($simulator->checkedRuns)->toBe($fail ? 1 : 3)
    ->and([serialize($hero), serialize($enemy)])->toBe($before)
    ->and($hero->states[0]->state)->toBe($state)->and($enemy->states[0]->state)->toBe($state);
})->with([false, true]);
