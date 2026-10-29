<?php

use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\EnemyStore;

beforeEach(function () {
  $this->priorConfig = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $this->priorDebug = new ReflectionClass(Debug::class)->getStaticProperties();
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Twin', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new Vector2(7, 9), 'imagePath' => '', 'rewards' => new BattleRewards(0, 0, [])] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
  }
  $store = new ReflectionClass(EnemyStore::class)->newInstanceWithoutConstructor();
  $store->set('Twin', $enemy);
  ConfigStore::put(EnemyStore::class, $store);
  Debug::configure(['enabled' => false]);
});

afterEach(function () {
  foreach ($this->priorConfig as $key => $value) { new ReflectionProperty(ConfigStore::class, $key)->setValue(null, $value); }
  foreach ($this->priorDebug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('loads independent graphical placements beside unchanged terminal positions for repeated enemies', function () {
  $positions = [[15, 7], [50, 20]];
  $placements = [
    ['x' => 260.5, 'depth' => 300, 'width' => 275, 'height' => 190],
    ['x' => 540, 'depth' => 450.25, 'width' => 275, 'height' => 190],
  ];
  $data = ['name' => 'Renamable group', 'enemies' => array_map(fn($index) => [
    'enemy' => 'Twin', 'position' => $positions[$index], 'graphicalPlacement' => $placements[$index],
  ], [0, 1])];
  $troop = Troop::fromArray($data);
  $members = $troop->members->toArray();
  expect($members[0])->not->toBe($members[1]);
  foreach ($members as $index => $enemy) {
    expect([$enemy->position->x, $enemy->position->y])->toBe(array_map(floatval(...), $positions[$index]))
      ->and($troop->getStagePlace($enemy))->toEqual(BattleStageSlot::fromArray($placements[$index]));
  }
  $troop->members[0] = $members[1];
  $troop->members[1] = $members[0];
  expect($troop->getStagePlace($members[0])->x)->toBe(260.5)
    ->and($troop->getStagePlace($members[1])->x)->toBe(540.0);
  $restored = unserialize(serialize($troop));
  expect($restored->getStagePlace($restored->members[0])->x)->toBe(540.0)
    ->and($restored->getStagePlace($restored->members[1])->x)->toBe(260.5);
});

it('loads logical combat positions independently of both presentation coordinate systems', function () {
  $troop = Troop::fromArray(['name' => 'Logical formation', 'enemies' => [
    ['enemy' => 'Twin', 'position' => [15, 7], 'battlePosition' => ['row' => 'front', 'lane' => 3]],
    ['enemy' => 'Twin', 'position' => [50, 20], 'battlePosition' => ['row' => 'back', 'lane' => 3]],
  ]]);
  $members = $troop->members->toArray();
  expect($troop->getBattlePosition($members[0])->toArray())->toBe(['row' => 'front', 'lane' => 3])
    ->and($troop->getBattlePosition($members[1])->toArray())->toBe(['row' => 'back', 'lane' => 3])
    ->and($troop->getStagePlace($members[0]))->toBeNull();
  $troop->members[0] = $members[1]; $troop->members[1] = $members[0];
  expect($troop->getBattlePosition($members[0])->row)->toBe(\Ichiloto\Engine\Battle\Formation\BattleRow::FRONT);
  $restored = unserialize(serialize($troop));
  expect($restored->getBattlePosition($restored->members[0])->row)->toBe(\Ichiloto\Engine\Battle\Formation\BattleRow::BACK);
  expect(fn() => Troop::fromArray(['name' => 'Overlap', 'enemies' => [
    ['enemy' => 'Twin', 'position' => [15, 7], 'battlePosition' => ['row' => 'front', 'lane' => 0]],
    ['enemy' => 'Twin', 'position' => [50, 20], 'battlePosition' => ['row' => 'front', 'lane' => 0]],
  ]]))->toThrow(InvalidArgumentException::class, 'same logical battle position');
});

it('refuses invalid graphical data with source context while preserving terminal combatants', function (mixed $placement) {
  $troop = Troop::fromArray(['name' => 'Broken art', 'enemies' => [
    ['enemy' => 'Twin', 'position' => [15, 7], 'graphicalPlacement' => $placement],
    ['enemy' => 'Twin', 'position' => [50, 20]],
  ]], 'Data/troops.php troop "Broken art"');
  expect($troop->members)->toHaveCount(2)
    ->and([$troop->members[0]->position->x, $troop->members[0]->position->y])->toBe([15.0, 7.0])
    ->and(fn() => $troop->getStagePlace($troop->members[0]))->toThrow(RuntimeException::class,
      'Data/troops.php troop "Broken art".enemies[0].graphicalPlacement');
  expect(fn() => BattleStageSlot::fromArray($placement, 'authored troop'))->toThrow(InvalidArgumentException::class, 'authored troop');
})->with([
  'null' => [null], 'not a map' => ['invalid'], 'missing' => [['x' => 1]],
  'unknown' => [['x' => 1, 'depth' => 2, 'width' => 3, 'height' => 4, 'left' => 1]],
  'string' => [['x' => '1', 'depth' => 2, 'width' => 3, 'height' => 4]],
  'infinite' => [['x' => INF, 'depth' => 2, 'width' => 3, 'height' => 4]],
  'not finite' => [['x' => NAN, 'depth' => 2, 'width' => 3, 'height' => 4]],
  'negative' => [['x' => -1, 'depth' => 2, 'width' => 3, 'height' => 4]],
  'zero size' => [['x' => 1, 'depth' => 2, 'width' => 0, 'height' => 4]],
  'over limit' => [['x' => 16385, 'depth' => 2, 'width' => 3, 'height' => 4]],
]);

it('does not infer placements from terminal coordinates when optional graphical data is absent', function () {
  $troop = Troop::fromArray(['name' => 'Terminal only', 'enemies' => [['enemy' => 'Twin', 'position' => [15, 7]]]]);
  expect($troop->getStagePlace($troop->members[0]))->toBeNull();
});

it('loads optional authored slot depth without changing terminal positions or old default slots', function () {
  $placement = ['x' => 260, 'depth' => 300, 'width' => 275, 'height' => 190];
  $troop = Troop::fromArray(['name' => 'Depth', 'enemies' => [
    ['enemy' => 'Twin', 'position' => [15, 7], 'graphicalPlacement' => $placement],
    ['enemy' => 'Twin', 'position' => [50, 20], 'graphicalPlacement' => [...$placement, 'displayScale' => 1.05]],
  ]]);
  expect($troop->getStagePlace($troop->members[0])->displayScale)->toBe(1.0)
    ->and($troop->getStagePlace($troop->members[1])->displayScale)->toBe(1.05)
    ->and([$troop->members[0]->position->x, $troop->members[0]->position->y])->toBe([15.0, 7.0])
    ->and([$troop->members[1]->position->x, $troop->members[1]->position->y])->toBe([50.0, 20.0]);
  $restored = unserialize(serialize($troop));
  expect($restored->getStagePlace($restored->members[1])->displayScale)->toBe(1.05);
});

it('refuses malformed optional slot depth with source context', function (mixed $scale) {
  expect(fn() => BattleStageSlot::fromArray(['x' => 260, 'depth' => 300, 'width' => 275, 'height' => 190,
    'displayScale' => $scale], 'synthetic placement'))
    ->toThrow(InvalidArgumentException::class, 'synthetic placement');
})->with([['1.05'], [null], [false], [0.0], [-1.0], [INF], [NAN], [64.1]]);

it('copies authored slots without retaining mutable array references', function () {
  $enemy = ConfigStore::get(EnemyStore::class)->get('Twin');
  $slot = new BattleStageSlot(350, 400, 100, 100);
  $slots = [&$slot];
  $troop = new Troop('Single', [$enemy], graphicalFormation: $slots);
  $slot = new BattleStageSlot(500, 450, 200, 100);
  $slots = [];
  expect($troop->getStagePlace($enemy)->x)->toBe(350.0);
});

it('refuses an over-budget graphical formation without imposing a terminal troop limit', function () {
  $data = ['name' => 'Large terminal troop', 'enemies' => array_fill(0, 65, [
    'enemy' => 'Twin', 'position' => [15, 7],
    'graphicalPlacement' => ['x' => 350, 'depth' => 400, 'width' => 100, 'height' => 100],
  ])];
  $troop = Troop::fromArray($data);
  expect($troop->members)->toHaveCount(65)
    ->and(fn() => $troop->getStagePlace($troop->members[0]))->toThrow(RuntimeException::class,
      'Large terminal troop": graphical formation exceeds 64');
  foreach ($troop->members as $enemy) {
    expect([$enemy->position->x, $enemy->position->y])->toBe([15.0, 7.0]);
  }
});

it('refuses a placement still authored at a fixed canvas height and says how to place it again', function () {
  $troop = Troop::fromArray(['name' => 'Old art', 'enemies' => [
    ['enemy' => 'Twin', 'position' => [15, 7], 'graphicalPlacement' => ['x' => 260, 'y' => 300, 'width' => 275, 'height' => 190]],
  ]], 'Data/troops.php troop "Old art"');
  expect([$troop->members[0]->position->x, $troop->members[0]->position->y])->toBe([15.0, 7.0])
    ->and(fn() => $troop->getStagePlace($troop->members[0]))->toThrow(RuntimeException::class,
      'y is a fixed canvas height; a placement stands at a depth on each arena\'s ground instead');
});
