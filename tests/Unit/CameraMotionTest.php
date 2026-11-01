<?php

use Ichiloto\Engine\Animations\Field\FieldSpriteBounds;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\CameraMotionEasing;
use Ichiloto\Engine\Rendering\Presentation\PresentationCameraMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewportFollow;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

it('keeps explicit camera timing finite and its retained identity bounded', function () {
  foreach (['', str_repeat('a', 257), "\xFF", "bad\0id", "bad\nline", "bad\u{0085}id"] as $id) {
    expect(fn() => new PresentationCameraMotion($id, .2))->toThrow(InvalidArgumentException::class);
  }
  foreach ([0.0, -1.0, INF, NAN, 60.01] as $seconds) {
    expect(fn() => new PresentationCameraMotion('transition', $seconds))->toThrow(InvalidArgumentException::class);
  }
  expect(new PresentationCameraMotion('transition', .2)->toArray())
    ->toBe(['id' => 'transition', 'duration' => .2, 'easing' => 'smoothstep'])
    ->and(new PresentationCameraMotion(str_repeat('a', 256), 60)->seconds)->toBe(60.0)
    ->and(new PresentationCameraMotion('linear', .4, CameraMotionEasing::LINEAR)->toArray()['easing'])->toBe('linear');
});

it('preserves camera transitions across independent follow and world-canvas selections', function () {
  $motion = new PresentationCameraMotion('correction', .2);
  $view = new PresentationViewport(2, 0, 0, new CanvasRectangle(0, 0, 100, 100),
    textLayerIds: ['subject'], spriteIds: ['actor'], worldId: 'world', worldOriginX: -3, worldOriginY: -5,
    follow: new PresentationViewportFollow('actor', ['subject']), cameraMotion: $motion);
  expect($view->withoutFollow()->cameraMotion)->toBe($motion)
    ->and($view->selectWorldCanvases(['lantern'])->cameraMotion)->toBe($motion)
    ->and($view->withoutCameraMotion()->follow)->toBe($view->follow)
    ->and($view->withoutCameraMotion()->worldOriginX)->toBe(-3)
    ->and($view->withoutCameraMotion()->worldOriginY)->toBe(-5)
    ->and($view->withoutCameraMotion()->toArray())->not->toHaveKey('cameraMotion')
    ->and($view->cameraMotion)->toBe($motion)
    ->and(fn() => new PresentationViewport(1, 0, 0, $view->clipRect, cameraMotion: $motion))
      ->toThrow(InvalidArgumentException::class);
});

it('retains a framing transition until a new target or timing is requested', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $owner = new stdClass(); $safe = new FieldSpriteBounds(0, 0, 10, 6); $before = $camera->captureState();
  $subjects = new FieldSpriteBounds(20, 20, 24, 24);
  $camera->frameBounds($owner, $subjects, $safe, .2);
  $motion = $camera->presentationMotion;
  expect($motion)->toBeInstanceOf(PresentationCameraMotion::class)
    ->and($camera->getWorldOrigin())->toBe(['x' => 14, 'y' => 18]);
  $camera->frameBounds($owner, new FieldSpriteBounds(19, 20, 23, 24), $safe, .2);
  expect($camera->presentationMotion)->toBe($motion);
  $camera->frameBounds($owner, new FieldSpriteBounds(28, 20, 32, 24), $safe, .2);
  expect($camera->presentationMotion->id)->not->toBe($motion->id)
    ->and($camera->getWorldOrigin())->toBe(['x' => 22, 'y' => 18]);
  $motion = $camera->presentationMotion;
  $camera->frameBounds($owner, new FieldSpriteBounds(28, 20, 32, 24), $safe, .4, CameraMotionEasing::LINEAR);
  expect($camera->presentationMotion->id)->not->toBe($motion->id)
    ->and($camera->presentationMotion->easing)->toBe(CameraMotionEasing::LINEAR)
    ->and($camera->captureState())->toEqual($before);
});

it('releases only its own framing lease and retains the return transition across repeated cleanup', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $owner = new stdClass(); $camera->frameBounds($owner, new FieldSpriteBounds(20, 20, 24, 24),
    new FieldSpriteBounds(0, 0, 10, 6), .2);
  $motion = $camera->presentationMotion;
  $camera->clearFraming(new stdClass(), .2);
  expect($camera->presentationMotion)->toBe($motion)->and($camera->isFramed)->toBeTrue();
  $camera->clearFraming($owner, .2);
  $return = $camera->presentationMotion;
  expect($return->id)->not->toBe($motion->id)->and($camera->isFramed)->toBeFalse()
    ->and($camera->getWorldOrigin())->toBe(['x' => 0, 'y' => 0]);
  $camera->clearFraming($owner, .2);
  expect($camera->presentationMotion)->toBe($return);
});

