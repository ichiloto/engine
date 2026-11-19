<?php

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Battle\Presentation\{BattleTravelPlan, BattleTravelPoseBounds};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

it('checks a fitted hop where each drawing is displayed instead of applying its peak at arrival', function () {
  $plan = new BattleTravelPlan([[700, 400], [200, 250]], 180, 250, -1, 600, 900);
  $arena = new CanvasRectangle(0, 80, 800, 520);
  $standing = new CanvasRectangle(680, 240, 40, 160);
  $peak = new CanvasRectangle(680, 180, 40, 170);
  $sequence = new BattleTravelPoseBounds([$standing, $peak, $standing], 16, fitToPhase: true);
  expect($sequence->isWithinArena($plan, $arena, returning: true))->toBeTrue()
    ->and((new BattleTravelPoseBounds([$peak, $standing, $standing], 16, fitToPhase: true))
      ->isWithinArena($plan, $arena, returning: true))->toBeFalse();
});

it('checks each stationary transition at its own endpoint', function () {
  $pose = new BattleTravelPoseBounds([new CanvasRectangle(680, 180, 40, 170)], 16);
  $arena = new CanvasRectangle(0, 80, 800, 520);
  expect($pose->isAtAnchorWithinArena(new Vector2(700, 400), new Vector2(700, 400), $arena))->toBeTrue()
    ->and($pose->isAtAnchorWithinArena(new Vector2(700, 400), new Vector2(200, 250), $arena))->toBeFalse();
});

it('does not demand a fitted drawing that a shorter command clock never displays', function () {
  $plan = new BattleTravelPlan([[700, 400], [692, 400]], 680, 400, -1, 600, 900);
  $safe = new CanvasRectangle(680, 240, 40, 160);
  $outside = new CanvasRectangle(680, 0, 40, 160);
  $pose = new BattleTravelPoseBounds([$safe, $outside, $safe, $outside], 16, fitToPhase: true);
  expect($pose->isWithinArena($plan, new CanvasRectangle(0, 80, 800, 520), returning: true))->toBeTrue();
  $stationary = new BattleTravelPlan([[700, 400], [700, 400]], 680, 400, -1, 600, 900);
  expect($pose->isWithinArena($stationary, new CanvasRectangle(0, 80, 800, 520), returning: true))->toBeTrue();
});

it('finds a pose crossing an arena edge at an interior waypoint', function () {
  $plan = new BattleTravelPlan([[700, 400], [500, 220], [200, 400]], 180, 400, -1, 600, 900);
  $pose = new BattleTravelPoseBounds([new CanvasRectangle(680, 240, 40, 160)], 16, loop: true);
  expect($pose->isWithinArena($plan, new CanvasRectangle(0, 80, 800, 520)))->toBeFalse();
});

it('keeps long-trip verification bounded by artwork and route segments', function () {
  $plan = new BattleTravelPlan([[700, 400], [200, 400]], 180, 400, -1, .0005, .0005);
  $pose = new BattleTravelPoseBounds([new CanvasRectangle(680, 240, 40, 160), new CanvasRectangle(680, 180, 40, 160)],
    17, loop: true);
  expect($pose->isWithinArena($plan, new CanvasRectangle(0, 80, 800, 520)))->toBeTrue();
  $outside = new BattleTravelPoseBounds([new CanvasRectangle(680, 240, 40, 160), new CanvasRectangle(680, 0, 40, 160)],
    17, loop: true);
  expect($outside->isWithinArena($plan, new CanvasRectangle(0, 80, 800, 520)))->toBeFalse();
});

it('matches exhaustive command-clock observations across repeated entries, rates, clipping edges and path turns', function () {
  $arena = new CanvasRectangle(0, 80, 800, 520);
  $paths = [[[700, 400], [200, 250]], [[700, 400], [500, 245], [200, 400]],
    [[700, 400], [500, 400], [200, 250]], [[700, 400], [699, 400]]];
  $frames = [new CanvasRectangle(675, 255, 45, 160), new CanvasRectangle(675, 180, 45, 160),
    new CanvasRectangle(675, 255, 45, 160), new CanvasRectangle(675, 230, 45, 160)];
  foreach ($paths as $points) {
    foreach ([7, 16, 119, 120] as $fps) {
      foreach ([[true, false], [false, false], [false, true]] as [$loop, $fitted]) {
        $plan = new BattleTravelPlan($points, 180, 250, -1, 600, 900);
        foreach ([false, true] as $returning) {
          $ticks = max(1, (int)ceil(($returning ? $plan->returnSeconds : $plan->advanceSeconds) * 120));
          $expected = true;
          for ($tick = 0; $tick < $ticks; $tick++) {
            $position = $plan->getPositionAtProgress($tick / $ticks, $returning);
            $entry = EffectPlaybackTiming::getFrameCountForElapsed($tick / 120, $fitted ? ($ticks / 120) / count($frames) : 1 / $fps);
            $box = $frames[$loop ? $entry % count($frames) : min($entry, count($frames) - 1)];
            $x = $box->x + $position->x - 700; $y = $box->y + $position->y - 400;
            $expected = $expected && $x >= $arena->x - 1e-7 && $y >= $arena->y - 1e-7
              && $x + $box->width <= $arena->x + $arena->width + 1e-7
              && $y + $box->height <= $arena->y + $arena->height + 1e-7;
          }
          expect((new BattleTravelPoseBounds($frames, $fps, $loop, $fitted))->isWithinArena($plan, $arena, $returning))
            ->toBe($expected);
        }
      }
    }
  }
});

it('refuses invalid pose-clock inputs and unrepresentable command durations', function () {
  expect(fn() => new BattleTravelPoseBounds([], 16))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTravelPoseBounds([new CanvasRectangle(0, 0, 20, 20)], 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTravelPoseBounds([new CanvasRectangle(0, 0, 20, 20)], 16, true, true))->toThrow(InvalidArgumentException::class);
  $plan = new BattleTravelPlan([[700, 400], [200, 400]], 180, 400, -1, PHP_FLOAT_MIN, 900);
  $pose = new BattleTravelPoseBounds([new CanvasRectangle(680, 240, 40, 160)], 16);
  expect(fn() => $pose->isWithinArena($plan, new CanvasRectangle(0, 80, 800, 520)))->toThrow(InvalidArgumentException::class);
});


it('detects active horizontal and lower-edge overhang near home without borrowing later drawings at contact', function (string $edge) {
  $plan = new BattleTravelPlan([[700, 400], [200, 250]], 180, 250, -1, 600, 900);
  $safe = new CanvasRectangle(680, 240, 40, 160);
  $overhang = $edge === 'right' ? new CanvasRectangle(680, 240, 200, 160) : new CanvasRectangle(680, 380, 40, 250);
  $arena = new CanvasRectangle(0, 80, 800, 520);
  expect((new BattleTravelPoseBounds([$overhang, $safe, $safe], 16, fitToPhase: true))
    ->isWithinArena($plan, $arena, returning: true))->toBeTrue()
    ->and((new BattleTravelPoseBounds([$safe, $safe, $overhang], 16, fitToPhase: true))
      ->isWithinArena($plan, $arena, returning: true))->toBeFalse();
})->with(['right', 'bottom']);
