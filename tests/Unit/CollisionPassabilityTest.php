<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Interpreter\MovementRoutePlanner;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\Reachability\MapReachability;
use Ichiloto\Engine\Field\Reachability\ReachabilityEntrance;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblemKind;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

dataset('collision movement contract', array_combine(
  array_column(CollisionType::cases(), 'name'),
  array_map(static fn(CollisionType $type): array => [$type, match ($type) {
    CollisionType::SOLID, CollisionType::NPC, CollisionType::COUNTER => true,
    default => false,
  }], CollisionType::cases()),
));

/** @return array{MapManager, GameScene} */
function createPassabilityField(array $collisions): array
{
  $scene = makeBareScene(GameScene::class);
  $manager = makeBareScene(MapManager::class);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
  new ReflectionProperty(GameScene::class, 'party')->setValue($scene, new Party());
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($manager, $collisions);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $manager);
  $tiles = array_map(static fn(array $row): array => array_fill(0, count($row), '.'), $collisions);
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene,
    new Camera(makeCameraTestScene(), 8, 4, worldSpace: $tiles));
  return [$manager, $scene];
}

it('declares movement blocking for every collision case', function (CollisionType $type, bool $blocks) {
  expect($type->blocksMovement())->toBe($blocks);
})->with('collision movement contract');

it('shares terrain passability between movement reachability and route planning', function (CollisionType $type, bool $blocks) {
  $grid = [[CollisionType::NONE->value, $type->value, CollisionType::NONE->value]];
  [$manager, $scene] = createPassabilityField($grid);
  $collision = CollisionType::SAVE_POINT;
  $report = new MapReachability('synthetic-corridor', $grid)
    ->analyze([new ReachabilityEntrance(0, 0, 'synthetic entrance')]);

  expect($manager->canMoveTo(1, 0, $collision))->toBe(!$blocks)
    ->and($collision)->toBe($type)
    ->and($manager->getCollision(1, 0))->toBe($type)
    ->and($manager->isCounterAt(1, 0))->toBe($type === CollisionType::COUNTER)
    ->and($report->isReachable(1, 0))->toBe(!$blocks)
    ->and($report->isReachable(2, 0))->toBe(!$blocks)
    ->and($report->problems)->toBe([]);

  $plan = fn(): array => MovementRoutePlanner::plan($scene, new Vector2(0, 0), new Vector2(2, 0), false);
  if ($blocks) {
    expect($plan)->toThrow(MovementRouteException::class, 'No walkable route');
  } else {
    expect($plan())->toBe([['direction' => 'right'], ['direction' => 'right']]);
  }
})->with('collision movement contract');

it('keeps live NPC blockers separate from every terrain collision', function (CollisionType $type, bool $blocks) {
  [$manager, $scene] = createPassabilityField([[$type->value]]);
  $npcs = new NpcManager($scene);
  new ReflectionProperty(GameScene::class, 'npcManager')->setValue($scene, $npcs);
  $npcs->configure([['id' => 'synthetic-resident', 'name' => 'Resident', 'x' => 0, 'y' => 0]]);
  $collision = null;
  expect($manager->canMoveTo(0, 0, $collision))->toBeFalse()
    ->and($collision)->toBe(CollisionType::NPC)
    ->and($manager->getCollision(0, 0))->toBe($type);

  $npcs->configure([]);
  expect($manager->canMoveTo(0, 0, $collision))->toBe(!$blocks)
    ->and($collision)->toBe($type);
})->with('collision movement contract');

it('keeps only visible collidable staged actors as movement blockers', function (CollisionType $type, bool $blocks) {
  [$manager, $scene] = createPassabilityField([[$type->value]]);
  $stage = new CinematicStageManager($scene);
  new ReflectionProperty(GameScene::class, 'cinematicStage')->setValue($scene, $stage);
  $stage->add(['id' => 'synthetic-blocker', 'x' => 0, 'y' => 0, 'collision' => true]);
  $stage->add(['id' => 'synthetic-visual', 'x' => 0, 'y' => 0, 'collision' => false]);
  $collision = null;
  expect($manager->canMoveTo(0, 0, $collision))->toBeFalse()
    ->and($collision)->toBe(CollisionType::NPC)
    ->and($manager->getCollision(0, 0))->toBe($type);

  $stage->hide('synthetic-blocker');
  expect($manager->canMoveTo(0, 0, $collision))->toBe(!$blocks)
    ->and($collision)->toBe($type);
  $stage->show('synthetic-blocker');
  expect($manager->canMoveTo(0, 0, $collision))->toBeFalse()
    ->and($collision)->toBe(CollisionType::NPC);
  $stage->clear();
  expect($manager->canMoveTo(0, 0, $collision))->toBe(!$blocks)
    ->and($collision)->toBe($type);
})->with('collision movement contract');

