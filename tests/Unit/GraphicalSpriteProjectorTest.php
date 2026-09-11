<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;

beforeEach(function () {
  $this->projector = new GraphicalSpriteProjector();
  $this->position = new Vector2(7, 4);
  $this->provider = $this->createStub(GraphicalSpriteProviderInterface::class);
  $this->provider->method('getGraphicalSpriteId')->willReturn('future-object');
  $this->provider->method('getGraphicalSpriteWorldPosition')->willReturn($this->position);
  $this->provider->method('getGraphicalSpriteDefinition')->willReturn(new GraphicalSpriteDefinition('Other.png', 64, 72, layer: -10));
});

it('stops at an absent definition without asking for identity position or Camera projection', function () {
  $provider = $this->createMock(GraphicalSpriteProviderInterface::class);
  $provider->expects($this->once())->method('getGraphicalSpriteDefinition')->willReturn(null);
  $provider->expects($this->never())->method('getGraphicalSpriteId');
  $provider->expects($this->never())->method('getGraphicalSpriteWorldPosition');
  $camera = $this->createMock(Camera::class);
  $camera->expects($this->never())->method('getScreenSpacePosition');
  expect($this->projector->project($provider, $camera))->toBeNull();
});

it('uses the supplied Camera transform for any provider rather than reproducing its formula', function () {
  $camera = $this->createMock(Camera::class);
  $camera->expects($this->once())->method('getScreenSpacePosition')->with($this->identicalTo($this->position))
    ->willReturn(new Vector2(17, 9));
  expect($this->projector->project($this->provider, $camera)->toArray())->toBe([
    'id' => 'future-object', 'asset' => 'Other.png', 'x' => 17, 'y' => 9,
    'width' => 64, 'height' => 72, 'anchor' => 'bottom_center', 'layer' => -10,
  ]);
});

it('inherits real Camera centering and viewport resizing', function () {
  $camera = new Camera(makeCameraTestScene(), 20, 10, worldSpace: array_fill(0, 4, '.....'));
  $sprite = $this->projector->project($this->provider, $camera);
  expect([$sprite->x, $sprite->y])->toBe([14, 7]);
  $camera->resizeViewport(24, 12);
  $resized = $this->projector->project($this->provider, $camera);
  expect([$resized->x, $resized->y])->toBe([16, 8])
    ->and([$sprite->x, $sprite->y])->toBe([14, 7]);
});

it('inherits scrolling without mutating provider or Camera state or filtering off-grid cells', function () {
  $camera = new Camera(makeCameraTestScene(), 20, 10, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  $camera->moveTo(3, 2);
  $state = $camera->captureState();
  $sprite = $this->projector->project($this->provider, $camera);
  expect([$sprite->x, $sprite->y])->toBe([4, 2])
    ->and($camera->captureState())->toEqual($state)
    ->and([$this->position->x, $this->position->y])->toBe([7.0, 4.0]);
  $camera->moveTo(10, 8);
  $offGrid = $this->projector->project($this->provider, $camera);
  expect([$offGrid->x, $offGrid->y])->toBe([-3, -4]);
});

it('uses the same finite fractional-to-cell truncation as terminal Camera rendering', function () {
  $this->position->x = 3.75;
  $this->position->y = -2.75;
  $sprite = $this->projector->project($this->provider, new Camera(makeCameraTestScene(), 20, 10));
  expect([$sprite->x, $sprite->y])->toBe([3, -2]);
});

it('rejects invalid projected ranges before a float can wrap into a protocol cell', function ($axis, $value) {
  $this->position->$axis = $value;
  $camera = $this->createStub(Camera::class);
  $camera->method('getScreenSpacePosition')->willReturn($this->position);
  expect(fn() => $this->projector->project($this->provider, $camera))
    ->toThrow(InvalidArgumentException::class, 'signed 32-bit');
})->with([
  ['x', NAN], ['y', NAN], ['x', INF], ['y', -INF],
  ['x', 2147483648.0], ['y', -2147483649.0], ['x', 1e30], ['y', -1e30],
]);
