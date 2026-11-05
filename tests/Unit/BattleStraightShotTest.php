<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleStraightShot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

it('chooses a single ordered ray through staggered target bodies', function (bool $mirrored) {
  $origin = new Vector2($mirrored ? 100 : 0, 10);
  $near = new CanvasRectangle($mirrored ? 60 : 20, 14, 20, 9);
  $far = new CanvasRectangle($mirrored ? 5 : 75, 29, 20, 20);
  $path = BattleStraightShot::plan($origin, [$near, $far]);
  expect($path)->not->toBeNull();
  $exit = $path->getPositionAtProgress(1);
  $throughNear = $path->getPositionAtProgress(($near->x + $near->width / 2 - $origin->x) / ($exit->x - $origin->x));
  expect($throughNear->y)->toBeGreaterThan($near->y)->toBeLessThan($near->y + $near->height)
    ->and($exit->y)->toBeGreaterThanOrEqual($far->y)->toBeLessThanOrEqual($far->y + $far->height)
    ->and(count($path->getWaypoints()))->toBe(2)
    ->and(BattleStraightShot::plan($origin, [$far, $near]))->toBeNull();
})->with([false, true]);

it('reports when no straight ray reaches both targets', function () {
  expect(BattleStraightShot::plan(new Vector2(0, 50), [
    new CanvasRectangle(20, 5, 10, 10), new CanvasRectangle(80, 80, 10, 10),
  ]))->toBeNull();
});
