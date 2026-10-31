<?php

use Ichiloto\Engine\Battle\Presentation\BattleFormationClearance;
use Ichiloto\Engine\Battle\Presentation\BattleTravelFailure;
use Ichiloto\Engine\Battle\Presentation\BattleTravelPlan;
use Ichiloto\Engine\Battle\Presentation\BattleTravelPlanner;
use Ichiloto\Engine\Battle\Presentation\BattleTravelSubject;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

function createTravelSubject(float $x, float $y, float $width = 40, float $height = 80): BattleTravelSubject
{
  $body = new CanvasRectangle($x - $width / 2, $y - $height, $width, $height);
  return new BattleTravelSubject(new Vector2($x, $y), $body, BattleFormationClearance::getFooting($body));
}

function expectTravelPoint(Vector2 $point, float $x, float $y): void
{
  expect($point->x)->toEqualWithDelta($x, .000001)->and($point->y)->toEqualWithDelta($y, .000001);
}

/** Check actual translated existing footings and body bounds, independently of the planner's inflated rectangles. */
function expectTravelClear(BattleTravelPlan $plan, BattleTravelSubject $source, CanvasRectangle $arena, array $blockers): void
{
  expect($plan->isPossible)->toBeTrue();
  for ($i = 0; $i <= 200; $i++) {
    $at = $plan->getPositionAtProgress($i / 200);
    $dx = $at->x - $source->footX;
    $dy = $at->y - $source->footY;
    foreach ([$source->body, $source->footing] as $bounds) {
      expect($bounds->x + $dx >= $arena->x - .000001 && $bounds->y + $dy >= $arena->y - .000001
        && $bounds->x + $bounds->width + $dx <= $arena->x + $arena->width + .000001
        && $bounds->y + $bounds->height + $dy <= $arena->y + $arena->height + .000001)->toBeTrue();
    }
    foreach ($blockers as $box) {
      $overlapX = min($source->footing->x + $source->footing->width + $dx, $box->x + $box->width)
        - max($source->footing->x + $dx, $box->x);
      $overlapY = min($source->footing->y + $source->footing->height + $dy, $box->y + $box->height)
        - max($source->footing->y + $dy, $box->y);
      expect($overlapX <= .000001 || $overlapY <= .000001)->toBeTrue();
    }
    $back = $plan->getPositionAtProgress(1 - $i / 200, returning: true);
    expectTravelPoint($back, $at->x, $at->y);
  }
}

it('lands the exact strike on the near edge of current small and large target bodies from either side', function (bool $left, float $width) {
  $source = createTravelSubject($left ? 700 : 100, 250);
  $target = createTravelSubject(400, 250, $width, 140);
  $plan = BattleTravelPlanner::plan($source, $target, new CanvasRectangle(0, 0, 800, 400), new Vector2(60, -40));
  $sign = $left ? -1 : 1;
  $edge = $left ? 400 + $width / 2 : 400 - $width / 2;
  expect($plan->isPossible)->toBeTrue()->and($plan->facingSign)->toBe($sign);
  expectTravelPoint($plan->getPositionAtProgress(1), $edge - $sign * 60, 250);
  expect($plan->contactX)->toBe($edge)->and($plan->contactY)->toBe(210.0);
  expectTravelPoint($plan->getPositionAtProgress(0, returning: true), $edge - $sign * 60, 250);
  expectTravelPoint($plan->getPositionAtProgress(1, returning: true), $source->footX, 250);
})->with([true, false])->with([40.0, 240.0]);

it('uses an authored strike point exactly without snapping to a body centre or substitute endpoint', function () {
  $source = createTravelSubject(700, 260);
  $target = createTravelSubject(300, 240, 160, 160);
  $plan = BattleTravelPlanner::plan($source, $target, new CanvasRectangle(0, 0, 800, 400),
    new Vector2(100, -50), strikePoint: new Vector2(365, 170));
  expect($plan->isPossible)->toBeTrue()->and([$plan->contactX, $plan->contactY])->toBe([365.0, 170.0]);
  expectTravelPoint($plan->getPositionAtProgress(1), 465, 220);
});

