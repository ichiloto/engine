<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheetAssetGuard;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;

use function Tests\Support\Rendering\writeCharacterSheetPng;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('draws every character exactly one field cell, whatever the frame size', function (int $frame) {
  $sheet = new CharacterSheet('Graphics/Characters/People.png');
  $definition = $sheet->getFrame(MovementHeading::SOUTH, 1, ['width' => $frame, 'height' => $frame]);

  expect([$definition->width, $definition->height])->toBe([FieldViewport::CELL_SIZE, FieldViewport::CELL_SIZE])
    ->and($definition->anchor)->toBe(PresentationSpriteAnchor::BOTTOM_CENTER);
})->with([[48], [32], [96]]);

it('selects RPG Maker frames by character index, direction row and walking pattern', function (
  int $index, MovementHeading $heading, int $pattern, array $expected,
) {
  $sheet = new CharacterSheet('Graphics/Characters/People.png', $index);
  $rect = $sheet->getFrame($heading, $pattern, ['width' => 48, 'height' => 48])->sourceRect;

  expect([$rect->x, $rect->y, $rect->width, $rect->height])->toBe($expected);
})->with([
  'first character standing, facing down' => [0, MovementHeading::SOUTH, 1, [48, 0, 48, 48]],
  'facing nowhere shows down' => [0, MovementHeading::NONE, 1, [48, 0, 48, 48]],
  'left is the second row' => [0, MovementHeading::WEST, 0, [0, 48, 48, 48]],
  'right is the third row' => [0, MovementHeading::EAST, 2, [96, 96, 48, 48]],
  'up is the fourth row' => [0, MovementHeading::NORTH, 1, [48, 144, 48, 48]],
  'fourth character is the last top block' => [3, MovementHeading::SOUTH, 1, [480, 0, 48, 48]],
  'fifth character starts the bottom row of blocks' => [4, MovementHeading::SOUTH, 0, [0, 192, 48, 48]],
  'eighth character, facing up' => [7, MovementHeading::NORTH, 2, [528, 336, 48, 48]],
]);

it('treats a sheet named with $ as a single character', function () {
  $sheet = new CharacterSheet('Graphics/Characters/$Hero.png');
  $rect = $sheet->getFrame(MovementHeading::EAST, 2, ['width' => 48, 'height' => 48])->sourceRect;

  expect($sheet->isSingleCharacter)->toBeTrue()
    ->and([$rect->x, $rect->y])->toBe([96, 96])
    ->and($sheet->getFrameSize(144, 192))->toBe(['width' => 48, 'height' => 48])
    ->and(fn() => new CharacterSheet('Graphics/Characters/$Hero.png', 1))->toThrow(InvalidArgumentException::class);
});

it('derives frame size from the image and refuses sheets that do not divide into the layout', function () {
  $sheet = new CharacterSheet('Graphics/Characters/People.png');

  expect($sheet->getFrameSize(576, 384))->toBe(['width' => 48, 'height' => 48])
    ->and($sheet->getFrameSize(384, 256))->toBe(['width' => 32, 'height' => 32])
    ->and(fn() => $sheet->getFrameSize(577, 384))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $sheet->getFrameSize(6, 4))->toThrow(InvalidArgumentException::class);
});

it('accepts only sheet, index and layer, never authored sizes, anchors or frames', function (array $data) {
  expect(fn() => CharacterSheet::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  'width' => [['sheet' => 'People.png', 'width' => 48]],
  'anchor' => [['sheet' => 'People.png', 'anchor' => 'bottom_center']],
  'frames' => [['sheet' => 'People.png', 'frameWidth' => 48]],
  'missing sheet' => [['index' => 0]],
  'index out of range' => [['sheet' => 'People.png', 'index' => 8]],
  'unsafe path' => [['sheet' => '../People.png']],
]);

it('keeps the terminal glyph when a sheet is missing or the wrong shape', function () {
  $root = sys_get_temp_dir() . '/ichiloto-character-sheet-' . bin2hex(random_bytes(4));
  mkdir($root);
  try {
    $guard = new CharacterSheetAssetGuard($root, 'Test');
    writeCharacterSheetPng("$root/People.png", 48, 48);
    expect($guard->getFrameSize(new CharacterSheet('People.png')))->toBe(['width' => 48, 'height' => 48])
      ->and($guard->getFrameSize(new CharacterSheet('Missing.png')))->toBeNull();

    writeTestPng("$root/Wrong.png", 50, 50);
    expect($guard->getFrameSize(new CharacterSheet('Wrong.png')))->toBeNull();
  } finally {
    array_map(unlink(...), glob("$root/*") ?: []);
    rmdir($root);
  }
});

it('walks RPG Maker strides around the standing frame and stands again when steps stop', function () {
  $walk = new CharacterWalkAnimation();
  expect($walk->getPattern())->toBe(1);

  $patterns = [];
  foreach (range(1, 5) as $step) {
    $walk->step();
    $patterns[] = $walk->getPattern();
  }
  expect($patterns)->toBe([2, 1, 0, 1, 2]);

  $walk->advance(CharacterWalkAnimation::STRIDE_SECONDS / 2);
  expect($walk->getPattern())->toBe(2);
  $walk->advance(CharacterWalkAnimation::STRIDE_SECONDS);
  expect($walk->getPattern())->toBe(1);

  $walk->step();
  $walk->stop();
  expect($walk->getPattern())->toBe(1)
    ->and(fn() => $walk->advance(-1))->toThrow(InvalidArgumentException::class);
});
