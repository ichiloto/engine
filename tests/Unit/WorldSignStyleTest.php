<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\WorldSignStyle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Util\Debug;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

use function Tests\Support\Rendering\writeTestPng;

function getWorldSignStyleData(array $replace = []): array
{
  return array_replace([
    'asset' => 'synthetic/board.png', 'fontRole' => 'sign.primary',
    'left' => 6, 'right' => 2, 'density' => 2, 'padding' => [3, 1, 5, 1],
    'minimumWidth' => 22, 'maximumWidth' => 64,
  ], $replace);
}

function createWorldSignMeasurement(?TextBounds $paint = null, float $advance = 20): TextMeasurement
{
  $paint ??= new TextBounds(-2.5, -1.25, 12.5, 3);
  $ink = $paint->width === 0.0 || $paint->height === 0.0 ? null
    : new TextBounds($paint->x + 1, $paint->y + 1, max(0, $paint->width - 2), max(0, $paint->height - 2));
  return new TextMeasurement('synthetic-sign-layout', new TextMetrics($advance, 5,
    new TextBounds(0, 0, $advance, 12), $ink, $paint));
}

function createWorldSignBoard(WorldSignStyle $style, int $width = 30, int $height = 11): CanvasNineSlice
{
  $root = createTestDirectory('world-sign-');
  writeTestPng($root . '/' . $style->asset, $width, $height);
  return $style->getBoard($root);
}

it('admits explicit sign intent with defaults and optional repeat and field layer values', function () {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData());
  expect($style->asset)->toBe('synthetic/board.png')->and($style->fontRole)->toBe('sign.primary')
    ->and($style->padding)->toBe([3, 1, 5, 1])->and($style->layer)->toBe(0)
    ->and($style->repeatWidth)->toBeNull();
  foreach ([-1, 0, 999] as $layer) {
    $explicit = WorldSignStyle::fromArray(getWorldSignStyleData(['layer' => $layer, 'repeatWidth' => 5]));
    expect($explicit->layer)->toBe($layer)->and($explicit->repeatWidth)->toBe(5);
  }
});

it('accepts inclusive structural limits without imposing dimensions from old artwork', function () {
  foreach ([1, 2] as $density) {
    $style = WorldSignStyle::fromArray(getWorldSignStyleData([
      'asset' => str_repeat('a', 4096), 'fontRole' => str_repeat('a', 128),
      'left' => CanvasValidation::MAX_EXTENT, 'right' => 0, 'density' => $density,
      'padding' => [0, 8192, 8191, 8192], 'minimumWidth' => 8192, 'maximumWidth' => 8192,
      'repeatWidth' => CanvasValidation::MAX_EXTENT,
    ]));
    expect($style->left)->toBe(16384)->and($style->density)->toBe($density)
      ->and($style->minimumWidth)->toBe(8192)->and($style->maximumWidth)->toBe(8192);
  }
  $smallest = WorldSignStyle::fromArray(getWorldSignStyleData([
    'left' => 0, 'right' => 0, 'padding' => [0, 0, 0, 0],
    'minimumWidth' => 1, 'maximumWidth' => 1, 'repeatWidth' => 1,
  ]));
  expect($smallest->minimumWidth)->toBe(1)->and($smallest->repeatWidth)->toBe(1);
});

it('rejects missing unknown and nonarray sign schemas', function (mixed $data) {
  expect(fn() => WorldSignStyle::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = getWorldSignStyleData();
  $cases = ['null' => [null], 'object' => [new stdClass()], 'string' => ['board'],
    'boolean' => [false], 'empty' => [[]], 'list' => [array_values($base)]];
  foreach (array_keys($base) as $field) {
    $missing = $base;
    unset($missing[$field]);
    $cases['missing-' . $field] = [$missing];
  }
  foreach (['width', 'height', 'hash', 'text', 'rendererHandle'] as $field) {
    $cases['unknown-' . $field] = [[...$base, $field => 'not-sign-intent']];
  }
  return $cases;
});

it('refuses sign schema type coercion including optional integer fields', function (array $replace) {
  expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData($replace)))
    ->toThrow(InvalidArgumentException::class);
})->with(function () {
  $cases = [];
  foreach (['left', 'right', 'density', 'minimumWidth', 'maximumWidth', 'layer', 'repeatWidth'] as $field) {
    foreach ([null, false, '2', 2.0] as $index => $value) {
      $cases[$field . '-type-' . $index] = [[$field => $value]];
    }
  }
  foreach (['asset', 'fontRole'] as $field) {
    foreach ([null, false, 1, [], new stdClass()] as $index => $value) {
      $cases[$field . '-type-' . $index] = [[$field => $value]];
    }
  }
  foreach ([null, false, 'padding', new stdClass()] as $index => $value) {
    $cases['padding-type-' . $index] = [['padding' => $value]];
  }
  return $cases;
});

