<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCubicFlightClearance;
use Ichiloto\Engine\Battle\Presentation\BattleRectangleRoute;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleTrail;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationPath;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

it('keeps the authored curve exactly when every visible body clears', function () {
  $start = new Vector2(20, 70);
  $end = new Vector2(170, 90);
  $first = new Vector2(60, 52);
  $second = new Vector2(120, 86);
  $original = BattlePresentationPath::fromCubic($start, $end, $first, $second);
  $planned = BattleCubicFlightClearance::plan($start, $end, $first, $second, 160,
    [new CanvasRectangle(80, 102, 30, 35)], 9);

  expect($planned?->getWaypoints())->toEqual($original->getWaypoints());
});

it('reroutes a continuous trail around more than one body in either direction without moving its endpoints', function (bool $mirrored) {
  $start = new Vector2($mirrored ? 180 : 20, 70);
  $end = new Vector2($mirrored ? 20 : 180, 70);
  $first = new Vector2($mirrored ? 140 : 60, 70);
  $second = new Vector2($mirrored ? 60 : 140, 70);
  $bodies = [new CanvasRectangle(80, 58, 24, 27), new CanvasRectangle(104, 75, 27, 20)];
  $planned = BattleCubicFlightClearance::plan($start, $end, $first, $second, 130, $bodies, 8);

  expect($planned)->not->toBeNull()
    ->and($planned->getPositionAtProgress(0))->toEqual($start)
    ->and($planned->getPositionAtProgress(1))->toEqual($end)
    ->and($planned->getPositionAtProgress(.5)->y)->toBeLessThan(55);
  for ($tick = 0; $tick <= 256; $tick++) {
    $point = $planned->getPositionAtProgress($tick / 256);
    foreach ($bodies as $body) {
      expect($point->x < $body->x - 8 || $point->x > $body->x + $body->width + 8
        || $point->y <= $body->y - 8 + .000001 || $point->y >= $body->y + $body->height + 8 - .000001)->toBeTrue();
    }
  }
})->with([false, true]);

it('uses the available side of the arena and refuses a trail when the contact itself is blocked', function () {
  $start = new Vector2(20, 35);
  $end = new Vector2(180, 35);
  $first = new Vector2(65, 35);
  $second = new Vector2(125, 35);
  $upper = new CanvasRectangle(70, 0, 55, 54);
  $planned = BattleCubicFlightClearance::plan($start, $end, $first, $second, 130, [$upper], 8);

  expect($planned?->getPositionAtProgress(.5)->y)->toBeGreaterThan(62)
    ->and(BattleCubicFlightClearance::plan($start, $end, $first, $second, 130,
      [new CanvasRectangle(170, 22, 22, 29)], 8))->toBeNull();
});

it('routes around a joined barrier when the original cubic cannot turn inside the available height', function (bool $mirrored) {
  $point = static fn(float $x, float $y): Vector2 => new Vector2($mirrored ? 240 - $x : $x, $y);
  $body = static fn(float $x, float $y, float $width, float $height): CanvasRectangle =>
    new CanvasRectangle($mirrored ? 240 - $x - $width : $x, $y, $width, $height);
  $start = $point(220, 112);
  $end = $point(20, 76);
  $bodies = [$body(29, 5, 144, 72), $body(29, 73, 144, 51)];
  $planned = BattleCubicFlightClearance::plan($start, $end, $point(165, 98), $point(78, 67),
    160, $bodies, 5, width: 240);

  expect($planned)->not->toBeNull()
    ->and($planned->getPositionAtProgress(0))->toEqual($start)
    ->and($planned->getPositionAtProgress(1))->toEqual($end);
  for ($index = 0; $index <= 256; $index++) {
    $sample = $planned->getPositionAtProgress($index / 256);
    expect($sample->y)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(155);
    foreach ($bodies as $obstacle) {
      expect($sample->x <= $obstacle->x - 5 + .000001 || $sample->x >= $obstacle->x + $obstacle->width + 5 - .000001
        || $sample->y <= $obstacle->y - 5 + .000001 || $sample->y >= $obstacle->y + $obstacle->height + 5 - .000001)->toBeTrue();
    }
  }
})->with([false, true]);

it('keeps an attached launch and draws only its occluded departure behind battlers', function () {
  $start = new Vector2(34, 65);
  $end = new Vector2(220, 65);
  $sourceBody = new CanvasRectangle(20, 40, 38, 52);
  $bystander = new CanvasRectangle(90, 48, 42, 36);
  $path = BattleCubicFlightClearance::plan($start, $end, new Vector2(80, 65), new Vector2(170, 65), 160,
    [$sourceBody, $bystander], 6, width: 240);

  expect($path)->not->toBeNull()
    ->and($path->getPositionAtProgress(0))->toEqual($start)
    ->and($path->getPositionAtProgress(1))->toEqual($end)
    ->and($path->occludedDepartureDistance)->toBeGreaterThan(0);
  $bounds = [[14, 34, 64, 98], [84, 42, 138, 90]];
  $departure = $path->occludedDepartureDistance / $path->distance;
  for ($index = 0; $index <= 64; $index++) {
    $point = $path->getPositionAtProgress($departure + (1 - $departure) * $index / 64);
    expect(BattleRectangleRoute::isOccupied([$point->x, $point->y], $bounds))->toBeFalse();
  }

  $arena = new BattleCanvasLayout(240, 160, uiCellWidth: 1, uiCellHeight: 1);
  $track = ['flight' => ['arrivalFrame' => 20], 'detachFrame' => 0, 'fadeEndFrame' => 25, 'depth' => 'front',
    'profile' => ['length' => 90, 'spacing' => 4, 'fadeLengthRatio' => .5, 'startRadius' => 1, 'endRadius' => 5,
      'radiusPower' => 1, 'startOpacity' => .2, 'endOpacity' => 1, 'opacityPower' => 1,
      'brush' => ['falloff' => .9, 'alphaSteps' => 127, 'minimumAlpha' => 3,
        'bands' => [['until' => 1, 'color' => ['kind' => 'rgb', 'r' => 255, 'g' => 255, 'b' => 255]]]]]];
  $initial = GraphicalBattleTrail::compose('wake', $path, 0, $track, $arena);
  expect($initial)->toHaveCount(1)->and($initial[0]->layer)->toBeLessThan(BattleCanvasLayout::BATTLER_LAYER);
  $crossing = GraphicalBattleTrail::compose('wake', $path, (int)ceil($departure * 20), $track, $arena);
  expect(array_column($crossing, 'id'))->toBe(['wake-departure', 'wake'])
    ->and($crossing[0]->layer)->toBeLessThan(BattleCanvasLayout::BATTLER_LAYER)
    ->and($crossing[1]->layer)->toBeGreaterThan(BattleCanvasLayout::BATTLER_LAYER)
    ->and(GraphicalBattleTrail::compose('wake', $path, 25, $track, $arena))->toBe([]);
});
