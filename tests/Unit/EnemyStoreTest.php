<?php

use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;

beforeEach(function () {
  $this->enemyStoreConfigs = (new ReflectionProperty(ConfigStore::class, 'store'))->getValue();
  $this->enemyStoreTroopCount = (new ReflectionProperty(Troop::class, 'count'))->getValue();
});

afterEach(function () {
  (new ReflectionProperty(ConfigStore::class, 'store'))->setValue(null, $this->enemyStoreConfigs);
  (new ReflectionProperty(Troop::class, 'count'))->setValue(null, $this->enemyStoreTroopCount);
});

function createStoreTestEnemy(string $name = 'Synthetic Enemy'): Enemy
{
  // The store tests exercise real Enemy cloning without its unrelated artwork loader.
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();
  foreach ([
    'name' => $name,
    'level' => 1,
    'stats' => new Stats(),
    'imagePath' => '',
    'image' => ['E'],
    'rewards' => new BattleRewards(0, 0, []),
    'position' => new Vector2(7, 9),
  ] as $property => $value) {
    (new ReflectionProperty(Enemy::class, $property))->setValue($enemy, $value);
  }
  return $enemy;
}

function createStoreTestRegistry(): EnemyStore
{
  return (new ReflectionClass(EnemyStore::class))->newInstanceWithoutConstructor();
}

it('returns null for a missing enemy without attempting to clone null', function () {
  $store = createStoreTestRegistry();
  expect($store->get('missing'))->toBeNull()
    ->and($store->has('missing'))->toBeFalse()
    ->and($store->all())->toBe([]);
});

it('retains null behavior for defaults that are not enemies', function (mixed $default) {
  $store = createStoreTestRegistry();
  expect($store->get('missing', $default))->toBeNull()
    ->and($store->has('missing'))->toBeFalse();
})->with([
  'null' => [null],
  'string' => ['Synthetic Enemy'],
  'integer' => [1],
  'array' => [[]],
  'object' => [new stdClass()],
]);

it('returns independent clones of an enemy default without registering it', function () {
  $store = createStoreTestRegistry();
  $default = createStoreTestEnemy('Fallback');
  $first = $store->get('missing', $default);
  $second = $store->get('missing', $default);
  expect($first)->toBeInstanceOf(Enemy::class)->not->toBe($default)->not->toBe($second)
    ->and($first->position)->not->toBe($default->position)->not->toBe($second->position)
    ->and($first->stats)->not->toBe($default->stats)->not->toBe($second->stats)
    ->and($first->rewards)->not->toBe($default->rewards)->not->toBe($second->rewards);
  $first->stats->currentHp = 1;
  $first->position->x = 99;
  expect($default->stats->currentHp)->toBe(100)
    ->and($second->stats->currentHp)->toBe(100)
    ->and($default->position->x)->toBe(7.0)
    ->and($second->position->x)->toBe(7.0)
    ->and($store->all())->toBe([]);
});

it('clones stored enemies independently and uses a hit before any default', function () {
  $store = createStoreTestRegistry();
  $prototype = createStoreTestEnemy();
  $store->set('known', $prototype);
  $first = $store->get('known', createStoreTestEnemy('Fallback'));
  $second = $store->get('known', 'invalid default');
  expect($first->name)->toBe('Synthetic Enemy')
    ->and($first)->not->toBe($prototype)->not->toBe($second)
    ->and($first->position)->not->toBe($prototype->position)->not->toBe($second->position)
    ->and($first->stats)->not->toBe($prototype->stats)->not->toBe($second->stats)
    ->and($first->rewards)->not->toBe($prototype->rewards)->not->toBe($second->rewards);
  $first->position->y = 99;
  $first->stats->currentHp = 1;
  expect($second->position->y)->toBe(9.0)
    ->and($prototype->position->y)->toBe(9.0)
    ->and($second->stats->currentHp)->toBe(100)
    ->and($prototype->stats->currentHp)->toBe(100)
    ->and($store->has('known'))->toBeTrue()
    ->and($store->all()['known'])->toBe($prototype);
});

it('skips missing encounter entries and gives repeated enemies independent positions and resources', function (bool $useTroopLoader) {
  $store = createStoreTestRegistry();
  $prototype = createStoreTestEnemy();
  $store->set('known', $prototype);
  ConfigStore::put(EnemyStore::class, $store);
  $entries = [
    ['enemy' => 'missing', 'position' => [1, 2]],
    ['enemy' => 'known', 'position' => [3, 4]],
    ['enemy' => 'known', 'position' => [5, 6]],
  ];
  $loaded = $useTroopLoader
    ? Troop::fromArray(['name' => 'Synthetic Encounter', 'enemies' => $entries])->members->toArray()
    : $store->load($entries);
  expect($loaded)->toHaveCount(2)
    ->and($loaded[0])->not->toBe($loaded[1])
    ->and($loaded[0]->position->x)->toBe(3.0)
    ->and($loaded[0]->position->y)->toBe(4.0)
    ->and($loaded[1]->position->x)->toBe(5.0)
    ->and($loaded[1]->position->y)->toBe(6.0);
  $loaded[0]->stats->currentHp = 1;
  expect($loaded[1]->stats->currentHp)->toBe(100)
    ->and($prototype->stats->currentHp)->toBe(100)
    ->and($prototype->position->x)->toBe(7.0)
    ->and($prototype->position->y)->toBe(9.0);
})->with(['store loader' => [false], 'troop loader' => [true]]);
