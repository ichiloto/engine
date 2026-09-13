<?php

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\SpriteSheet;
use Ichiloto\Engine\Rendering\Sprites\SpriteWalkAnimation;
use function Tests\Support\Rendering\spriteSheetData;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('uses each directions actual populated frames without cropping empty cells', function ($direction, $frames, $rows) {
  $definition = DirectionalGraphicalSpriteSet::fromArray(spriteSheetData())->$direction;
  expect($definition->sheet->frames)->toBe($frames)->and($definition->sheet->rows)->toBe($rows)
    ->and($definition->width)->toBe(56)->and($definition->height)->toBe(56);
  for ($frame = 0; $frame < $frames; $frame++) {
    $crop = $definition->atFrame($frame)->sourceRect;
    expect($crop->toArray())->toBe(['x' => ($frame % 5) * 256, 'y' => intdiv($frame, 5) * 256,
      'width' => 256, 'height' => 256]);
  }
  expect(fn() => $definition->atFrame($frames))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $definition->atFrame(-1))->toThrow(InvalidArgumentException::class);
})->with([['south', 22, 5], ['east', 21, 5], ['north', 17, 4], ['west', 21, 5]]);

it('rejects invalid source rectangle geometry before transport', function ($values) {
  expect(fn() => new SpriteSourceRect(...$values))->toThrow(InvalidArgumentException::class);
})->with([
  [[-1, 0, 256, 256]], [[0, -1, 256, 256]], [[0, 0, 0, 256]], [[0, 0, 256, 0]],
  [[0, 0, -1, 256]], [[4294967295, 0, 1, 1]], [[0, 4294967295, 1, 1]],
  [[0, 0, PHP_INT_MAX, 1]],
]);

it('rejects invalid sheet metadata without reading image files', function ($key, $value) {
  $data = spriteSheetData();
  $data[$key] = $value;
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  ['mode', 'atlas'], ['frameWidth', 0], ['frameHeight', -1], ['frameWidth', '256'],
  ['width', 0], ['height', 4097], ['idleFrame', 17], ['frameDurationMs', 0],
  ['stepDurationMs', 60001], ['anchor', 'feet'], ['directions', []], ['typo', 1],
]);

it('rejects invalid per-direction grid data', function ($key, $value) {
  $data = spriteSheetData();
  $data['directions']['north'][$key] = $value;
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  ['asset', '../north.png'], ['columns', 0], ['rows', 0], ['frames', 0], ['frames', 21],
  ['frames', 17.0], ['columns', PHP_INT_MAX], ['rows', PHP_INT_MAX], ['unknown', true],
]);

it('defaults a directly constructed sheet sprite to its authored resting crop', function () {
  $sheet = new SpriteSheet(256, 256, 5, 5, 22, idleFrame: 6);
  $definition = new GraphicalSpriteDefinition('south.png', 56, 56, sheet: $sheet);
  expect($definition->sourceRect->toArray())->toBe(['x' => 256, 'y' => 256, 'width' => 256, 'height' => 256]);
});

it('advances all populated frames using elapsed movement time and wraps without selecting blanks', function ($direction) {
  $definition = DirectionalGraphicalSpriteSet::fromArray(spriteSheetData())->$direction;
  $animation = new SpriteWalkAnimation();
  for ($frame = 0; $frame < $definition->sheet->frames * 3; $frame++) {
    $animation->step($definition);
    expect($animation->present($definition)->sourceRect)->toEqual(
      $definition->sheet->sourceRect($frame % $definition->sheet->frames));
    $animation->advance(0.08);
  }
})->with(['north', 'east', 'south', 'west']);

it('settles to the resting frame and restarts on heading changes without accumulating idle time', function () {
  $data = spriteSheetData();
  $data['idleFrame'] = 6;
  $set = DirectionalGraphicalSpriteSet::fromArray($data);
  $animation = new SpriteWalkAnimation();
  $animation->advance(10000);
  expect($animation->present($set->south))->toBe($set->south);
  $animation->step($set->south);
  $animation->advance(0.08);
  expect($animation->present($set->south)->sourceRect)->toEqual($set->south->sheet->sourceRect(1));
  $animation->step($set->east);
  expect($animation->present($set->east)->sourceRect)->toEqual($set->east->sheet->sourceRect(0));
  $animation->advance(0.16);
  expect($animation->present($set->east))->toBe($set->east);
  $animation->step($set->east);
  expect($animation->present($set->east)->sourceRect)->toEqual($set->east->sheet->sourceRect(0));
  $animation->stop();
  expect($animation->present($set->east))->toBe($set->east);
});

it('produces identical animation state for equal elapsed time regardless of update subdivision', function () {
  $definition = DirectionalGraphicalSpriteSet::fromArray(spriteSheetData())->north;
  $whole = new SpriteWalkAnimation();
  $parts = new SpriteWalkAnimation();
  $whole->step($definition);
  $parts->step($definition);
  $whole->advance(0.12);
  for ($i = 0; $i < 12; $i++) { $parts->advance(0.01); }
  expect($parts->present($definition))->toEqual($whole->present($definition));
  $before = serialize($parts);
  $parts->present($definition);
  expect(serialize($parts))->toBe($before);
});

it('rejects invalid animation deltas', function ($delta) {
  expect(fn() => new SpriteWalkAnimation()->advance($delta))->toThrow(InvalidArgumentException::class);
})->with([-0.1, INF, NAN]);
