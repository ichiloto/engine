<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;

it('centers maps that are smaller than the viewport', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('.', 20)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(30.0)
    ->and($screenPosition->y)->toBe(20.0);
});

it('keeps large maps anchored to the viewport while scrolling', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(10, 5), null, array_fill(0, 60, str_repeat('.', 120)));
  $scene->camera = $camera;

  $screenPosition = $camera->getScreenSpacePosition(new Vector2(25, 15));

  expect($screenPosition->x)->toBe(15.0)
    ->and($screenPosition->y)->toBe(10.0);
});

it('uses the upper middle cell as the focus point on even viewports', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50);
  $scene->camera = $camera;

  expect($camera->getHorizontalFocusPosition())->toBe(39)
    ->and($camera->getVerticalFocusPosition())->toBe(24);
});

it('re-centers smaller maps after the viewport is resized', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 80, 50, new Vector2(0, 0), null, array_fill(0, 10, str_repeat('.', 20)));
  $scene->camera = $camera;

  $camera->resizeViewport(100, 60);
  $screenPosition = $camera->getScreenSpacePosition(new Vector2(0, 0));

  expect($screenPosition->x)->toBe(40.0)
    ->and($screenPosition->y)->toBe(25.0);
});

it('detaches, clamps, focuses, restores and reattaches without duplicating camera math', function () {
  $scene = makeCameraTestScene();
  $camera = new Camera($scene, 20, 10, new Vector2(3, 4), null, array_fill(0, 40, str_repeat('.', 80)));
  $scene->camera = $camera;
  $initial = $camera->captureState();

  $camera->detach();
  $camera->moveTo(500, -20);
  expect($camera->followsPlayer)->toBeFalse()
    ->and([$camera->position->x, $camera->position->y])->toBe([60.0, 0.0]);

  $camera->focusOn(new Vector2(40, 20));
  expect([$camera->position->x, $camera->position->y])->toBe([31.0, 16.0]);

  $camera->restorePrevious();
  expect($camera->followsPlayer)->toBeTrue()
    ->and([$camera->position->x, $camera->position->y])->toBe([
      $initial->position->x,
      $initial->position->y,
    ]);
});


it('fits drawn subjects clear of dialogue with signed projection while preserving camera ownership', function () {
  $camera = new Camera(makeCameraTestScene(), 28, 15, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  $before = $camera->captureState(); $owner = new stdClass();
  $subjects = new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(4, 25.5, 9, 30);
  $safe = new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(0, 0, 28, 10);
  expect($camera->frameBounds($owner, $subjects, $safe))->toBeTrue()->and($camera->isFramed)->toBeTrue();
  $origin = $camera->getWorldOrigin();
  expect($subjects->top - $origin['y'])->toBeGreaterThanOrEqual(0)
    ->and($subjects->bottom - $origin['y'])->toBeLessThanOrEqual(10)
    ->and($camera->captureState())->toEqual($before);
  $world = new Vector2(7, 29); $screen = $camera->getScreenSpacePosition($world);
  expect($camera->getWorldSpacePosition($screen))->toEqual($world);
  $newOwner = new stdClass(); $camera->frameBounds($newOwner, $subjects, $safe); $camera->clearFraming($owner);
  expect($camera->isFramed)->toBeTrue();
  $camera->clearFraming($newOwner); expect($camera->isFramed)->toBeFalse()->and($camera->captureState())->toEqual($before);
});

it('fits a small map into dialogue space without enlarging its physical source or changing actor positions', function () {
  $rows = array_fill(0, 8, 'abcdefgh');
  $camera = new Camera(makeCameraTestScene(), 28, 15, worldSpace: $rows); $owner = new stdClass();
  $subjects = new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(-.25, -.125, 8, 8);
  expect($camera->frameBounds($owner, $subjects, new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(0, 0, 28, 10)))->toBeTrue();
  $origin = $camera->getWorldOrigin();
  expect($origin['y'])->toBeLessThan(0)->and($subjects->top - $origin['y'])->toBeGreaterThanOrEqual(0)
    ->and($subjects->bottom - $origin['y'])->toBeLessThanOrEqual(10)->and($camera->worldSpace)->toBe($rows);
  $camera->worldSpace = $rows;
  expect($camera->isFramed)->toBeFalse();
  expect($camera->frameBounds($owner, new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(0, 0, 30, 14),
    new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(0, 0, 28, 10)))->toBeFalse();
});


it('keeps authored camera movement live within the cast-safe range and refuses invalid signed origins', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $owner = new stdClass(); $subjects = new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(20, 20, 24, 24);
  $safe = new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(0, 0, 10, 6);
  $camera->moveTo(15, 19); $camera->frameBounds($owner, $subjects, $safe);
  expect($camera->getWorldOrigin())->toBe(['x' => 15, 'y' => 19]);
  $camera->moveTo(20, 20); $camera->frameBounds($owner, $subjects, $safe);
  expect($camera->getWorldOrigin())->toBe(['x' => 20, 'y' => 20]);
  expect(fn() => $camera->frameBounds($owner, new \Ichiloto\Engine\Animations\Field\FieldSpriteBounds(1e12, 0, 1e12 + 1, 1), $safe))
    ->toThrow(InvalidArgumentException::class);
});
