<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePoseReach;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlerStrikePoint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-contact-metadata-');
  writeTestPng($this->root . '/Graphics/body.png', 40, 80);
  $this->entry = ['artwork' => ['image' => 'Graphics/body.png'],
    'poses' => ['attack' => ['image' => 'Graphics/body.png', 'loop' => false, 'impactFrame' => 0]]];
});

it('hydrates optional authored reach and contact for actors and enemies without changing the input payload', function () {
  $entry = $this->entry;
  $entry['strikePoint'] = ['x' => .25, 'y' => .6];
  $entry['poses']['attack']['reach'] = ['x' => .75, 'y' => -.4];
  $data = ['actors' => ['actor-id' => $entry], 'enemies' => ['enemy-id' => $entry]];
  $before = $data;
  $bindings = BattlerBindings::getFromArray($data, $this->root);
  $catalog = (new BattlePresentationCatalog([], [], []))->bindBattlers($bindings);
  foreach ([[$bindings->actorPoses, $bindings->actorStrikePoints, 'actor-id'],
    [$catalog->enemyPoses, $catalog->enemyStrikePoints, 'enemy-id']] as [$poses, $points, $id]) {
    expect($poses[$id]->getPose(BattlePoseRole::ATTACK)->reach->toArray())->toBe(['x' => .75, 'y' => -.4])
      ->and($points[$id]->toArray())->toBe(['x' => .25, 'y' => .6]);
  }
  expect($data)->toBe($before)->and($bindings->getIdentities(true))->toBe(['actor-id'])
    ->and($bindings->getIdentities(false))->toBe(['enemy-id']);
});

it('keeps historical omitted fields absent and uses null or empty maps in the typed presentation', function () {
  $data = ['actors' => ['actor-id' => $this->entry]];
  $bindings = BattlerBindings::getFromArray($data, $this->root);
  $catalog = (new BattlePresentationCatalog([], [], []))->bindBattlers($bindings);
  expect(new BattlerPose('Graphics/body.png')->reach)->toBeNull()
    ->and($bindings->actorPoses['actor-id']->getPose(BattlePoseRole::ATTACK)->reach)->toBeNull()
    ->and($bindings->actorStrikePoints)->toBe([])->and($bindings->enemyStrikePoints)->toBe([])
    ->and($catalog->actorStrikePoints)->toBe([])->and($catalog->enemyStrikePoints)->toBe([])
    ->and(array_key_exists('strikePoint', $data['actors']['actor-id']))->toBeFalse()
    ->and(array_key_exists('reach', $data['actors']['actor-id']['poses']['attack']))->toBeFalse();
});

it('round trips typed metadata through its authored value form, preserving explicit zero reach', function () {
  $reach = BattlePoseReach::fromArray(['x' => 0, 'y' => 0]);
  $point = BattlerStrikePoint::fromArray(['x' => 0, 'y' => 1]);
  expect(BattlePoseReach::fromArray($reach->toArray()))->toEqual($reach)
    ->and(BattlerStrikePoint::fromArray($point->toArray()))->toEqual($point)
    ->and($reach->toArray())->toBe(['x' => 0.0, 'y' => 0.0]);
  $entry = $this->entry;
  $entry['strikePoint'] = $point->toArray();
  $entry['poses']['attack']['reach'] = $reach->toArray();
  $bindings = BattlerBindings::getFromArray(['actors' => ['actor-id' => $entry]], $this->root);
  expect($bindings->actorPoses['actor-id']->getPose(BattlePoseRole::ATTACK)->reach)->toEqual($reach)
    ->and($bindings->actorStrikePoints['actor-id'])->toEqual($point);
});

it('scales reach only against the supplied current standing body reference', function () {
  $reach = new BattlePoseReach(.75, -.4);
  $one = $reach->getCanvasOffset(100);
  $two = $reach->getCanvasOffset(200);
  expect([$one->x, $one->y])->toBe([75.0, -40.0])->and([$two->x, $two->y])->toBe([150.0, -80.0]);
  $one->x = 999;
  expect($reach->getCanvasOffset(100)->x)->toBe(75.0)->and($reach->x)->toBe(.75);
});

it('loads contact metadata through the normal catalog while replacement PNG dimensions remain image-owned', function () {
  mkdir($this->root . '/Data/Presentation', 0700, true);
  file_put_contents($this->root . '/' . BattlePresentationCatalog::FILE,
    '<?php return new Ichiloto\\Engine\\Battle\\Presentation\\BattlePresentationCatalog([], [], []);');
  $entry = [...$this->entry, 'strikePoint' => ['x' => .25, 'y' => .5]];
  $entry['poses']['attack']['reach'] = ['x' => .6, 'y' => -.3];
  $path = $this->root . '/' . BattlerBindings::FILE;
  $source = '<?php return ' . var_export(['enemies' => ['enemy-id' => $entry]], true) . ';';
  file_put_contents($path, $source);
  $first = BattlePresentationCatalog::load($this->root);
  writeTestPng($this->root . '/Graphics/body.png', 80, 160);
  $replacement = BattlePresentationCatalog::load($this->root);
  expect($first->enemies['enemy-id']->width)->toBe(40)->and($replacement->enemies['enemy-id']->width)->toBe(80)
    ->and($replacement->enemyStrikePoints['enemy-id'])->toEqual($first->enemyStrikePoints['enemy-id'])
    ->and($replacement->enemyPoses['enemy-id']->getPose(BattlePoseRole::ATTACK)->reach->toArray())->toBe(['x' => .6, 'y' => -.3])
    ->and(file_get_contents($path))->toBe($source);
});