it('rejects out of bound caps density widths repeat and field layer intent', function (array $replace) {
  expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData($replace)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'negative-left' => [['left' => -1]], 'excessive-left' => [['left' => 16385]],
  'negative-right' => [['right' => -1]], 'excessive-right' => [['right' => 16385]],
  'zero-density' => [['density' => 0]], 'unsupported-density' => [['density' => 3]],
  'zero-minimum' => [['minimumWidth' => 0]], 'negative-minimum' => [['minimumWidth' => -1]],
  'reversed-widths' => [['minimumWidth' => 65]], 'zero-maximum' => [['maximumWidth' => 0]],
  'excessive-maximum' => [['maximumWidth' => 8193]],
  'low-layer' => [['layer' => -2]], 'high-layer' => [['layer' => 1000]],
  'zero-repeat' => [['repeatWidth' => 0]], 'negative-repeat' => [['repeatWidth' => -1]],
  'excessive-repeat' => [['repeatWidth' => 16385]],
  'no-horizontal-space' => [['padding' => [32, 0, 32, 0]]],
]);

it('requires exactly four detached nonnegative bounded integer padding values', function () {
  foreach ([[], [0, 0, 0], [0, 0, 0, 0, 0], ['left' => 0, 'top' => 0, 'right' => 0, 'bottom' => 0],
    [1 => 0, 2 => 0, 3 => 0, 4 => 0]] as $padding) {
    expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData(['padding' => $padding])))
      ->toThrow(InvalidArgumentException::class);
  }
  foreach ([null, false, '0', 0.0, -1, 8193] as $value) {
    foreach (range(0, 3) as $index) {
      $padding = [0, 0, 0, 0];
      $padding[$index] = $value;
      expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData(['padding' => $padding])))
        ->toThrow(InvalidArgumentException::class);
    }
  }
  $left = 3;
  $padding = [&$left, 1, 5, 1];
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['padding' => &$padding]));
  $left = 60;
  $padding[1] = 60;
  expect($style->padding)->toBe([3, 1, 5, 1])
    ->and(fn() => $style->padding = [0, 0, 0, 0])->toThrow(Error::class);
});

it('uses shared safe relative asset paths and stable role identifiers', function () {
  foreach (['', '/board.png', '../board.png', 'art/../board.png', 'C:/board.png',
    'art\\board.png', 'https://example.test/board.png', "art/board\0.png", "\xFF", str_repeat('a', 4097)] as $asset) {
    expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData(['asset' => $asset])))
      ->toThrow(InvalidArgumentException::class);
  }
  foreach (['', 'Uppercase', '0sign', 'sign role', '../sign', "sign\n", str_repeat('a', 129)] as $role) {
    expect(fn() => WorldSignStyle::fromArray(getWorldSignStyleData(['fontRole' => $role])))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('validates direct typed construction through the same bounded rules', function () {
  expect(fn() => new WorldSignStyle('synthetic/board.png', 'sign', -1, 0, 1, [0, 0, 0, 0], 1, 10))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => new WorldSignStyle('synthetic/board.png', 'sign', 0, 0, 3, [0, 0, 0, 0], 1, 10))
    ->toThrow(InvalidArgumentException::class);
});

it('reads replacement board dimensions at the same path without changing authored identity', function () {
  $root = createTestDirectory('world-sign-replacement-');
  $style = WorldSignStyle::fromArray(getWorldSignStyleData());
  $path = $root . '/' . $style->asset;
  writeTestPng($path, 30, 11);
  $original = $style->getBoard($root);
  writeTestPng($path, 40, 17);
  $replacement = $style->getBoard($root);
  expect($original->source->width)->toBe(30)->and($original->source->height)->toBe(11)
    ->and($replacement->source->width)->toBe(40)->and($replacement->source->height)->toBe(17)
    ->and($replacement->asset)->toBe($original->asset)
    ->and([$replacement->left, $replacement->right, $replacement->density])->toBe([6, 2, 2]);
  unlink($path);
  expect(fn() => $style->getBoard($root))->toThrow(RuntimeException::class);
  writeTestPng($path, 24, 13);
  expect($style->getBoard($root)->source->height)->toBe(13);
});

it('reconciles smaller replacement caps while retaining a grain pixel and reporting diagnostics', function () {
  $root = createTestDirectory('world-sign-reconciled-');
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['left' => 12, 'right' => 4]));
  $directory = new ReflectionProperty(Debug::class, 'logDirectory');
  $previous = $directory->getValue();
  $directory->setValue(null, $root . '/logs');
  try {
    writeTestPng($root . '/' . $style->asset, 7, 11);
    $board = $style->getBoard($root);
    expect([$board->left, $board->right])->toBe([4, 2])
      ->and($board->source->width - $board->left - $board->right)->toBe(1)
      ->and([$style->left, $style->right])->toBe([12, 4])
      ->and(file_get_contents($root . '/logs/warning.log'))->toContain('border cuts reconciled');
  } finally {
    $directory->setValue(null, $previous);
  }
});

