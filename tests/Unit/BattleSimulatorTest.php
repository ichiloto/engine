<?php

use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
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

  foreach (['name' => $name, 'level' => 1, 'stats' => $stats, 'imagePath' => '', 'image' => ['@']] as $property => $value) {
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