it('resolves normalized contact using replacement body bounds and the actual drawn mirrors', function (bool $flipX, bool $flipY) {
  $point = new BattlerStrikePoint(.25, .6);
  $body = new CanvasRectangle(20, 30, 40, 80);
  $replacement = new CanvasRectangle(40, 60, 80, 160);
  $one = $point->getCanvasPosition($body, $flipX, $flipY);
  $two = $point->getCanvasPosition($replacement, $flipX, $flipY);
  expect($one->x)->toBe(20 + ($flipX ? .75 : .25) * 40)
    ->and($one->y)->toBe(30 + ($flipY ? .4 : .6) * 80)
    ->and($two->x)->toBe(2 * $one->x)->and($two->y)->toBe(2 * $one->y);
})->with([true, false])->with([true, false]);

it('retains contact metadata when binding arenas and independent code or data battlers', function () {
  $codePoint = new BattlerStrikePoint(.2, .5);
  $base = new BattlePresentationCatalog([], [], [], ui: new BattleCanvasLayout(1350, 720),
    actorStrikePoints: ['code-actor' => $codePoint]);
  $entry = [...$this->entry, 'strikePoint' => ['x' => .7, 'y' => .4]];
  $bindings = BattlerBindings::getFromArray(['enemies' => ['data-enemy' => $entry]], $this->root);
  $joined = $base->bindBattlers($bindings);
  $arenas = BattleArenaBindings::getFromArray(['arenas' => ['road' => ['name' => 'Road', 'background' => ['image' => 'Graphics/road.png']]]]);
  $withArena = $joined->bindArenas($arenas);
  expect($joined->actorStrikePoints['code-actor'])->toBe($codePoint)
    ->and($joined->enemyStrikePoints['data-enemy']->toArray())->toBe(['x' => .7, 'y' => .4])
    ->and($withArena->actorStrikePoints)->toBe($joined->actorStrikePoints)
    ->and($withArena->enemyStrikePoints)->toBe($joined->enemyStrikePoints)
    ->and($base->enemyStrikePoints)->toBe([]);
});

it('preserves one owner per identity even when code declares contact without base artwork', function (bool $party) {
  $point = new BattlerStrikePoint(.5, .5);
  $base = new BattlePresentationCatalog([], [], [], actorStrikePoints: $party ? ['bound-id' => $point] : [],
    enemyStrikePoints: $party ? [] : ['bound-id' => $point]);
  $bindings = BattlerBindings::getFromArray([$party ? 'actors' : 'enemies' => ['bound-id' => $this->entry]], $this->root);
  expect(fn() => $base->bindBattlers($bindings))->toThrow(InvalidArgumentException::class, 'bound both');
})->with([true, false]);

it('refuses unknown, incomplete, null or incorrectly typed reach and contact data at the authored boundary', function (string $field, mixed $value) {
  $entry = $this->entry;
  if ($field === 'reach') { $entry['poses']['attack']['reach'] = $value; }
  else { $entry['strikePoint'] = $value; }
  expect(fn() => BattlerBindings::getFromArray(['actors' => ['actor-id' => $entry]], $this->root))
    ->toThrow(InvalidArgumentException::class, $field === 'reach' ? 'actors.actor-id.poses.attack.reach' : 'actors.actor-id.strikePoint');
})->with([
  'null reach' => ['reach', null], 'missing reach axis' => ['reach', ['x' => 1]],
  'unknown reach axis' => ['reach', ['x' => 1, 'y' => 0, 'z' => 0]],
  'string reach' => ['reach', ['x' => '1', 'y' => 0]], 'boolean reach' => ['reach', ['x' => true, 'y' => 0]],
  'backward reach' => ['reach', ['x' => -.1, 'y' => 0]], 'infinite reach' => ['reach', ['x' => INF, 'y' => 0]],
  'NaN reach' => ['reach', ['x' => 1, 'y' => NAN]], 'list reach' => ['reach', [1, 0]],
  'null strike' => ['strikePoint', null], 'missing strike axis' => ['strikePoint', ['x' => .5]],
  'unknown strike axis' => ['strikePoint', ['x' => .5, 'y' => .5, 'z' => 0]],
  'string strike' => ['strikePoint', ['x' => '.5', 'y' => .5]], 'boolean strike' => ['strikePoint', ['x' => false, 'y' => .5]],
  'negative strike' => ['strikePoint', ['x' => -.1, 'y' => .5]], 'oversized strike' => ['strikePoint', ['x' => .5, 'y' => 1.1]],
  'infinite strike' => ['strikePoint', ['x' => INF, 'y' => .5]], 'NaN strike' => ['strikePoint', ['x' => .5, 'y' => NAN]],
  'list strike' => ['strikePoint', [.5, .5]],
]);

it('refuses invalid reference scales and incorrectly typed code-owned contact maps', function () {
  $reach = new BattlePoseReach(1, -.5);
  foreach ([0, -1, INF, NAN] as $scale) {
    expect(fn() => $reach->getCanvasOffset($scale))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new BattlePresentationCatalog([], [], [], actorStrikePoints: ['actor' => ['x' => .5, 'y' => .5]]))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattlePresentationCatalog([], [], [], enemyStrikePoints: ['enemy' => null]))
    ->toThrow(InvalidArgumentException::class);
});
