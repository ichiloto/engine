<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleCubicFlightClearance;
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