it('scales advance and backward-hop durations by route distance at independent constant speeds', function () {
  $arena = new CanvasRectangle(0, 0, 1000, 400);
  $target = createTravelSubject(200, 250);
  $near = BattleTravelPlanner::plan(createTravelSubject(480, 250), $target, $arena, new Vector2(60, -40),
    advanceSpeed: 100, returnSpeed: 200);
  $far = BattleTravelPlanner::plan(createTravelSubject(680, 250), $target, $arena, new Vector2(60, -40),
    advanceSpeed: 100, returnSpeed: 200);
  expect($near->distance)->toBe(200.0)->and($far->distance)->toBe(400.0)
    ->and($far->advanceSeconds)->toBe(2 * $near->advanceSeconds)
    ->and($far->returnSeconds)->toBe(2 * $near->returnSeconds);
  expectTravelPoint($near->getPositionAtProgress(.5), 380, 250);
  expectTravelPoint($far->getPositionAtProgress(.25), 580, 250);
});

it('routes continuously around several intervening ground footings and returns along the identical route', function () {
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $source = createTravelSubject(700, 250);
  $target = createTravelSubject(100, 250);
  $blockers = [new CanvasRectangle(520, 205, 40, 90), new CanvasRectangle(350, 140, 70, 140),
    new CanvasRectangle(225, 255, 45, 60)];
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), $blockers);
  expect(count($plan->getWaypoints()))->toBeGreaterThan(2)->and($plan->distance)->toBeGreaterThan(520.0)
    ->and($plan->facingSign)->toBe(-1)->and($plan->advanceSeconds)->toEqualWithDelta($plan->distance / 600, .000001);
  expectTravelClear($plan, $source, $arena, [...$blockers, $target->footing]);
});

it('preserves arena edge clearance and cannot tunnel through a thin full-height barrier', function () {
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $source = createTravelSubject(700, 250);
  $target = createTravelSubject(100, 250);
  $wall = new CanvasRectangle(450, 0, .01, 400);
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), [$wall]);
  expect($plan->isPossible)->toBeFalse()->and($plan->failure)->toBe(BattleTravelFailure::NO_ROUTE)
    ->and($plan->getWaypoints())->toBe([])
    ->and(fn() => $plan->getPositionAtProgress(.5))->toThrow(LogicException::class);
});

it('does not turn tall sprite overhang into a ground collision wall', function () {
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $source = createTravelSubject(700, 230);
  $target = createTravelSubject(100, 230);
  $tall = new CanvasRectangle(380, 10, 60, 340);
  $footing = BattleFormationClearance::getFooting($tall);
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), [$footing]);
  expect($plan->getWaypoints())->toHaveCount(2);
  expectTravelClear($plan, $source, $arena, [$footing, $target->footing]);
});

it('handles overlapping blocker unions and asymmetric source pivots without crossing their ground', function () {
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $body = new CanvasRectangle(640, 170, 90, 80);
  $source = new BattleTravelSubject(new Vector2(710, 250), $body, BattleFormationClearance::getFooting($body));
  $target = createTravelSubject(100, 250);
  $blockers = [new CanvasRectangle(450, 210, 90, 80), new CanvasRectangle(380, 240, 100, 100),
    new CanvasRectangle(510, 190, 50, 80)];
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(100, -40), $blockers);
  expectTravelPoint($plan->getPositionAtProgress(1), 220, 250);
  expectTravelClear($plan, $source, $arena, [...$blockers, $target->footing]);
  $points = $plan->getWaypoints();
  $firstLength = hypot($points[1]->x - $points[0]->x, $points[1]->y - $points[0]->y);
  expectTravelPoint($plan->getPositionAtProgress($firstLength / $plan->distance), $points[1]->x, $points[1]->y);
});

it('uses a safe boundary detour when an obstacle extends beyond the arena and never clips a false passage', function () {
  $arena = new CanvasRectangle(0, 80, 800, 300);
  $source = createTravelSubject(700, 250);
  $target = createTravelSubject(100, 250);
  $wall = new CanvasRectangle(400, 0, 20, 280);
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), [$wall]);
  expect($plan->isPossible)->toBeTrue();
  $points = $plan->getWaypoints();
  expect(max(array_column($points, 'y')))->toBeGreaterThan(280.0);
  expectTravelClear($plan, $source, $arena, [$wall, $target->footing]);
});