it('preserves fixed wandering and conditional NPC reachability on passable terrain', function (CollisionType $type, array $entry, bool $blocks) {
  $npc = NpcPlacement::fromArray(['name' => 'Synthetic gate', 'x' => 1, 'y' => 0, ...$entry]);
  $report = new MapReachability('synthetic-npc-corridor', [[0, $type->value, 0]], npcs: [$npc])
    ->analyze([new ReachabilityEntrance(0, 0, 'synthetic entrance')]);
  expect($report->isReachable(1, 0))->toBe(!$blocks)
    ->and($report->isReachable(2, 0))->toBe(!$blocks)
    ->and($report->problems)->toBe([]);
})->with([CollisionType::ENCOUNTER, CollisionType::SAVE_POINT, CollisionType::PASS_THROUGH])
  ->with([
    'fixed resident' => [[], true],
    'wanderer' => [['movement' => 'wander'], false],
    'conditional gate' => [['conditions' => [['type' => 'event', 'name' => 'synthetic-gate']]], false],
  ]);

it('preserves movement errors for invalid collision data without replacing the reported type', function (mixed $cell, string $error) {
  [$manager] = createPassabilityField([[$cell]]);
  $collision = CollisionType::ITEM;
  expect(fn() => $manager->getCollision(0, 0))->toThrow($error);
  try {
    $manager->canMoveTo(0, 0, $collision);
    throw new RuntimeException('Invalid collision data was accepted.');
  } catch (Throwable $caught) {
    expect($caught)->toBeInstanceOf($error)
      ->and($collision)->toBe(CollisionType::ITEM);
  }
})->with([
  'unknown integer' => [-1, NotFoundException::class],
  'unknown numeric string' => ['999', NotFoundException::class],
  'non-numeric string' => ['invalid', TypeError::class],
  'array' => [[], TypeError::class],
]);

it('preserves direct reachability tolerance for non-null unknown collision data', function (mixed $cell) {
  $report = new MapReachability('synthetic-unknown-cell', [[0, $cell, 0]])
    ->analyze([new ReachabilityEntrance(0, 0, 'synthetic entrance')]);
  expect($report->isReachable(1, 0))->toBeTrue()
    ->and($report->isReachable(2, 0))->toBeTrue()
    ->and($report->problems)->toBe([]);
})->with([
  'unknown integer' => [-1],
  'numeric string is not a typed blocker' => ['1'],
  'float is not a typed blocker' => [1.0],
  'boolean' => [true],
  'non-numeric string' => ['invalid'],
  'array' => [[]],
]);

it('never floods through null or missing collision cells', function (array $grid) {
  $report = new MapReachability('synthetic-missing-cell', $grid)
    ->analyze([new ReachabilityEntrance(0, 0, 'synthetic entrance')]);
  expect($report->isReachable(0, 0))->toBeTrue()
    ->and($report->isReachable(1, 0))->toBeFalse()
    ->and($report->isReachable(2, 0))->toBeFalse()
    ->and($report->isReachable(0, 1))->toBeFalse()
    ->and($report->isReachable(-1, 0))->toBeFalse()
    ->and($report->problems)->toBe([]);
})->with([
  'null cell' => [[[0, null, 0]]],
  'missing cell' => [[[0 => 0, 2 => 0]]],
  'ragged row' => [[[0], []]],
]);

it('preserves undefined movement bounds and blocked entrance diagnostics', function (array $grid, int $x, int $y) {
  $debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => createTestDirectory('passability-bounds-')]);
  try {
    [$manager, $scene] = createPassabilityField($grid);
    $npcs = new NpcManager($scene);
    new ReflectionProperty(GameScene::class, 'npcManager')->setValue($scene, $npcs);
    $npcs->configure([['name' => 'Outside resident', 'x' => $x, 'y' => $y]]);
    $collision = CollisionType::ITEM;
    expect($manager->canMoveTo($x, $y, $collision))->toBeFalse()
      ->and($collision)->toBe(CollisionType::ITEM)
      ->and(fn() => $manager->getCollision($x, $y))->toThrow(OutOfBounds::class);

    $report = new MapReachability('synthetic-bounds', $grid)
      ->analyze([new ReachabilityEntrance($x, $y, 'synthetic entrance')]);
    expect($report->isReachable(0, 0))->toBeFalse()
      ->and($report->isReachable($x, $y))->toBeFalse()
      ->and(array_column($report->problems, 'kind'))->toBe([ReachabilityProblemKind::BLOCKED_ENTRANCE])
      ->and($report->problems[0]->message)->toContain('outside the map');
  } finally {
    foreach ($debug as $name => $value) {
      new ReflectionProperty(Debug::class, $name)->setValue(null, $value);
    }
  }
})->with([
  'negative coordinate' => [[[0]], -1, 0],
  'outside row' => [[[0]], 0, 1],
  'ragged row' => [[[0, 0], [0]], 1, 1],
  'null cell' => [[[0, null]], 1, 0],
]);