it('clears automatic motion for authored positioning, snap mode and world or viewport changes', function () {
  $rows = array_fill(0, 60, str_repeat('.', 80));
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: $rows);
  $owner = new stdClass(); $subjects = new FieldSpriteBounds(20, 20, 24, 24); $safe = new FieldSpriteBounds(0, 0, 10, 6);
  $camera->frameBounds($owner, $subjects, $safe, .2);
  $camera->moveTo(20, 20);
  expect($camera->presentationMotion)->toBeNull();
  $camera->frameBounds($owner, $subjects, $safe, .2);
  expect($camera->presentationMotion)->toBeNull()->and($camera->getWorldOrigin())->toBe(['x' => 20, 'y' => 20]);
  $camera->frameBounds($owner, new FieldSpriteBounds(40, 40, 44, 44), $safe, .2);
  expect($camera->presentationMotion)->not->toBeNull();
  $camera->frameBounds($owner, new FieldSpriteBounds(40, 40, 44, 44), $safe);
  expect($camera->presentationMotion)->toBeNull();
  $camera->clearFraming($owner, .2); $camera->worldSpace = $rows;
  expect($camera->presentationMotion)->toBeNull()->and($camera->isFramed)->toBeFalse();
  $camera->frameBounds($owner, new FieldSpriteBounds(40, 40, 44, 44), $safe, .2);
  $camera->resizeViewport(12, 9);
  expect($camera->presentationMotion)->toBeNull()->and($camera->isFramed)->toBeFalse();
});

it('rejects invalid framing durations before changing logical or presentation state', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $before = $camera->captureState();
  foreach ([-.1, INF, NAN, 60.01] as $seconds) {
    expect(fn() => $camera->frameBounds(new stdClass(), new FieldSpriteBounds(20, 20, 24, 24),
      new FieldSpriteBounds(0, 0, 10, 6), $seconds))->toThrow(InvalidArgumentException::class);
    expect($camera->isFramed)->toBeFalse()->and($camera->presentationMotion)->toBeNull()
      ->and($camera->captureState())->toEqual($before);
  }
});

it('owns a projected destination separately from sampled logical state and respects framing cleanup', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $owner = new stdClass(); $reader = new stdClass();
  $target = new Vector2(30, 20);
  $camera->beginTransition($owner, $target, 2.0);
  $motion = $camera->presentationMotion;
  $target->x = 70;
  $camera->updateTransition($owner, new Vector2(15, 10), 1.0);
  expect($camera->position)->toEqual(new Vector2(15, 10))
    ->and($camera->getWorldOrigin())->toBe(['x' => 30, 'y' => 20])
    ->and($camera->getScreenSpacePosition(new Vector2(32, 22)))->toEqual(new Vector2(2, 2))
    ->and($camera->captureState()->position)->toEqual(new Vector2(15, 10))
    ->and($camera->presentationMotion)->toBe($motion);
  $camera->frameBounds($reader, new FieldSpriteBounds(20, 20, 24, 24), new FieldSpriteBounds(0, 0, 10, 6), .2);
  $readingMotion = $camera->presentationMotion;
  expect($camera->getWorldOrigin())->toBe(['x' => 20, 'y' => 20])
    ->and($readingMotion->seconds)->toBe(1.0);
  $camera->updateTransition($owner, new Vector2(22, 15), .5);
  $camera->frameBounds($reader, new FieldSpriteBounds(20, 20, 24, 24), new FieldSpriteBounds(0, 0, 10, 6), .2);
  expect($camera->presentationMotion)->toBe($readingMotion);
  $camera->clearFraming($reader, .2);
  expect($camera->getWorldOrigin())->toBe(['x' => 30, 'y' => 20])
    ->and($camera->presentationMotion->seconds)->toBe(.5);
  $camera->updateTransition($owner, new Vector2(30, 20), 0.0);
  $last = $camera->presentationMotion; $camera->endTransition($owner);
  expect($camera->presentationMotion)->toBe($last)->and($camera->hasTransition)->toBeFalse();
});

it('prevents old transition samples and cleanup from changing a newer destination', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $old = new stdClass(); $next = new stdClass();
  $camera->beginTransition($old, new Vector2(10, 10), 1.0);
  $camera->beginTransition($next, new Vector2(20, 20), 2.0);
  $motion = $camera->presentationMotion;
  expect($camera->updateTransition($old, new Vector2(5, 5), .5))->toBeFalse();
  $camera->endTransition($old, snap: true);
  expect($camera->ownsTransition($next))->toBeTrue()->and($camera->presentationMotion)->toBe($motion)
    ->and($camera->position)->toEqual(new Vector2(0, 0));
  $camera->resizeViewport(12, 9);
  expect($camera->hasTransition)->toBeFalse()->and($camera->presentationMotion)->toBeNull();
});

it('rejects malformed transition inputs before changing an owned view or logical sample', function () {
  $camera = new Camera(makeCameraTestScene(), 10, 8, worldSpace: array_fill(0, 60, str_repeat('.', 80)));
  $owner = new stdClass(); $camera->beginTransition($owner, new Vector2(20, 20), 1.0);
  $before = $camera->captureState(); $motion = $camera->presentationMotion;
  foreach ([-1.0, INF, NAN, 61.0] as $duration) {
    expect(fn() => $camera->beginTransition(new stdClass(), new Vector2(10, 10), $duration))->toThrow(InvalidArgumentException::class);
  }
  $invalidTarget = new Vector2(0, 10);
  $invalidTarget->x = NAN;
  expect(fn() => $camera->beginTransition(new stdClass(), $invalidTarget, 1))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $camera->updateTransition($owner, new Vector2(INF, 10), .5))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $camera->updateTransition($owner, new Vector2(5, 5), 2))->toThrow(InvalidArgumentException::class)
    ->and($camera->captureState())->toEqual($before)->and($camera->presentationMotion)->toBe($motion)
    ->and($camera->ownsTransition($owner))->toBeTrue();
});