it('keeps an already positioned actor stationary with zero travel duration', function () {
  $source = createTravelSubject(180, 250);
  $plan = BattleTravelPlanner::plan($source, createTravelSubject(100, 250),
    new CanvasRectangle(0, 0, 800, 400), new Vector2(60, -40));
  expect($plan->isPossible)->toBeTrue()->and($plan->distance)->toBe(0.0)
    ->and($plan->advanceSeconds)->toBe(0.0)->and($plan->returnSeconds)->toBe(0.0);
  expectTravelPoint($plan->getPositionAtProgress(.5), 180, 250);
  expectTravelPoint($plan->getPositionAtProgress(.75, returning: true), 180, 250);
});

it('refuses an occupied exact endpoint and never silently shortens the requested reach', function () {
  $target = createTravelSubject(300, 250);
  $plan = BattleTravelPlanner::plan(createTravelSubject(700, 250), $target,
    new CanvasRectangle(0, 0, 800, 400), new Vector2(0, -40));
  expect($plan->failure)->toBe(BattleTravelFailure::CONTACT_OCCUPIED)->and($plan->getWaypoints())->toBe([]);
});

it('reports an initially occupied footprint and source or destination outside the safe arena', function () {
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $source = createTravelSubject(700, 250);
  $target = createTravelSubject(100, 250);
  expect(BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), [$source->footing])->failure)
    ->toBe(BattleTravelFailure::START_OCCUPIED);
  expect(BattleTravelPlanner::plan(createTravelSubject(790, 250), $target, $arena, new Vector2(60, -40))->failure)
    ->toBe(BattleTravelFailure::OUTSIDE_ARENA);
  expect(BattleTravelPlanner::plan($source, $target, $arena, new Vector2(1000, -40))->failure)
    ->toBe(BattleTravelFailure::OUTSIDE_ARENA);
});

it('requires explicit facing for aligned foot anchors and keeps it unchanged on the return', function () {
  $source = createTravelSubject(500, 300);
  $target = createTravelSubject(500, 150);
  $arena = new CanvasRectangle(0, 0, 800, 400);
  expect(fn() => BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40)))
    ->toThrow(InvalidArgumentException::class, 'explicit facing');
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), facingSign: -1);
  expect($plan->isPossible)->toBeTrue()->and($plan->facingSign)->toBe(-1);
  expectTravelPoint($plan->getPositionAtProgress(1, returning: true), 500, 300);
});

it('snapshots mutable input vectors and gives callers independent sampled positions', function () {
  $foot = new Vector2(700, 250);
  $body = new CanvasRectangle(680, 170, 40, 80);
  $source = new BattleTravelSubject($foot, $body, BattleFormationClearance::getFooting($body));
  $plan = BattleTravelPlanner::plan($source, createTravelSubject(100, 250), new CanvasRectangle(0, 0, 800, 400), new Vector2(60, -40));
  $foot->x = 0;
  $points = $plan->getWaypoints();
  $points[0]->x = 1;
  $sample = $plan->getPositionAtProgress(0);
  $sample->x = 2;
  expectTravelPoint($plan->getPositionAtProgress(0), 700, 250);
});

it('reports bounded capacity instead of allocating an unbounded raster or graph', function () {
  $blockers = array_fill(0, BattleTravelPlanner::MAX_FOOTINGS, new CanvasRectangle(200, 20, 10, 10));
  $plan = BattleTravelPlanner::plan(createTravelSubject(700, 250), createTravelSubject(100, 250),
    new CanvasRectangle(0, 0, 800, 400), new Vector2(60, -40), $blockers);
  expect($plan->failure)->toBe(BattleTravelFailure::CAPACITY)->and($plan->getWaypoints())->toBe([]);
});

it('refuses invalid speeds, reach, facing and progress', function () {
  $source = createTravelSubject(700, 250);
  $target = createTravelSubject(100, 250);
  $arena = new CanvasRectangle(0, 0, 800, 400);
  $infinite = new Vector2();
  $infinite->x = INF;
  $nan = new Vector2(10, 0);
  $nan->y = NAN;
  foreach ([new Vector2(-1, 0), $infinite, $nan] as $reach) {
    expect(fn() => BattleTravelPlanner::plan($source, $target, $arena, $reach))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), advanceSpeed: 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), returnSpeed: INF))->toThrow(InvalidArgumentException::class)
    ->and(fn() => BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40), facingSign: 1))->toThrow(InvalidArgumentException::class);
  $plan = BattleTravelPlanner::plan($source, $target, $arena, new Vector2(60, -40));
  foreach ([-.1, 1.1, NAN] as $progress) { expect(fn() => $plan->getPositionAtProgress($progress))->toThrow(InvalidArgumentException::class); }
});