it('rejects current boards with no vertical text room or caps wider than the sign limit', function () {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['padding' => [0, 2, 0, 3]]));
  expect(fn() => createWorldSignBoard($style, height: 10))->toThrow(InvalidArgumentException::class);
  expect(fn() => createWorldSignBoard($style, height: 9))->toThrow(InvalidArgumentException::class);
  $wideCaps = WorldSignStyle::fromArray(getWorldSignStyleData([
    'left' => 16, 'right' => 16, 'padding' => [0, 0, 0, 0], 'minimumWidth' => 1, 'maximumWidth' => 15,
  ]));
  expect(fn() => createWorldSignBoard($wideCaps, 40, 10))->toThrow(InvalidArgumentException::class);
});

it('requires safe present current PNGs rather than accepting invalid or outside-root artwork', function () {
  $root = createTestDirectory('world-sign-invalid-art-');
  $style = WorldSignStyle::fromArray(getWorldSignStyleData());
  $path = $root . '/' . $style->asset;
  mkdir(dirname($path), 0700);
  file_put_contents($path, 'not a PNG');
  expect(fn() => $style->getBoard($root))->toThrow(RuntimeException::class);
  unlink($path);
  $outside = createTestDirectory('world-sign-outside-');
  writeTestPng($outside . '/board.png', 30, 11);
  symlink($outside . '/board.png', $path);
  expect(fn() => $style->getBoard($root))->toThrow(RuntimeException::class);
});

it('fits effect-expanded paint against both axes without using advance or logical line bounds', function () {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['maximumWidth' => 40]));
  $board = createWorldSignBoard($style);
  foreach ([[32, 3.5, 1.0], [64, 3.5, 0.5], [32, 7, 0.5], [64, 14, 0.25], [0, 0, 1.0]] as [$w, $h, $ratio]) {
    $text = createWorldSignMeasurement(new TextBounds(-3, -2, $w, $h), advance: 200);
    expect($style->getFitRatio($board, $text))->toBe($ratio);
    if ($ratio < 1) {
      expect(fn() => $style->composeCanvas($board, $text, new PresentationSpritePivot()))
        ->toThrow(InvalidArgumentException::class, 'does not fit');
    } else {
      expect($style->composeCanvas($board, $text, new PresentationSpritePivot())[0]->labels)->toHaveCount(1);
    }
  }
  $narrowPaint = createWorldSignMeasurement(new TextBounds(-1, -1, 30, 3));
  $wideEffects = createWorldSignMeasurement(new TextBounds(-3, -1, 33, 3));
  expect($style->getFitRatio($board, $narrowPaint))->toBe(1.0)
    ->and($style->getFitRatio($board, $wideEffects))->toBeLessThan(1.0);
});

it('preserves fixed-density caps and repeated grain including a clipped full-size tail', function (int $density) {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['density' => $density, 'repeatWidth' => 5]));
  $board = createWorldSignBoard($style);
  $inputPivot = new PresentationSpritePivot(0.25, 0.75);
  [$canvas, $pivot] = $style->composeCanvas($board, createWorldSignMeasurement(), $inputPivot);
  $left = $canvas->images[0];
  $right = $canvas->images[array_key_last($canvas->images)];
  $boardWidth = $right->destination->x + $right->destination->width;
  $boardHeight = 11.0 / $density;
  $repeats = array_slice($canvas->images, 1, -1);
  expect($boardWidth)->toBe(22.0)->and($canvas->height)->toBe((int)ceil($boardHeight))
    ->and($left->sourceRect->width)->toBe(6)->and($left->destination->width)->toBe(6.0 / $density)
    ->and($right->sourceRect->width)->toBe(2)->and($right->destination->width)->toBe(2.0 / $density)
    ->and($right->sourceRect->x)->toBe(28)
    ->and(count(array_unique(array_column($canvas->images, 'id'))))->toBe(count($canvas->images));
  foreach ($canvas->images as $image) {
    expect($image->asset)->toBe($style->asset)->and($image->layer)->toBe(0)
      ->and($image->destination->height)->toBe($boardHeight)
      ->and($image->sourceRect->width / $image->destination->width)->toBe((float)$density)
      ->and($image->sourceRect->height / $image->destination->height)->toBe((float)$density);
  }
  foreach ($repeats as $index => $image) {
    expect($image->sourceRect->x)->toBe(6)->and($image->sourceRect->width)->toBe(5)
      ->and($image->destination->x)->toBe(6.0 / $density + $index * 5.0 / $density)
      ->and($image->destination->width)->toBe(5.0 / $density);
  }
  $tail = $repeats[array_key_last($repeats)];
  expect($tail->clipRect)->not->toBeNull()
    ->and($tail->clipRect->x + $tail->clipRect->width)->toBe($right->destination->x)
    ->and($tail->clipRect->width)->toBeLessThan($tail->destination->width)
    ->and($tail->clipRect->height)->toBe($boardHeight);
  $this->assertEqualsWithDelta($inputPivot->x * $boardWidth, $pivot->x * $canvas->width, 0.000001);
  $this->assertEqualsWithDelta($inputPivot->y * $boardHeight, $pivot->y * $canvas->height, 0.000001);
  expect($canvas->labels[0]->layer)->toBe(1);
})->with(['density-one' => [1], 'density-two-fractional-height' => [2]]);

it('centers expanded paint inside asymmetric padding regardless of signed bearings and effects', function (float $x, float $y) {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData(['repeatWidth' => 5]));
  $board = createWorldSignBoard($style);
  $paint = new TextBounds($x, $y, 12.5, 3);
  $text = createWorldSignMeasurement($paint);
  [$canvas] = $style->composeCanvas($board, $text, new PresentationSpritePivot());
  $label = $canvas->labels[0];
  expect($label->measurement)->toBe($text)->and($label->paintBounds->width)->toBe(12.5)
    ->and($label->paintBounds->height)->toBe(3.0)
    ->and($label->paintBounds->x)->toBe(3.75)->and($label->paintBounds->y)->toBe(1.25)
    ->and($label->x)->toBe(3.75 - $x)->and($label->y)->toBe(1.25 - $y);
  $this->assertEqualsWithDelta($label->paintBounds->x - 3,
    22 - 5 - $label->paintBounds->x - $label->paintBounds->width, 0.000001);
  $this->assertEqualsWithDelta($label->paintBounds->y - 1,
    5.5 - 1 - $label->paintBounds->y - $label->paintBounds->height, 0.000001);
})->with(['negative-bearings' => [-2.5, -1.25], 'positive-bearings' => [4.5, 3.25],
  'opposed-effect-offsets' => [-6.25, 4.5]]);

it('grows to rounded paint width or cap minimum and clamps samples to the current middle strip', function () {
  foreach ([null, 100] as $repeatWidth) {
    $style = WorldSignStyle::fromArray(getWorldSignStyleData([
      'left' => 4, 'right' => 3, 'density' => 1, 'minimumWidth' => 1,
      ...($repeatWidth === null ? [] : ['repeatWidth' => $repeatWidth]),
    ]));
    $board = createWorldSignBoard($style, 14, 10);
    [$canvas] = $style->composeCanvas($board, createWorldSignMeasurement(new TextBounds(-1, -1, 20.25, 3)),
      new PresentationSpritePivot());
    $right = $canvas->images[array_key_last($canvas->images)];
    expect($right->destination->x + $right->destination->width)->toBe(29.0);
    foreach (array_slice($canvas->images, 1, -1) as $repeat) {
      expect($repeat->sourceRect->width)->toBe(7)->and($repeat->destination->width)->toBe(7.0);
    }
  }
  $style = WorldSignStyle::fromArray(getWorldSignStyleData([
    'left' => 8, 'right' => 6, 'density' => 1, 'minimumWidth' => 1, 'padding' => [0, 0, 0, 0],
  ]));
  [$canvas] = $style->composeCanvas(createWorldSignBoard($style),
    createWorldSignMeasurement(new TextBounds(0, 0, 0, 0)), new PresentationSpritePivot());
  expect($canvas->images)->toHaveCount(2)
    ->and($canvas->images[1]->destination->x + $canvas->images[1]->destination->width)->toBe(14.0);
});

it('enforces the shared image expansion budget before constructing excessive repeated grain', function () {
  $style = WorldSignStyle::fromArray(getWorldSignStyleData([
    'left' => 0, 'right' => 0, 'density' => 1, 'padding' => [0, 0, 0, 0],
    'minimumWidth' => 1024, 'maximumWidth' => 8192, 'repeatWidth' => 1,
  ]));
  $board = createWorldSignBoard($style, 1, 3);
  $empty = createWorldSignMeasurement(new TextBounds(0, 0, 0, 0));
  expect($style->composeCanvas($board, $empty, new PresentationSpritePivot())[0]->images)->toHaveCount(1024);
  $tooWide = WorldSignStyle::fromArray(getWorldSignStyleData([
    'left' => 0, 'right' => 0, 'density' => 1, 'padding' => [0, 0, 0, 0],
    'minimumWidth' => 1025, 'maximumWidth' => 8192, 'repeatWidth' => 1,
  ]));
  expect(fn() => $tooWide->composeCanvas($board, $empty, new PresentationSpritePivot()))
    ->toThrow(InvalidArgumentException::class, 'image budget');
});
